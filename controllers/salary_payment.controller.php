<?php
require_once "models/salary_payment.model.php";

class SalaryPaymentController
{
    private $salaryPaymentModel;

    public function __construct()
    {
        $this->salaryPaymentModel = new SalaryPaymentModel();
    }

    public function handleRequest($segments)
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $paymentId = $segments[4] ?? null;
        $action = $segments[5] ?? null;

        switch ($method) {
            case 'GET':
                if ($paymentId === null) {
                    if ($action === 'summary') {
                        $this->handleGetPaymentSummary();
                    } else {
                        $this->handleGetSalaryPayments();
                    }
                } else {
                    if ($action === 'employee-summary') {
                        $this->handleGetEmployeeSummary($paymentId); // paymentId is employee_id here
                    } else {
                        $this->handleGetSalaryPaymentById($paymentId);
                    }
                }
                break;
            case 'POST':
                $this->handleCreateSalaryPayment();
                break;
            case 'PUT':
            case 'PATCH':
                if ($paymentId) {
                    $this->handleUpdateSalaryPayment($paymentId);
                } else {
                    sendResponse(400, errorResponse("Payment ID required for update"));
                }
                break;
            case 'DELETE':
                if ($paymentId) {
                    $this->handleDeleteSalaryPayment($paymentId);
                } else {
                    sendResponse(400, errorResponse("Payment ID required for deletion"));
                }
                break;
            default:
                sendResponse(405, errorResponse("Method not allowed"));
        }
    }

    private function handleGetSalaryPayments()
    {
        $user = require_authenticated_user();

        // Get query parameters
        $branch_id = $_GET['branch_id'] ?? null;
        $employee_id = $_GET['employee_id'] ?? null;
        $start_date = $_GET['start_date'] ?? null;
        $end_date = $_GET['end_date'] ?? null;

        // Role-based access control
        if ($this->isBranchUser($user)) {
            $branch_id = $user['data']['branch_id'];
        }

        try {
            $result = $this->salaryPaymentModel->getSalaryPayments($branch_id, $employee_id, $start_date, $end_date);
            sendResponse($result["success"] ? 200 : 400, $result);
        } catch (Exception $e) {
            sendResponse(500, errorResponse("Database error: " . $e->getMessage()));
        }
    }

    private function handleGetSalaryPaymentById($paymentId)
    {
        $user = require_authenticated_user();

        try {
            $result = $this->salaryPaymentModel->getSalaryPaymentById($paymentId);

            if ($result["success"]) {
                // Check branch access
                $paymentBranch = $result['data']['branch_id'] ?? null;
                if ($this->isBranchUser($user) && $paymentBranch !== $user['data']['branch_id']) {
                    sendResponse(403, errorResponse("Access denied to this salary payment"));
                    return;
                }
                sendResponse(200, $result);
            } else {
                sendResponse(404, errorResponse("Salary payment not found"));
            }
        } catch (Exception $e) {
            sendResponse(500, errorResponse("Database error: " . $e->getMessage()));
        }
    }

    private function handleCreateSalaryPayment()
    {
        $data = $this->getJsonInput();
        if (!$data) return;

        $user = require_authenticated_user();
        if (!$this->isAdmin($user)) {
            sendResponse(403, errorResponse("Forbidden: Only admins can create salary payments"));
            return;
        }

        // Set user context
        $data['user_id'] = $user['data']['id'];
        if ($this->isBranchUser($user)) {
            $data['branch_id'] = $user['data']['branch_id'];
        }

        try {
            $result = $this->salaryPaymentModel->createSalaryPayment($data);
            sendResponse($result["success"] ? 201 : 400, $result);
        } catch (Exception $e) {
            sendResponse(500, errorResponse("Database error: " . $e->getMessage()));
        }
    }

    private function handleUpdateSalaryPayment($paymentId)
    {
        $data = $this->getJsonInput();
        if (!$data) return;

        $user = require_authenticated_user();
        if (!$this->isAdmin($user)) {
            sendResponse(403, errorResponse("Forbidden: Only admins can update salary payments"));
            return;
        }

        try {
            $result = $this->salaryPaymentModel->updateSalaryPayment($paymentId, $data);
            sendResponse($result["success"] ? 200 : 400, $result);
        } catch (Exception $e) {
            sendResponse(500, errorResponse("Database error: " . $e->getMessage()));
        }
    }

    private function handleDeleteSalaryPayment($paymentId)
    {
        $user = require_authenticated_user();
        if (!$this->isAdmin($user)) {
            sendResponse(403, errorResponse("Forbidden: Only admins can delete salary payments"));
            return;
        }

        try {
            $result = $this->salaryPaymentModel->deleteSalaryPayment($paymentId);
            sendResponse($result["success"] ? 200 : 400, $result);
        } catch (Exception $e) {
            sendResponse(500, errorResponse("Database error: " . $e->getMessage()));
        }
    }

    private function handleGetPaymentSummary()
    {
        $user = require_authenticated_user();

        $branch_id = $_GET['branch_id'] ?? null;
        $start_date = $_GET['start_date'] ?? null;
        $end_date = $_GET['end_date'] ?? null;

        // Role-based access control
        if ($this->isBranchUser($user)) {
            $branch_id = $user['data']['branch_id'];
        }

        if (!$branch_id) {
            sendResponse(400, errorResponse("Branch ID is required for summary"));
            return;
        }

        try {
            $result = $this->salaryPaymentModel->getBranchPaymentSummary($branch_id, $start_date, $end_date);
            sendResponse($result["success"] ? 200 : 400, $result);
        } catch (Exception $e) {
            sendResponse(500, errorResponse("Database error: " . $e->getMessage()));
        }
    }

    private function handleGetEmployeeSummary($employeeId)
    {
        $user = require_authenticated_user();

        $year = $_GET['year'] ?? date('Y');
        $month = $_GET['month'] ?? null;

        try {
            $result = $this->salaryPaymentModel->getEmployeePaymentSummary($employeeId, $year, $month);
            sendResponse($result["success"] ? 200 : 400, $result);
        } catch (Exception $e) {
            sendResponse(500, errorResponse("Database error: " . $e->getMessage()));
        }
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