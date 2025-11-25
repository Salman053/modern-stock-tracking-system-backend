<?php
require_once "models/due_payment.model.php";

class DuePaymentController
{
    private $duePaymentModel;

    public function __construct()
    {
        $this->duePaymentModel = new DuePaymentModel();
    }

    public function handleRequest($segments)
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $paymentId = $segments[4] ?? null;

        // Check if this is a summary request
        if (isset($segments[3]) && $segments[3] === 'summary') {
            $this->handleGetSummary();
            return;
        }

        switch ($method) {
            case 'GET':
                if ($paymentId === null) {
                    $this->handleGetDuePayments();
                } else {
                    $this->handleGetDuePaymentById($paymentId);
                }
                break;
            case 'POST':
                $this->handleAddDuePayment();
                break;
            case 'PUT':
            case 'PATCH':
                if ($paymentId) {
                    $this->handleUpdateDuePayment($paymentId);
                } else {
                    $response = errorResponse("Due payment ID required for update");
                    sendResponse(400, $response);
                }
                break;
            case 'DELETE':
                if ($paymentId) {
                    $this->handleDeleteDuePayment($paymentId);
                } else {
                    $response = errorResponse("Due payment ID required for deletion");
                    sendResponse(400, $response);
                }
                break;
            default:
                $response = errorResponse("Method not allowed");
                sendResponse(405, $response);
                return;
        }
    }

    private function handleGetDuePayments()
    {
        $user = require_authenticated_user();

        // Get query parameters for filtering
        $branch_id = $_GET['branch_id'] ?? null;
        $user_id = $_GET['user_id'] ?? null;
        $due_type = $_GET['due_type'] ?? null;
        $due_id = $_GET['due_id'] ?? null;
        $start_date = $_GET['start_date'] ?? null;
        $end_date = $_GET['end_date'] ?? null;

        // Role-based access control
        if ($user['data']['role'] === 'branch-admin' || $user['data']['role'] === 'staff') {
            $branch_id = $user['data']['branch_id']; // Restrict to user's branch
        }

        try {
            if ($start_date && $end_date) {
                $result = $this->duePaymentModel->getDuePaymentsByDateRange($start_date, $end_date, $branch_id, $due_type);
            } else {
                $result = $this->duePaymentModel->getDuePayments($branch_id, $user_id, $due_type, $due_id);
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

    private function handleGetDuePaymentById($paymentId)
    {
        $user = require_authenticated_user();

        try {
            $result = $this->duePaymentModel->getDuePaymentById($paymentId);

            if ($result["success"]) {
                // Check if user has access to this payment
                $paymentBranch = $result['data']['branch_id'] ?? null;

                if ($user['data']['role'] === 'branch-admin' || $user['data']['role'] === 'staff') {
                    if ($paymentBranch !== $user['data']['branch_id']) {
                        $response = errorResponse("Access denied to this due payment");
                        sendResponse(403, $response);
                        return;
                    }
                }

                sendResponse(200, $result);
            } else {
                $response = errorResponse("Due payment not found");
                sendResponse(404, $response);
            }
            return;
        } catch (Exception $e) {
            $response = errorResponse("Database error: " . $e->getMessage());
            sendResponse(500, $response);
            return;
        }
    }

    private function handleAddDuePayment()
    {
        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data)) {
            $response = errorResponse("Invalid or empty JSON body");
            sendResponse(400, $response);
            return;
        }

        $user = require_authenticated_user();

        // Check if user has permission to add due payments
        if ($user['data']['role'] !== "super-admin" && $user['data']['role'] !== "branch-admin" && $user['data']['role'] !== "staff") {
            $response = errorResponse("Forbidden: Only super-admins, branch-admins, and staff can add due payments");
            sendResponse(403, $response);
            return;
        }

        // Set user_id and branch_id based on role
        $data['user_id'] = $user['data']['id'];

        if ($user['data']['role'] === 'branch-admin' || $user['data']['role'] === 'staff') {
            $data['branch_id'] = $user['data']['branch_id']; // Restrict to user's branch
        }

        try {
            $result = $this->duePaymentModel->addDuePayment($data);

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

    private function handleUpdateDuePayment($paymentId)
    {
        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data)) {
            $response = errorResponse("Invalid or empty JSON body");
            sendResponse(400, $response);
            return;
        }

        $user = require_authenticated_user();

        // Check if user has permission to update due payments
        if ($user['data']['role'] !== "super-admin" && $user['data']['role'] !== "branch-admin") {
            $response = errorResponse("Forbidden: Only admins can update due payments");
            sendResponse(403, $response);
            return;
        }

        try {
            // First get the existing payment to check permissions
            $existingPayment = $this->duePaymentModel->getDuePaymentById($paymentId);

            if (!$existingPayment['success']) {
                sendResponse(404, $existingPayment);
                return;
            }

            // Check branch access for branch-admin and staff
            if ($user['data']['role'] === 'branch-admin' || $user['data']['role'] === 'staff') {
                if ($existingPayment['data']['branch_id'] !== $user['data']['branch_id']) {
                    $response = errorResponse("Access denied to update this due payment");
                    sendResponse(403, $response);
                    return;
                }
            }

            $result = $this->duePaymentModel->updateDuePayment($paymentId, $data);

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

    private function handleDeleteDuePayment($paymentId)
    {
        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data)) {
            $response = errorResponse("Invalid or empty JSON body");
            sendResponse(400, $response);
            return;
        }

        $user = require_authenticated_user(true);

        if ($user['data']['role'] !== "super-admin" && $user['data']['role'] !== "branch-admin") {
            $response = errorResponse("Forbidden: Only admins can delete due payments");
            sendResponse(403, $response);
            return;
        }
        if (!password_verify($data["admin_password"], $user["data"]["password"])) {
            $response = errorResponse("Invalid admin password");
            sendResponse(401, $response);
            return;
        }

        try {
            // First get the existing payment to check permissions
            $existingPayment = $this->duePaymentModel->getDuePaymentById($paymentId);

            if (!$existingPayment['success']) {
                sendResponse(404, $existingPayment);
                return;
            }

            // Check branch access for branch-admin
            if ($user['data']['role'] === 'branch-admin') {
                if ($existingPayment['data']['branch_id'] !== $user['data']['branch_id']) {
                    $response = errorResponse("Access denied to delete this due payment");
                    sendResponse(403, $response);
                    return;
                }
            }

            $result = $this->duePaymentModel->deleteDuePayment($paymentId);

            if ($result["success"]) {
                sendResponse(200, $result);
            } else {
                $response = errorResponse("Due payment not found or deletion failed");
                sendResponse(404, $response);
            }
            return;
        } catch (Exception $e) {
            $response = errorResponse("Database error: " . $e->getMessage());
            sendResponse(500, $response);
            return;
        }
    }

    // Additional endpoint for getting summary
    public function handleGetSummary()
    {
        $user = require_authenticated_user();

        // Get query parameters
        $start_date = $_GET['start_date'] ?? null;
        $end_date = $_GET['end_date'] ?? null;
        $branch_id = $_GET['branch_id'] ?? null;

        // Role-based access control
        if ($user['data']['role'] === 'branch-admin' || $user['data']['role'] === 'staff') {
            $branch_id = $user['data']['branch_id']; // Restrict to user's branch
        }

        try {
            $result = $this->duePaymentModel->getDuePaymentsSummary($branch_id, $start_date, $end_date);

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