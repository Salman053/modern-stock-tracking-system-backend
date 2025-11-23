<?php
require_once "models/stock.model.php";

class StockController
{
    private $stockModel;

    public function __construct()
    {
        $this->stockModel = new StockModel();
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

        try {
            $result = $this->stockModel->updateStockMovement($movementId, $data);
            sendResponse($result["success"] ? 200 : 400, $result);
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
            sendResponse($result["success"] ? 200 : 400, $result);
        } catch (Exception $e) {
            sendResponse(500, errorResponse("Database error: " . $e->getMessage()));
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
                // Check branch access
                $movementBranch = $result['data']['branch_id'] ?? null;
                $movementReferenceBranch = $result['data']['reference_branch_id'] ?? null;
                
                if ($this->isBranchUser($user)) {
                    $userBranch = $user['data']['branch_id'];
                    // Allow access if user's branch matches movement branch OR reference branch
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

        try {
            $result = $this->stockModel->addStockMovement($data);
            sendResponse($result["success"] ? 201 : 400, $result);
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