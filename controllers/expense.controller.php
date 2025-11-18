<?php
require_once "models/expense.model.php";

class ExpenseController
{
    private $expenseModel;

    public function __construct()
    {
        $this->expenseModel = new ExpenseModel();
    }

    public function handleRequest($segments)
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $expenseId = $segments[4] ?? null;

        switch ($method) {
            case 'GET':
                if ($expenseId === null) {
                    $this->handleGetExpenses();
                } else {
                    $this->handleGetExpenseById($expenseId);
                }
                break;
            case 'POST':
                $this->handleAddExpense();
                break;
            case 'PUT':
            case 'PATCH':
                if ($expenseId) {
                    $this->handleUpdateExpense($expenseId);
                } else {
                    $response = errorResponse("Expense ID required for update");
                    sendResponse(400, $response);
                }
                break;
            case 'DELETE':
                if ($expenseId) {
                    $this->handleDeleteExpense($expenseId);
                } else {
                    $response = errorResponse("Expense ID required for deletion");
                    sendResponse(400, $response);
                }
                break;
            default:
                $response = errorResponse("Method not allowed");
                sendResponse(405, $response);
                return;
        }
    }

    private function handleGetExpenses()
    {
        $user = require_authenticated_user();

        // Get query parameters for filtering
        $branch_id = $_GET['branch_id'] ?? null;
        $user_id = $_GET['user_id'] ?? null;
        $include_archived = isset($_GET['include_archived']) && $_GET['include_archived'] === 'true';
        $type = $_GET['type'] ?? null;
        $start_date = $_GET['start_date'] ?? null;
        $end_date = $_GET['end_date'] ?? null;
        $year = $_GET['year'] ?? null;
        $month = $_GET['month'] ?? null;

        // Role-based access control
        if ($user['data']['role'] === 'branch-admin' || $user['data']['role'] === 'staff') {
            $branch_id = $user['data']['branch_id']; // Restrict to user's branch
        }

        try {
            if ($year && $month) {
                $result = $this->expenseModel->getExpensesSummaryByMonth($year, $month, $branch_id);
            } elseif ($start_date && $end_date) {
                $result = $this->expenseModel->getExpensesByDateRange($start_date, $end_date, $branch_id);
            } elseif ($type) {
                $result = $this->expenseModel->getExpensesByType($type, $branch_id);
            } else {
                $result = $this->expenseModel->getExpenses($branch_id, $user_id, $include_archived);
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

    private function handleGetExpenseById($expenseId)
    {
        $user = require_authenticated_user();

        try {
            $result = $this->expenseModel->getExpenseById($expenseId);

            if ($result["success"]) {
                // Check if user has access to this expense
                $expenseBranch = $result['data']['branch_id'] ?? null;
                
                if ($user['data']['role'] === 'branch-admin' || $user['data']['role'] === 'staff') {
                    if ($expenseBranch !== $user['data']['branch_id']) {
                        $response = errorResponse("Access denied to this expense");
                        sendResponse(403, $response);
                        return;
                    }
                }

                sendResponse(200, $result);
            } else {
                $response = errorResponse("Expense not found");
                sendResponse(404, $response);
            }
            return;
        } catch (Exception $e) {
            $response = errorResponse("Database error: " . $e->getMessage());
            sendResponse(500, $response);
            return;
        }
    }

    private function handleAddExpense()
    {
        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data)) {
            $response = errorResponse("Invalid or empty JSON body");
            sendResponse(400, $response);
            return;
        }

        $user = require_authenticated_user();

        // Check if user has permission to add expenses
        if ($user['data']['role'] !== "super-admin" && $user['data']['role'] !== "branch-admin") {
            $response = errorResponse("Forbidden: Only admins can add expenses");
            sendResponse(403, $response);
            return;
        }

        // Set user_id and branch_id based on role
        $data['user_id'] = $user['data']['id'];
        
        if ($user['data']['role'] === 'branch-admin' || $user['data']['role'] === 'staff') {
            $data['branch_id'] = $user['data']['branch_id']; // Restrict to user's branch
        }

        try {
            $result = $this->expenseModel->addExpense($data);

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

    private function handleUpdateExpense($expenseId)
    {
        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data)) {
            $response = errorResponse("Invalid or empty JSON body");
            sendResponse(400, $response);
            return;
        }

        $user = require_authenticated_user();

        // Check if user has permission to update expenses
        if ($user['data']['role'] !== "super-admin" && $user['data']['role'] !== "branch-admin") {
            $response = errorResponse("Forbidden: Only admins can update expenses");
            sendResponse(403, $response);
            return;
        }

        try {
            // First get the existing expense to check permissions
            $existingExpense = $this->expenseModel->getExpenseById($expenseId);
            
            if (!$existingExpense['success']) {
                sendResponse(404, $existingExpense);
                return;
            }

            // Check branch access for branch-admin and staff
            if ($user['data']['role'] === 'branch-admin' || $user['data']['role'] === 'staff') {
                if ($existingExpense['data']['branch_id'] !== $user['data']['branch_id']) {
                    $response = errorResponse("Access denied to update this expense");
                    sendResponse(403, $response);
                    return;
                }
            }

            $result = $this->expenseModel->updateExpense($expenseId, $data);

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

    private function handleDeleteExpense($expenseId)
    {
        $user = require_authenticated_user();

        if ($user['data']['role'] !== "super-admin" && $user['data']['role'] !== "branch-admin") {
            $response = errorResponse("Forbidden: Only admins can delete expenses");
            sendResponse(403, $response);
            return;
        }

        try {
            // First get the existing expense to check permissions
            $existingExpense = $this->expenseModel->getExpenseById($expenseId);
            
            if (!$existingExpense['success']) {
                sendResponse(404, $existingExpense);
                return;
            }

            // Check branch access for branch-admin
            if ($user['data']['role'] === 'branch-admin') {
                if ($existingExpense['data']['branch_id'] !== $user['data']['branch_id']) {
                    $response = errorResponse("Access denied to delete this expense");
                    sendResponse(403, $response);
                    return;
                }
            }

            $result = $this->expenseModel->deleteExpense($expenseId);

            if ($result["success"]) {
                sendResponse(200, $result);
            } else {
                $response = errorResponse("Expense not found or deletion failed");
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