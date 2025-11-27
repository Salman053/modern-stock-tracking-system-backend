<?php
require_once "models/employee.model.php";


class EmployeeController
{
    private $employeeModel;

    public function __construct()
    {
        $this->employeeModel = new EmployeeModel();
    }

    public function handleRequest($segments)
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $employeeId = $segments[4] ?? null;

        switch ($method) {
            case 'GET':
                if ($employeeId === null) {
                    $this->handleGetEmployees();
                } else {
                    $this->handleGetEmployeeById($employeeId);
                }
                break;
            case 'POST':
                $this->handleAddEmployee();
                break;
            case 'PUT':
            case 'PATCH':
                if ($employeeId) {
                    $this->handleUpdateEmployee($employeeId);
                } else {
                    $response = errorResponse("Employee ID required for update");
                    sendResponse(400, $response);
                }
                break;
            case 'DELETE':
                if ($employeeId) {
                    $this->handleDeleteEmployee($employeeId);
                } else {
                    $response = errorResponse("Employee ID required for deletion");
                    sendResponse(400, $response);
                }
                break;
            default:
                $response = errorResponse("Method not allowed");
                sendResponse(405, $response);
                return;
        }
    }

    private function handleGetEmployees()
    {
        $user = require_authenticated_user();

        $branch_id = $_GET['branch_id'] ?? null;
        $user_id = $_GET['user_id'] ?? null;
        $include_archived = isset($_GET['include_archived']) && $_GET['include_archived'] === 'true';
        $permanent_only = isset($_GET['permanent_only']) && $_GET['permanent_only'] === 'true';
        $designation = $_GET['designation'] ?? null;

        if ($user['data']['role'] === 'branch-admin' || $user['data']['role'] === 'staff') {
            $branch_id = $user['data']['branch_id'];
        }

        try {
            if ($permanent_only) {
                $result = $this->employeeModel->getPermanentEmployees($branch_id);
            } elseif ($designation) {
                $result = $this->employeeModel->getEmployeesByDesignation($designation, $branch_id);
            } else {
                $result = $this->employeeModel->getEmployees($branch_id, $user_id, $include_archived);
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

    private function handleGetEmployeeById($employeeId)
    {
        $user = require_authenticated_user();

        try {
            $result = $this->employeeModel->getEmployeeById($employeeId);

            if ($result["success"]) {
                // Check if user has access to this employee
                $employeeBranch = $result['data']['branch_id'] ?? null;

                if ($user['data']['role'] === 'branch-admin' || $user['data']['role'] === 'staff') {
                    if ($employeeBranch !== $user['data']['branch_id']) {
                        $response = errorResponse("Access denied to this employee");
                        sendResponse(403, $response);
                        return;
                    }
                }

                sendResponse(200, $result);
            } else {
                $response = errorResponse("Employee not found");
                sendResponse(404, $response);
            }
            return;
        } catch (Exception $e) {
            $response = errorResponse("Database error: " . $e->getMessage());
            sendResponse(500, $response);
            return;
        }
    }

    private function handleAddEmployee()
    {
        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data)) {
            $response = errorResponse("Invalid or empty JSON body");
            sendResponse(400, $response);
            return;
        }

        $user = require_authenticated_user();

        // Check if user has permission to add employees
        if ($user['data']['role'] !== "super-admin" && $user['data']['role'] !== "branch-admin") {
            $response = errorResponse("Forbidden: Only admins can add employees");
            sendResponse(403, $response);
            return;
        }

        // Set user_id and branch_id based on role
        $data['user_id'] = $user['data']['id'];

        if ($user['data']['role'] === 'branch-admin' || $user['data']['role'] === 'staff') {
            $data['branch_id'] = $user['data']['branch_id']; // Restrict to user's branch
        }

        try {
            $result = $this->employeeModel->addEmployee($data);

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

    private function handleUpdateEmployee($employeeId)
    {
        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data)) {
            $response = errorResponse("Invalid or empty JSON body");
            sendResponse(400, $response);
            return;
        }

        $user = require_authenticated_user();

        // Check if user has permission to update employees
        if ($user['data']['role'] !== "super-admin" && $user['data']['role'] !== "branch-admin") {
            $response = errorResponse("Forbidden: Only admins can update employees");
            sendResponse(403, $response);
            return;
        }

        try {
            // First get the existing employee to check permissions
            $existingEmployee = $this->employeeModel->getEmployeeById($employeeId);

            if (!$existingEmployee['success']) {
                sendResponse(404, $existingEmployee);
                return;
            }

            // Check branch access for branch-admin and staff
            if ($user['data']['role'] === 'branch-admin' || $user['data']['role'] === 'staff') {
                if ($existingEmployee['data']['branch_id'] !== $user['data']['branch_id']) {
                    $response = errorResponse("Access denied to update this employee");
                    sendResponse(403, $response);
                    return;
                }
            }

            $result = $this->employeeModel->updateEmployee($employeeId, $data);

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

    private function handleDeleteEmployee($employeeId)
    {
        $data = json_decode(file_get_contents("php://input"), true);
        $user = require_authenticated_user(true);

        if ($user['data']['role'] !== "super-admin" && $user['data']['role'] !== "branch-admin") {
            $response = errorResponse("Forbidden: Only admins can delete employees");
            sendResponse(403, $response);
            return;
        }

        try {

            $existingEmployee = $this->employeeModel->getEmployeeById($employeeId);

            if (!$existingEmployee['success']) {
                sendResponse(404, $existingEmployee);
                return;
            }


            if ($user['data']['role'] === 'branch-admin') {
                if ($existingEmployee['data']['branch_id'] !== $user['data']['branch_id']) {
                    $response = errorResponse("Access denied to delete this employee");
                    sendResponse(403, $response);
                    return;
                }
            }
            if (!password_verify($data["admin_password"], $user['data']['password'])) {
                $response = errorResponse("Invalid admin password");
                sendResponse(401, $response);
                return;
            }

            $result = $this->employeeModel->deleteEmployee($employeeId);

            if ($result["success"]) {
                sendResponse(200, $result);
            } else {
                $response = errorResponse("Employee not found or deletion failed");
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