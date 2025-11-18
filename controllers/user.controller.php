<?php
require_once "models/user.model.php";
require_once "utils/auth_utils.php";

class UserController
{
    private $userModel;

    public function __construct()
    {
        $this->userModel = new UserModel();
    }

    public function handleRequest($pathAction = null)
    {
        $method = $_SERVER['REQUEST_METHOD'];

        switch ($method) {
            case 'POST':
                $this->handlePost($pathAction);
                break;
            case 'GET':
                $this->handleGet($pathAction);
                break;
            default:
                $response = errorResponse("Method not allowed");
                sendResponse(405, $response);
        }
    }

    // --- POST Request Handlers ---    
    private function handlePost($pathAction)
    {
        $data = json_decode(file_get_contents("php://input"), true);

        if (json_last_error() !== JSON_ERROR_NONE && !in_array($pathAction, ['logout'])) {
            $response = errorResponse("Invalid JSON body");
            sendResponse(400, $response);
            return;
        }

        switch ($pathAction) {
            case 'login':
                $this->login($data);
                break;
            case 'register':
                $this->register($data);
                break;
            case 'logout':
                $this->logoutSessionUser();
                break;
            case 'update':
                $this->updateUser($data);
                break;
            case 'deactivate':
                $this->deactivateUser($data);
                break;
            default:
                $response = errorResponse("Action not found or invalid route for POST");
                sendResponse(404, $response);
        }
    }

    // --- GET Request Handler ---
    private function handleGet($pathAction)
    {
        switch ($pathAction) {
            case 'me':
                $this->handleGetAuthenticatedUser();
                break;
            case 'single':
                $this->getSingleUser();
                break;
            case 'branch':
                $this->getUsersByBranch();
                break;
            case 'all':
                $this->getAllUsers();
                break;
            case null:
                $this->handleGetAuthenticatedUser();
                break;
            default:
                $response = errorResponse("Resource not found");
                sendResponse(404, $response);
        }
    }

    private function handleGetAuthenticatedUser()
    {
        $user = require_authenticated_user();
        unset($user['password']);
        sendResponse(200, $user);
    }

    private function logoutSessionUser()
    {
        if (destroy_session_and_return_false()) {
            $response = successResponse("Successfully logged out");
            sendResponse(200, $response);
        } else {
            $response = errorResponse("Logout failed");
            sendResponse(500, $response);
        }
    }

    private function login($data)
    {
        if (empty($data['username']) || empty($data['password'])) {
            $response = errorResponse("Username and password are required");
            sendResponse(400, $response);
            return;
        }

        $result = $this->userModel->login($data['username'], $data['password']);

        if ($result['success']) {
            sendResponse(200, $result);
        } else {
            sendResponse(401, $result);
        }
    }

    private function register($data)
    {
        $user = require_authenticated_user();

        if (($user['data']["role"] ?? null) !== "super-admin") {
            $response = errorResponse("Forbidden. Only super-admin can register new users");
            sendResponse(403, $response);
            return;
        }

        if (empty($data['username']) || empty($data['password']) || empty($data['email'])) {
            $response = errorResponse("Username, password, and email are required");
            sendResponse(400, $response);
            return;
        }

        $result = $this->userModel->createUser($data);

        if ($result['success']) {
            sendResponse(201, $result);
        } else {
            $statusCode = ($result['code'] ?? null) === 'DUPLICATE_USER' ? 409 : 400;
            sendResponse($statusCode, $result);
        }
    }

    // --- Update User ---
    private function updateUser($data)
    {
        $authUser = require_authenticated_user();

        $targetUserId = ($authUser['data']["role"] === "super-admin")
            ? ($data["user_id"] ?? null)
            : $authUser['data']["id"];

        if ($authUser["role"] === "super-admin" && empty($targetUserId)) {
            $response = errorResponse("Super admin must provide user_id");
            sendResponse(400, $response);
            return;
        }

        $allowedFields = ["username", "email", "status", "full_name", "password", "role"];
        $updateData = array_intersect_key($data, array_flip($allowedFields));

        if (empty($updateData)) {
            $response = errorResponse("No valid fields to update");
            sendResponse(400, $response);
            return;
        }

        $result = $this->userModel->updateUser($targetUserId, $updateData);

        if ($result["success"]) {
            $response = successResponse("User updated successfully");
            sendResponse(200, $response);
        } else {
            $response = errorResponse($result["message"] ?? "Update failed");
            sendResponse(400, $response);
        }
    }

    // --- Deactivate User ---
    private function deactivateUser($data)
    {
        $authUser = require_authenticated_user();

        if (($authUser['data']["role"] ?? null) !== "super-admin") {
            $response = errorResponse("Only super-admin can deactivate users");
            sendResponse(403, $response);
            return;
        }

        $userId = $data["user_id"] ?? null;
        if (empty($userId)) {
            $response = errorResponse("User ID is required to deactivate a user");
            sendResponse(400, $response);
            return;
        }

        $result = $this->userModel->deleteUser($userId);

        if ($result["success"]) {
            $response = successResponse("User deactivated successfully");
            sendResponse(200, $response);
        } else {
            $response = errorResponse($result["message"] ?? "Deactivation failed");
            sendResponse(400, $response);
        }
    }

    private function getSingleUser()
    {
        $authUser = require_authenticated_user();

        if ($authUser['data']["role"] === "super-admin") {
            $user_id = $_GET['user_id'] ?? null;
            if (empty($user_id)) {
                $response = errorResponse("user_id parameter is required");
                sendResponse(400, $response);
                return;
            }
        } else if ($authUser['data']["role"] === "branch-admin") {
            $user_id = $_GET['user_id'] ?? null;
            if (empty($user_id)) {
                $response = errorResponse("user_id parameter is required");
                sendResponse(400, $response);
                return;
            }
            $targetUser = $this->userModel->getUserById($user_id);
            if ($targetUser['success'] && $targetUser['data']['branch_id'] !== $authUser['data']['branch_id']) {
                $response = errorResponse("Access denied. You can only view users from your branch");
                sendResponse(403, $response);
                return;
            }
        } else {
            $user_id = $authUser['data']["id"];
        }

        $result = $this->userModel->getUserById($user_id);

        if ($result['success']) {
            sendResponse(200, $result);
        } else {
            sendResponse(404, $result);
        }
    }

    private function getUsersByBranch()
    {
        $authUser = require_authenticated_user();

        if ($authUser["data"]["role"] === "super-admin") {
            $branch_id = $_GET['branch_id'] ?? null;
            if (empty($branch_id)) {
                $response = errorResponse("branch_id parameter is required for super admin");
                sendResponse(400, $response);
                return;
            }
        } else {
            $branch_id = $authUser['data']['branch_id'];
        }

        $include_archived = isset($_GET['include_archived']) && $_GET['include_archived'] === 'true';
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;

        if ($page < 1) $page = 1;
        if ($limit < 1 || $limit > 100) $limit = 10;

        $result = $this->userModel->getUsersByBranchPaginated($branch_id, $page, $limit, $include_archived);

        if ($result['success']) {
            sendResponse(200, $result);
        } else {
            sendResponse(400, $result);
        }
    }

    private function getAllUsers()
    {
        $authUser = require_authenticated_user();

        if ($authUser["data"]["role"] !== "super-admin") {
            $response = errorResponse("Forbidden. Only super-admin can access all users");
            sendResponse(403, $response);
            return;
        }

        $branch_id = $_GET['branch_id'] ?? null;
        $include_archived = isset($_GET['include_archived']) && $_GET['include_archived'] === 'true';

        $result = $this->userModel->getAllUsers($branch_id, $include_archived);

        if ($result['success']) {
            sendResponse(200, $result);
        } else {
            sendResponse(400, $result);
        }
    }
}
