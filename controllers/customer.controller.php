<?php
require_once "models/customer.model.php";

class CustomerController
{
    private $customerModel;

    public function __construct()
    {
        $this->customerModel = new CustomerModel();
    }

    public function handleRequest($segments)
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $customerId = $segments[4] ?? null;

        switch ($method) {
            case 'GET':
                if ($customerId === null) {
                    $this->handleGetCustomers();
                } else {
                    $this->handleGetCustomerById($customerId);
                }
                break;
            case 'POST':
                $this->handleAddCustomer();
                break;
            case 'PUT':
            case 'PATCH':
                if ($customerId) {
                    $this->handleUpdateCustomer($customerId);
                } else {
                    $response = errorResponse("Customer ID required for update");
                    sendResponse(400, $response);
                }
                break;
            case 'DELETE':
                if ($customerId) {
                    $this->handleDeleteCustomer($customerId);
                } else {
                    $response = errorResponse("Customer ID required for deletion");
                    sendResponse(400, $response);
                }
                break;
            default:
                $response = errorResponse("Method not allowed");
                sendResponse(405, $response);
                return;
        }
    }

    private function handleGetCustomers()
    {
        $user = require_authenticated_user();

        // Get query parameters for filtering
        $branch_id = $_GET['branch_id'] ?? null;
        $user_id = $_GET['user_id'] ?? null;
        $include_archived = isset($_GET['include_archived']) && $_GET['include_archived'] === 'true';
        $regular_only = isset($_GET['regular_only']) && $_GET['regular_only'] === 'true';

        // Role-based access control
        if ($user['data']['role'] === 'branch-admin' || $user['data']['role'] === 'staff') {
            $branch_id = $user['data']['branch_id']; // Restrict to user's branch
        }

        try {
            // if ($regular_only) {
            //     $result = $this->customerModel->getRegularCustomers($branch_id);
            // } else {
            $result = $this->customerModel->getCustomers($branch_id, $user_id, $include_archived);
            // }

            if ($result["success"] === true) {
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

    private function handleGetCustomerById($customerId)
    {
        $user = require_authenticated_user();

        try {
            $result = $this->customerModel->getCustomerById($customerId);

            if ($result["success"]) {
                // Check if user has access to this customer
                $customerBranch = $result['data']['branch_id'] ?? null;

                if ($user['data']['role'] === 'branch-admin' || $user['data']['role'] === 'staff') {
                    if ($customerBranch !== $user['data']['branch_id']) {
                        $response = errorResponse("Access denied to this customer");
                        sendResponse(403, $response);
                        return;
                    }
                }

                sendResponse(200, $result);
            } else {
                $response = errorResponse("Customer not found");
                sendResponse(404, $response);
            }
            return;
        } catch (Exception $e) {
            $response = errorResponse("Database error: " . $e->getMessage());
            sendResponse(500, $response);
            return;
        }
    }

    private function handleAddCustomer()
    {
        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data)) {
            $response = errorResponse("Invalid or empty JSON body");
            sendResponse(400, $response);
            return;
        }

        $user = require_authenticated_user();

        // Check if user has permission to add customers
        if ($user['data']['role'] !== "super-admin" && $user['data']['role'] !== "branch-admin") {
            $response = errorResponse("Forbidden: Only admins can add customers");
            sendResponse(403, $response);
            return;
        }

        // Set user_id and branch_id based on role
        $data['user_id'] = $user['data']['id'];

        if ($user['data']['role'] === 'branch-admin' || $user['data']['role'] === 'staff') {
            $data['branch_id'] = $user['data']['branch_id']; // Restrict to user's branch
        }

        try {
            $result = $this->customerModel->addCustomer($data);

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

    private function handleUpdateCustomer($customerId)
    {
        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data)) {
            $response = errorResponse("Invalid or empty JSON body");
            sendResponse(400, $response);
            return;
        }

        $user = require_authenticated_user();

        // Check if user has permission to update customers
        if ($user['data']['role'] !== "super-admin" && $user['data']['role'] !== "branch-admin") {
            $response = errorResponse("Forbidden: Only admins can update customers");
            sendResponse(403, $response);
            return;
        }

        try {
            // First get the existing customer to check permissions
            $existingCustomer = $this->customerModel->getCustomerById($customerId);

            if (!$existingCustomer['success']) {
                sendResponse(404, $existingCustomer);
                return;
            }

            // Check branch access for branch-admin and staff
            if ($user['data']['role'] === 'branch-admin' || $user['data']['role'] === 'staff') {
                if ($existingCustomer['data']['branch_id'] !== $user['data']['branch_id']) {
                    $response = errorResponse("Access denied to update this customer");
                    sendResponse(403, $response);
                    return;
                }
            }

            $result = $this->customerModel->updateCustomer($customerId, $data);

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

    private function handleDeleteCustomer($customerId)
    {
        $user = require_authenticated_user();

        if ($user['data']['role'] !== "super-admin" && $user['data']['role'] !== "branch-admin") {
            $response = errorResponse("Forbidden: Only admins can delete customers");
            sendResponse(403, $response);
            return;
        }

        try {
            // First get the existing customer to check permissions
            $existingCustomer = $this->customerModel->getCustomerById($customerId);

            if (!$existingCustomer['success']) {
                sendResponse(404, $existingCustomer);
                return;
            }

            // Check branch access for branch-admin
            if ($user['data']['role'] === 'branch-admin') {
                if ($existingCustomer['data']['branch_id'] !== $user['data']['branch_id']) {
                    $response = errorResponse("Access denied to delete this customer");
                    sendResponse(403, $response);
                    return;
                }
            }

            $result = $this->customerModel->deleteCustomer($customerId);

            if ($result["success"]) {
                sendResponse(200, $result);
            } else {
                $response = errorResponse("Customer not found or deletion failed");
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