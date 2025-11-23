<?php
require_once "models/branch.model.php";

class BranchController
{
    private $branchModel;

    public function __construct()
    {
        $this->branchModel = new BranchModel();
    }

    public function handleRequest($segments)
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $branchId = $segments[4] ?? null;
        $action = $segments[5] ?? null;

        // Fix: Proper parameter extraction
        $queryParams = $_GET;
        $include_archived = $queryParams['include_archived'] ?? null;
        $status = $queryParams['status'] ?? null;

        switch ($method) {
            case 'GET':
                if ($branchId === null && $action === null) {
                    if ($include_archived || $status) {
                        $this->handleAllBranches($status, $include_archived);
                    } else {
                        $this->handleGetActiveBranches();
                    }
                } elseif ($branchId && $action === 'users') {
                    $this->handleGetBranchUsers($branchId);
                } elseif ($branchId && $action === 'stats') {
                    $this->handleGetBranchStats($branchId);
                } elseif ($branchId && $action === 'summary') {
                    $this->handleGetBranchesSummary();
                } elseif ($branchId && $action === null) {
                    $this->handleGetBranchById($branchId);
                } else {
                    $response = errorResponse("Invalid endpoint");
                    sendResponse(404, $response);
                }
                break;
            case 'POST':
                if ($action === 'bulk') {
                    $this->handleBulkAddBranches();
                } else {
                    $this->handleAddBranch();
                }
                break;
            case 'DELETE':
                if ($action === 'bulk') {
                    $this->handleBulkDeleteBranches();
                } else {
                    $this->handleDeleteBranch($branchId);
                }
                break;
            case 'PUT':
            case 'PATCH':
                if ($branchId && $action === 'restore') {
                    $this->handleRestoreBranch($branchId);
                } elseif ($branchId && $action === 'status') {
                    $this->handleUpdateBranchStatus($branchId);
                } elseif ($branchId) {
                    $this->handleUpdateBranch($branchId);
                } else {
                    $response = errorResponse("Branch ID required for update");
                    sendResponse(400, $response);
                }
                break;
            default:
                $response = errorResponse("Method not allowed");
                sendResponse(405, $response);
                return;
        }
    }

    private function handleGetActiveBranches()
    {
        $user = require_authenticated_user();
        if ($user['data']['role']) {
            $result = $this->branchModel->getActiveBranches();
            sendResponse(200, $result);
        } else {
            $response = errorResponse("Unauthorized or session expired");
            sendResponse(401, $response);
        }
    }

    private function handleAllBranches($status, $include_archived)
    {
        $user = require_authenticated_user();
        if ($user['data']['role']) {
            $result = $this->branchModel->getAllBranches($status, $include_archived);
            sendResponse(200, $result);
        } else {
            $response = errorResponse("Unauthorized or session expired");
            sendResponse(401, $response);
        }
    }

    private function handleAddBranch()
    {
        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data)) {
            $response = errorResponse("Invalid or empty JSON body");
            sendResponse(400, $response);
            return;
        }

        $user = require_authenticated_user(true);

        if ($user['data']["role"] !== "super-admin") {
            $response = errorResponse("Forbidden: Only super-admin can add branches" . $user["data"]["password"]);
            sendResponse(403, $response);
            return;
        }
        if (!password_verify($data["password"], $user["data"]["password"])) {
            $response = errorResponse("The password is wrong it seems like your are not the actual super admin user " . $user["data"]["password"]);
            sendResponse(400, $response);
        }
        try {
            $result = $this->branchModel->addNewBranch($data);

            if ($result["success"]) {
                sendResponse(201, $result);
            } else {
                sendResponse(400, $result);
            }
            return;
        } catch (Exception $e) {
            $response = errorResponse("Database error : " . $e->getMessage());
            sendResponse(500, $response);
            return;
        }
    }

    private function handleDeleteBranch($branchId)
    {
        $data = json_decode(file_get_contents("php://input"), true);

        if ($branchId === null) {
            $response = errorResponse("Branch ID required in URL path");
            sendResponse(400, $response);
            return;
        }


        $user = require_authenticated_user(true);

        if (!password_verify($data["password"], $user['data']['password'])) {
            $response = errorResponse("Password is incorrect");
            sendResponse(404, $response);

            return;
        }

        if ($user['data']['role'] && $user['data']["role"] === "super-admin") {
            try {
                $result = $this->branchModel->deleteBranch($branchId);

                if ($result["success"]) {
                    sendResponse(200, $result);
                } else {
                    $response = errorResponse("Branch not found or already archived");
                    sendResponse(404, $response);
                }
                return;
            } catch (Exception $e) {
                $response = errorResponse("Database error: " . $e->getMessage());
                sendResponse(500, $response);
                return;
            }
        } else {
            $response = errorResponse("Forbidden: Only super-admin can archive branches");
            sendResponse(403, $response);
            return;
        }
    }

    private function handleUpdateBranch($branchId)
    {
        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data)) {
            $response = errorResponse("Invalid or empty JSON body");
            sendResponse(400, $response);
            return;
        }

        if ($branchId === null) {
            $response = errorResponse("Branch ID required in URL path");
            sendResponse(400, $response);
            return;
        }

        $user = require_authenticated_user();

        if ($user['data']['role'] && ($user['data']["role"] === "super-admin" || $user['data']["role"] === "branch-admin")) {
            try {
                $result = $this->branchModel->updateBranch($branchId, $data);

                if ($result["success"]) {
                    sendResponse(200, $result);
                } else {
                    $response = errorResponse("Branch not found");
                    sendResponse(404, $response);
                }
                return;
            } catch (Exception $e) {
                $response = errorResponse("Database error: " . $e->getMessage());
                sendResponse(500, $response);
                return;
            }
        } else {
            $response = errorResponse("Forbidden: Only super-admin and branch-admin can update branches");
            sendResponse(403, $response);
            return;
        }
    }

    private function handleGetBranchById($branchId)
    {
        if ($branchId === null) {
            $response = errorResponse("Branch ID required in URL path");
            sendResponse(400, $response);
            return;
        }

        $user = require_authenticated_user();

        if ($user['data']['role'] && ($user['data']["role"] === "super-admin" || $user['data']["role"] === "branch-admin")) {
            try {
                $result = $this->branchModel->getBranchById($branchId);
                sendResponse(200, $result);
            } catch (Exception $e) {
                $response = errorResponse("Database error: " . $e->getMessage());
                sendResponse(500, $response);
            }
        } else {
            $response = errorResponse("Forbidden: Only super-admin and branch-admin can access branches");
            sendResponse(403, $response);
        }
    }

    // NEW FUNCTIONS

    private function handleGetBranchUsers($branchId)
    {
        $user = require_authenticated_user();
        if ($user['data']['role']) {
            $result = $this->branchModel->getBranchUsers($branchId);
            sendResponse(200, $result);
        } else {
            $response = errorResponse("Unauthorized or session expired");
            sendResponse(401, $response);
        }
    }

    private function handleGetBranchStats($branchId)
    {
        $user = require_authenticated_user();
        if ($user['data']['role']) {
            $result = $this->branchModel->getBranchStatistics($branchId);
            sendResponse(200, $result);
        } else {
            $response = errorResponse("Unauthorized or session expired");
            sendResponse(401, $response);
        }
    }


    private function handleGetBranchesSummary()
    {
        $user = require_authenticated_user();
        if ($user['data']['role']) {
            $result = $this->branchModel->getBranchesSummary();
            sendResponse(200, $result);
        } else {
            $response = errorResponse("Unauthorized or session expired");
            sendResponse(401, $response);
        }
    }

    private function handleRestoreBranch($branchId)
    {
        $user = require_authenticated_user();

        if ($user['data']['role'] && $user['data']["role"] === "super-admin") {
            try {
                $result = $this->branchModel->restoreBranch($branchId);

                if ($result["success"]) {
                    sendResponse(200, $result);
                } else {
                    sendResponse(404, $result);
                }
            } catch (Exception $e) {
                $response = errorResponse("Database error: " . $e->getMessage());
                sendResponse(500, $response);
            }
        } else {
            $response = errorResponse("Forbidden: Only super-admin can restore branches");
            sendResponse(403, $response);
        }
    }

    private function handleUpdateBranchStatus($branchId)
    {
        $data = json_decode(file_get_contents("php://input"), true);
        $status = $data['status'] ?? null;

        if (!in_array($status, ['active', 'inactive'])) {
            $response = errorResponse("Invalid status value");
            sendResponse(400, $response);
            return;
        }

        $user = require_authenticated_user();

        if ($user['data']['role'] && $user['data']["role"] === "super-admin") {
            try {
                $result = $this->branchModel->updateBranchStatus($branchId, $status);
                sendResponse(200, $result);
            } catch (Exception $e) {
                $response = errorResponse("Database error: " . $e->getMessage());
                sendResponse(500, $response);
            }
        } else {
            $response = errorResponse("Forbidden: Only super-admin can update branch status");
            sendResponse(403, $response);
        }
    }

    private function handleBulkAddBranches()
    {
        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data) || !is_array($data)) {
            $response = errorResponse("Invalid or empty JSON array in body");
            sendResponse(400, $response);
            return;
        }

        $user = require_authenticated_user();

        if ($user['data']["role"] !== "super-admin") {
            $response = errorResponse("Forbidden: Only super-admin can add branches in bulk");
            sendResponse(403, $response);
            return;
        }

        try {
            $result = $this->branchModel->bulkAddBranches($data);
            sendResponse(201, $result);
        } catch (Exception $e) {
            $response = errorResponse("Database error: " . $e->getMessage());
            sendResponse(500, $response);
        }
    }

    private function handleBulkDeleteBranches()
    {
        $data = json_decode(file_get_contents("php://input"), true);
        $branchIds = $data['branch_ids'] ?? [];

        if (empty($branchIds) || !is_array($branchIds)) {
            $response = errorResponse("Invalid or empty branch_ids array in body");
            sendResponse(400, $response);
            return;
        }

        $user = require_authenticated_user();

        if ($user['data']['role'] && $user['data']["role"] === "super-admin") {
            try {
                $result = $this->branchModel->bulkDeleteBranches($branchIds);
                sendResponse(200, $result);
            } catch (Exception $e) {
                $response = errorResponse("Database error: " . $e->getMessage());
                sendResponse(500, $response);
            }
        } else {
            $response = errorResponse("Forbidden: Only super-admin can archive branches in bulk");
            sendResponse(403, $response);
        }
    }
}
