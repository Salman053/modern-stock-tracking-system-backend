<?php
require_once "models/supplier_due.php";

class SupplierDueController
{
    private $supplierDueModel;

    public function __construct()
    {
        $this->supplierDueModel = new SupplierDueModel();
    }

    public function handleRequest($segments)
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $dueId = $segments[4] ?? null;

        switch ($method) {
            case 'GET':
                if ($dueId === null) {
                    $this->handleGetSupplierDues();
                } else {
                    $this->handleGetSupplierDueById($dueId);
                }
                break;
            case 'POST':
                $this->handleCreateSupplierDue();
                break;
            case 'PUT':
            case 'PATCH':
                if ($dueId) {
                    $this->handleUpdateSupplierDuePayment($dueId);
                } else {
                    $response = errorResponse("Supplier due ID required for payment update");
                    sendResponse(400, $response);
                }
                break;
            default:
                $response = errorResponse("Method not allowed");
                sendResponse(405, $response);
                return;
        }
    }

    private function handleGetSupplierDues()
    {
        $user = require_authenticated_user();

        // Get query parameters for filtering
        $supplier_id = $_GET['supplier_id'] ?? null;
        $branch_id = $_GET['branch_id'] ?? null;
        $status = $_GET['status'] ?? null;
        $data_type = $_GET['data_type'] ?? 'dues'; // dues, summary

        // Role-based access control
        if ($user['data']['role'] === 'branch-admin' || $user['data']['role'] === 'staff') {
            $branch_id = $user['data']['branch_id']; // Restrict to user's branch
        }

        try {
            if ($data_type === 'summary') {
                $result = $this->supplierDueModel->getSupplierDueSummary($branch_id);
            } else {
                $result = $this->supplierDueModel->getSupplierDues($supplier_id, $branch_id, $status);
            }

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

    private function handleGetSupplierDueById($dueId)
    {
        $user = require_authenticated_user();

        try {
            $result = $this->supplierDueModel->getSupplierDueById($dueId);

            if ($result["success"]) {
                $dueBranch = $result['data']['branch_id'] ?? null;

                if ($user['data']['role'] === 'branch-admin' || $user['data']['role'] === 'staff') {
                    if ($dueBranch !== $user['data']['branch_id']) {
                        $response = errorResponse("Access denied to this supplier due");
                        sendResponse(403, $response);
                        return;
                    }
                }

                sendResponse(200, $result);
            } else {
                $response = errorResponse("Supplier due not found");
                sendResponse(404, $response);
            }
            return;
        } catch (Exception $e) {
            $response = errorResponse("Database error: " . $e->getMessage());
            sendResponse(500, $response);
            return;
        }
    }

    private function handleCreateSupplierDue()
    {
        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data)) {
            $response = errorResponse("Invalid or empty JSON body");
            sendResponse(400, $response);
            return;
        }

        $user = require_authenticated_user();

        // Check if user has permission to create supplier dues
        if ($user['data']['role'] !== "super-admin" && $user['data']['role'] !== "branch-admin") {
            $response = errorResponse("Forbidden: Only admins can create supplier dues");
            sendResponse(403, $response);
            return;
        }

        // Set branch_id based on role if not provided
        if (empty($data['branch_id'])) {
            if ($user['data']['role'] === 'branch-admin' || $user['data']['role'] === 'staff') {
                $data['branch_id'] = $user['data']['branch_id'];
            }
        }

        try {
            $result = $this->supplierDueModel->createSupplierDue($data);

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

    private function handleUpdateSupplierDuePayment($dueId)
    {
        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data) || !isset($data['payment_amount'])) {
            $response = errorResponse("Payment amount is required");
            sendResponse(400, $response);
            return;
        }

        $user = require_authenticated_user();

        if ($user['data']['role'] !== "super-admin" && $user['data']['role'] !== "branch-admin") {
            $response = errorResponse("Forbidden: Only admins can update supplier due payments");
            sendResponse(403, $response);
            return;
        }

        try {
            // First get the existing due to check permissions
            $existingDue = $this->supplierDueModel->getSupplierDueById($dueId);

            if (!$existingDue['success']) {
                sendResponse(404, $existingDue);
                return;
            }

            // Check branch access for branch-admin
            if ($user['data']['role'] === 'branch-admin') {
                if ($existingDue['data']['branch_id'] !== $user['data']['branch_id']) {
                    $response = errorResponse("Access denied to update this supplier due");
                    sendResponse(403, $response);
                    return;
                }
            }

            $result = $this->supplierDueModel->updatePayment($dueId, $data['payment_amount']);

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
}