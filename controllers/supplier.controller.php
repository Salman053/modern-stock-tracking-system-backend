<?php
require_once "models/supplier.model.php";

class SupplierController
{
    private $supplierModel;

    public function __construct()
    {
        $this->supplierModel = new SupplierModel();
    }

    public function handleRequest($segments)
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $supplierId = $segments[4] ?? null;

        switch ($method) {
            case 'GET':
                if ($supplierId === null) {
                    $this->handleGetSuppliers();
                } else {
                    $this->handleGetSupplierById($supplierId);
                }
                break;
            case 'POST':
                $this->handleAddSupplier();
                break;
            case 'PUT':
            case 'PATCH':
                if ($supplierId) {
                    $this->handleUpdateSupplier($supplierId);
                } else {
                    $response = errorResponse("Supplier ID required for update");
                    sendResponse(400, $response);
                }
                break;
            case 'DELETE':
                if ($supplierId) {
                    $this->handleDeleteSupplier($supplierId);
                } else {
                    $response = errorResponse("Supplier ID required for deletion");
                    sendResponse(400, $response);
                }
                break;
            default:
                $response = errorResponse("Method not allowed");
                sendResponse(405, $response);
                return;
        }
    }

    private function handleGetSuppliers()
    {
        $user = require_authenticated_user();

        // Get query parameters for filtering
        $branch_id = $_GET['branch_id'] ?? null;
        $user_id = $_GET['user_id'] ?? null;
        $include_archived = isset($_GET['include_archived']) && $_GET['include_archived'] === 'true';

        // Role-based access control
        if ($user['data']['role'] === 'branch-admin' || $user['data']['role'] === 'staff') {
            $branch_id = $user['data']['branch_id']; // Restrict to user's branch
        }

        try {
            $result = $this->supplierModel->getSuppliers($branch_id, $user_id, $include_archived);

            if ($result["success"]) {
                sendResponse(200, $result);
            } else {
                sendResponse(400, $result);
            }
            return;
        } catch (Exception $e) {
            $response = errorResponse("Database error: " . $e->getMessage());
            sendResponse(500, $response);
            return;
        }
    }

    private function handleGetSupplierById($supplierId)
    {
        $user = require_authenticated_user();

        try {
            $result = $this->supplierModel->getSupplierById($supplierId);

            if ($result["success"]) {
                // Check if user has access to this supplier
                $supplierBranch = $result['data']['branch_id'] ?? null;
                
                if ($user['data']['role'] === 'branch-admin' || $user['data']['role'] === 'staff') {
                    if ($supplierBranch !== $user['data']['branch_id']) {
                        $response = errorResponse("Access denied to this supplier");
                        sendResponse(403, $response);
                        return;
                    }
                }

                sendResponse(200, $result);
            } else {
                $response = errorResponse("Supplier not found");
                sendResponse(404, $response);
            }
            return;
        } catch (Exception $e) {
            $response = errorResponse("Database error: " . $e->getMessage());
            sendResponse(500, $response);
            return;
        }
    }

    private function handleAddSupplier()
    {
        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data)) {
            $response = errorResponse("Invalid or empty JSON body");
            sendResponse(400, $response);
            return;
        }

        $user = require_authenticated_user();

        // Check if user has permission to add suppliers
        if ($user['data']['role'] !== "super-admin" && $user['data']['role'] !== "branch-admin") {
            $response = errorResponse("Forbidden: Only admins can add suppliers");
            sendResponse(403, $response);
            return;
        }

        // Set user_id and branch_id based on role
        $data['user_id'] = $user['data']['id'];
        
        if ($user['data']['role'] === 'branch-admin' || $user['data']['role'] === 'staff') {
            $data['branch_id'] = $user['data']['branch_id']; // Restrict to user's branch
        }

        try {
            $result = $this->supplierModel->addSupplier($data);

            if ($result["success"]) {
                sendResponse(201, $result);
            } else {
                sendResponse(400, $result);
            }
            return;
        } catch (Exception $e) {
            $response = errorResponse("Database error: " . $e->getMessage());
            sendResponse(500, $response);
            return;
        }
    }

    private function handleUpdateSupplier($supplierId)
    {
        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data)) {
            $response = errorResponse("Invalid or empty JSON body");
            sendResponse(400, $response);
            return;
        }

        $user = require_authenticated_user();

        // Check if user has permission to update suppliers
        if ($user['data']['role'] !== "super-admin" && $user['data']['role'] !== "branch-admin") {
            $response = errorResponse("Forbidden: Only admins can update suppliers");
            sendResponse(403, $response);
            return;
        }

        try {
            // First get the existing supplier to check permissions
            $existingSupplier = $this->supplierModel->getSupplierById($supplierId);
            
            if (!$existingSupplier['success']) {
                sendResponse(404, $existingSupplier);
                return;
            }

            // Check branch access for branch-admin and staff
            if ($user['data']['role'] === 'branch-admin' || $user['data']['role'] === 'staff') {
                if ($existingSupplier['data']['branch_id'] !== $user['data']['branch_id']) {
                    $response = errorResponse("Access denied to update this supplier");
                    sendResponse(403, $response);
                    return;
                }
            }

            $result = $this->supplierModel->updateSupplier($supplierId, $data);

            if ($result["success"]) {
                sendResponse(200, $result);
            } else {
                sendResponse(400, $result);
            }
            return;
        } catch (Exception $e) {
            $response = errorResponse("Database error: " . $e->getMessage());
            sendResponse(500, $response);
            return;
        }
    }

    private function handleDeleteSupplier($supplierId)
    {
        $user = require_authenticated_user();

        if ($user['data']['role'] !== "super-admin" && $user['data']['role'] !== "branch-admin") {
            $response = errorResponse("Forbidden: Only admins can delete suppliers");
            sendResponse(403, $response);
            return;
        }

        try {
            // First get the existing supplier to check permissions
            $existingSupplier = $this->supplierModel->getSupplierById($supplierId);
            
            if (!$existingSupplier['success']) {
                sendResponse(404, $existingSupplier);
                return;
            }

            // Check branch access for branch-admin
            if ($user['data']['role'] === 'branch-admin') {
                if ($existingSupplier['data']['branch_id'] !== $user['data']['branch_id']) {
                    $response = errorResponse("Access denied to delete this supplier");
                    sendResponse(403, $response);
                    return;
                }
            }

            $result = $this->supplierModel->deleteSupplier($supplierId);

            if ($result["success"]) {
                sendResponse(200, $result);
            } else {
                $response = errorResponse("Supplier not found or deletion failed");
                sendResponse(404, $response);
            }
            return;
        } catch (Exception $e) {
            $response = errorResponse("Database error: " . $e->getMessage());
            sendResponse(500, $response);
            return;
        }
    }
}