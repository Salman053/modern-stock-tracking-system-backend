<?php 
require_once "models/stock.model.php";
require_once "models/supplier_due.php";
require_once "models/branch_due.model.php";

class StockController
{
    private $stockModel;
    private $supplierDuesModel;
    private $branchDuesModel;

    public function __construct()
    {
        $this->stockModel = new StockModel();
        $this->supplierDuesModel = new SupplierDueModel();
        $this->branchDuesModel = new BranchDueModel();
    }

    public function handleRequest($segments)
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $stockId = $segments[4] ?? null;

        switch ($method) {
            case 'GET':
                if ($stockId === null) {
                    $this->handleGetStockData();
                } else {
                    $this->handleGetStockMovementById($stockId);
                }
                break;
            case 'POST':
                $this->handleAddStockMovement();
                break;
            case 'PUT':
            case 'PATCH':
                if ($stockId) {
                    $this->handleUpdateStockMovement($stockId);
                } else {
                    sendResponse(400, errorResponse("Stock movement ID required for update"));
                }
                break;
            case 'DELETE':
                if ($stockId) {
                    $this->handleCancelStockMovement($stockId);
                } else {
                    sendResponse(400, errorResponse("Stock movement ID required for cancellation"));
                }
                break;
            default:
                sendResponse(405, errorResponse("Method not allowed"));
        }
    }

    private function handleAddStockMovement()
    {
        $data = $this->getJsonInput();
        if (!$data) return;

        $user = require_authenticated_user();
        if (!$this->isAdmin($user)) {
            sendResponse(403, errorResponse("Forbidden: Only admins can add stock movements"));
            return;
        }

        // Set user context
        $data['user_id'] = $user['data']['id'];
        if ($this->isBranchUser($user)) {
            $data['branch_id'] = $user['data']['branch_id'];
        }

        // Validate transfer data
        $validationError = $this->validateTransferData($data);
        if ($validationError) {
            sendResponse(400, $validationError);
            return;
        }

        // Set default auto_update_product
        if (!isset($data['auto_update_product'])) {
            $data['auto_update_product'] = true;
        }

        // Auto-calculate total_amount if not provided
        if (!isset($data['total_amount']) && isset($data['quantity']) && isset($data['unit_price_per_meter'])) {
            $data['total_amount'] = $data['quantity'] * $data['unit_price_per_meter'];
        }

        // Calculate remaining amount for dues
        if (!isset($data['remaining_amount'])) {
            $data['remaining_amount'] = $data['total_amount'] - ($data['paid_amount'] ?? 0);
        }

        try {
            $result = $this->stockModel->addStockMovement($data);
            
            if ($result["success"]) {
                // Handle automatic dues creation if auto_update is enabled
                if ($data['auto_update_product']) {
                    $this->handleAutomaticDuesCreation($result["data"]["id"], $data);
                }
                
                sendResponse(201, $result);
            } else {
                sendResponse(400, $result);
            }
        } catch (Exception $e) {
            sendResponse(500, errorResponse("Database error: " . $e->getMessage()));
        }
    }

    private function handleUpdateStockMovement($movementId)
    {
        $data = $this->getJsonInput();
        if (!$data) return;

        $user = require_authenticated_user();
        if (!$this->isAdmin($user)) {
            sendResponse(403, errorResponse("Forbidden: Only admins can update stock movements"));
            return;
        }

        // Validate reference_branch_id for transfers
        $validationError = $this->validateTransferData($data);
        if ($validationError) {
            sendResponse(400, $validationError);
            return;
        }

        // Set user context for branch restrictions
        if ($this->isBranchUser($user)) {
            $data['branch_id'] = $user['data']['branch_id'];
        }

        // Recalculate amounts if quantity or price changed
        if (isset($data['quantity']) || isset($data['unit_price_per_meter'])) {
            if (!isset($data['total_amount'])) {
                // Get existing movement to calculate new total
                $existing = $this->stockModel->getStockMovementById($movementId);
                if ($existing['success']) {
                    $oldData = $existing['data'];
                    $quantity = $data['quantity'] ?? $oldData['quantity'];
                    $unitPrice = $data['unit_price_per_meter'] ?? $oldData['unit_price_per_meter'];
                    $data['total_amount'] = $quantity * $unitPrice;
                    
                    // Recalculate remaining amount
                    $paidAmount = $data['paid_amount'] ?? $oldData['paid_amount'];
                    $data['remaining_amount'] = $data['total_amount'] - $paidAmount;
                }
            }
        }

        try {
            $result = $this->stockModel->updateStockMovement($movementId, $data);
            
            if ($result["success"]) {
                // Update related dues if auto_update was enabled
                $autoUpdate = $data['auto_update_product'] ?? true;
                if ($autoUpdate) {
                    $this->handleDuesUpdate($movementId, $data);
                }
                
                sendResponse($result["success"] ? 200 : 400, $result);
            } else {
                sendResponse(400, $result);
            }
        } catch (Exception $e) {
            sendResponse(500, errorResponse("Database error: " . $e->getMessage()));
        }
    }

    private function handleCancelStockMovement($movementId)
    {
        $user = require_authenticated_user();
        if (!$this->isAdmin($user)) {
            sendResponse(403, errorResponse("Forbidden: Only admins can cancel stock movements"));
            return;
        }

        try {
            $result = $this->stockModel->cancelStockMovement($movementId);
            
            if ($result["success"]) {
                // Cancel related dues
                $this->handleDuesCancellation($movementId);
                sendResponse(200, $result);
            } else {
                sendResponse(400, $result);
            }
        } catch (Exception $e) {
            sendResponse(500, errorResponse("Database error: " . $e->getMessage()));
        }
    }

    // Dues Management Methods
    private function handleAutomaticDuesCreation($stockMovementId, $movementData)
    {
        // Only create dues for arrival movements with suppliers
        if ($movementData['movement_type'] === 'arrival' && !empty($movementData['supplier_id'])) {
            $this->createSupplierDue($stockMovementId, $movementData);
        }
        
        // Create branch dues for transfer movements
        if (in_array($movementData['movement_type'], ['transfer_in', 'transfer_out']) && !empty($movementData['reference_branch_id'])) {
            $this->createBranchDue($stockMovementId, $movementData);
        }
    }

    private function createSupplierDue($stockMovementId, $movementData)
    {
        $dueData = [
            'supplier_id' => $movementData['supplier_id'],
            'branch_id' => $movementData['branch_id'],
            'stock_movement_id' => $stockMovementId,
            'due_date' => date('Y-m-d', strtotime('+30 days')), // 30 days from now
            'total_amount' => $movementData['total_amount'],
            'paid_amount' => $movementData['paid_amount'] ?? 0,
            'remaining_amount' => $movementData['remaining_amount'] ?? ($movementData['total_amount'] - ($movementData['paid_amount'] ?? 0)),
            'status' => ($movementData['remaining_amount'] ?? $movementData['total_amount']) > 0 ? 'pending' : 'paid',
            'due_type' => 'stock_purchase',
            'description' => "Stock arrival: " . ($movementData['notes'] ?? 'Product purchase')
        ];

        return $this->supplierDuesModel->createSupplierDue($dueData);
    }

    private function createBranchDue($stockMovementId, $movementData)
    {
        $dueData = [
            'branch_id' => $movementData['reference_branch_id'],
            'supplier_id' => $movementData['supplier_id'] ?? null,
            'stock_movement_id' => $stockMovementId,
            'due_date' => date('Y-m-d', strtotime('+15 days')), // 15 days for inter-branch
            'total_amount' => $movementData['total_amount'],
            'paid_amount' => $movementData['paid_amount'] ?? 0,
            'remaining_amount' => $movementData['remaining_amount'] ?? ($movementData['total_amount'] - ($movementData['paid_amount'] ?? 0)),
            'status' => ($movementData['remaining_amount'] ?? $movementData['total_amount']) > 0 ? 'pending' : 'paid',
            'due_type' => $movementData['movement_type'] === 'transfer_in' ? 'receivable' : 'payable',
            'description' => "Stock transfer: " . ($movementData['notes'] ?? 'Branch transfer')
        ];

        return $this->branchDuesModel->createBranchDue($dueData);
    }

    private function handleDuesUpdate($stockMovementId, $movementData)
    {
        // Update supplier dues
        $supplierDue = $this->supplierDuesModel->getDueByStockMovement($stockMovementId);
        if ($supplierDue['success'] && !empty($supplierDue['data'])) {
            $this->updateSupplierDue($supplierDue['data']['id'], $movementData);
        }

        // Update branch dues
        $branchDue = $this->branchDuesModel->getBranchDueById($stockMovementId);
        if ($branchDue['success'] && !empty($branchDue['data'])) {
            $this->updateBranchDue($branchDue['data']['id'], $movementData);
        }
    }

    private function updateSupplierDue($dueId, $movementData)
    {
        $updateData = [
            'total_amount' => $movementData['total_amount'],
            'paid_amount' => $movementData['paid_amount'] ?? 0,
            'remaining_amount' => $movementData['remaining_amount'] ?? ($movementData['total_amount'] - ($movementData['paid_amount'] ?? 0)),
            'status' => ($movementData['remaining_amount'] ?? $movementData['total_amount']) > 0 ? 'pending' : 'paid'
        ];

        return $this->supplierDuesModel->updateSupplierDue($dueId, $updateData);
    }

    private function updateBranchDue($dueId, $movementData)
    {
        $updateData = [
            'total_amount' => $movementData['total_amount'],
            'paid_amount' => $movementData['paid_amount'] ?? 0,
            'remaining_amount' => $movementData['remaining_amount'] ?? ($movementData['total_amount'] - ($movementData['paid_amount'] ?? 0)),
            'status' => ($movementData['remaining_amount'] ?? $movementData['total_amount']) > 0 ? 'pending' : 'paid'
        ];

        return $this->branchDuesModel->updatePayment($dueId, $updateData);
    }

    private function handleDuesCancellation($stockMovementId)
    {
        // Cancel supplier dues
        $supplierDue = $this->supplierDuesModel->getDueByStockMovement($stockMovementId);
        if ($supplierDue['success'] && !empty($supplierDue['data'])) {
            $this->supplierDuesModel->cancelDue($supplierDue['data']['id']);
        }

        // Cancel branch dues
        $branchDue = $this->branchDuesModel->getBranchDueById($stockMovementId);
        if ($branchDue['success'] && !empty($branchDue['data'])) {
            $this->branchDuesModel->deleteBranchDue($branchDue['data']['id']);
        }
    }
    private function handleGetStockData()
    {
        $user = require_authenticated_user();

        // Get query parameters
        $branch_id = $_GET['branch_id'] ?? null;
        $product_id = $_GET['product_id'] ?? null;
        $movement_type = $_GET['movement_type'] ?? null;
        $start_date = $_GET['start_date'] ?? null;
        $end_date = $_GET['end_date'] ?? null;
        $data_type = $_GET['data_type'] ?? 'movements';
        $reference_branch_id = $_GET['reference_branch_id'] ?? null;

        // Role-based access control
        if ($this->isBranchUser($user)) {
            $branch_id = $user['data']['branch_id'];
        }

        try {
            switch ($data_type) {
                case 'levels':
                    $result = $this->stockModel->getCurrentStockLevels($branch_id);
                    break;
                case 'summary':
                    $result = $this->stockModel->getStockMovementSummary($product_id, $branch_id, $start_date, $end_date);
                    break;
                case 'transfers':
                    // Special endpoint for transfer movements
                    $result = $this->stockModel->getStockMovements($branch_id, $product_id, ['transfer_in', 'transfer_out'], $start_date, $end_date);
                    break;
                default:
                    $result = $this->stockModel->getStockMovements($branch_id, $product_id, $movement_type, $start_date, $end_date);
            }

            // Filter by reference branch if specified
            if ($reference_branch_id && isset($result['data'])) {
                $result['data'] = array_filter($result['data'], function($movement) use ($reference_branch_id) {
                    return $movement['reference_branch_id'] == $reference_branch_id;
                });
                $result['data'] = array_values($result['data']); // Reindex array
                $result['meta']['count'] = count($result['data']);
            }

            sendResponse($result["success"] ? 200 : 400, $result);
        } catch (Exception $e) {
            sendResponse(500, errorResponse("Database error: " . $e->getMessage()));
        }
    }

    private function handleGetStockMovementById($movementId)
    {
        $user = require_authenticated_user();

        try {
            $result = $this->stockModel->getStockMovementById($movementId);

            if ($result["success"]) {
                $movementBranch = $result['data']['branch_id'] ?? null;
                $movementReferenceBranch = $result['data']['reference_branch_id'] ?? null;
                
                if ($this->isBranchUser($user)) {
                    $userBranch = $user['data']['branch_id'];
                    if ($movementBranch !== $userBranch && $movementReferenceBranch !== $userBranch) {
                        sendResponse(403, errorResponse("Access denied to this stock movement"));
                        return;
                    }
                }
                sendResponse(200, $result);
            } else {
                sendResponse(404, errorResponse("Stock movement not found"));
            }
        } catch (Exception $e) {
            sendResponse(500, errorResponse("Database error: " . $e->getMessage()));
        }
    }

    // Validation helper methods
    private function validateTransferData($data)
    {
        $movement_type = $data['movement_type'] ?? null;
        
        // Check if this is a transfer movement
        if (in_array($movement_type, ['transfer_in', 'transfer_out'])) {
            
            // Validate reference_branch_id exists for transfers
            if (empty($data['reference_branch_id'])) {
                return errorResponse(
                    "Reference branch required", 
                    ["reference_branch_id" => "Reference branch is required for transfer movements"], 
                    "MISSING_REFERENCE_BRANCH"
                );
            }

            // Validate reference_branch_id is different from branch_id
            if (isset($data['branch_id']) && $data['reference_branch_id'] == $data['branch_id']) {
                return errorResponse(
                    "Invalid reference branch", 
                    ["reference_branch_id" => "Reference branch cannot be the same as source branch"], 
                    "INVALID_REFERENCE_BRANCH"
                );
            }

            // For branch users, validate they're not transferring to unauthorized branches
            $user = require_authenticated_user();
            if ($this->isBranchUser($user)) {
                $userBranch = $user['data']['branch_id'];
                
                if ($movement_type === 'transfer_out' && $data['reference_branch_id'] == $userBranch) {
                    return errorResponse(
                        "Invalid transfer", 
                        ["reference_branch_id" => "Cannot transfer to your own branch"], 
                        "INVALID_TRANSFER_BRANCH"
                    );
                }
                
                if ($movement_type === 'transfer_in' && $data['branch_id'] != $userBranch) {
                    return errorResponse(
                        "Invalid transfer", 
                        ["branch_id" => "Cannot receive transfers for other branches"], 
                        "INVALID_TRANSFER_BRANCH"
                    );
                }
            }
        }

        return null;
    }

    // Helper methods
    private function getJsonInput()
    {
        $data = json_decode(file_get_contents("php://input"), true);
        if (empty($data)) {
            sendResponse(400, errorResponse("Invalid or empty JSON body"));
            return null;
        }
        return $data;
    }

    private function isAdmin($user)
    {
        return in_array($user['data']['role'], ["super-admin", "branch-admin"]);
    }

    private function isBranchUser($user)
    {
        return in_array($user['data']['role'], ['branch-admin', 'staff']);
    }
}