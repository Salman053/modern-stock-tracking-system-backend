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

        switch ($method) {
            case 'GET':
                if ($branchId === null) {
                    $this->handleGetActiveBranches();
                } else {
                    $this->handleGetBranchById($branchId);
                }
                break;
            case 'POST':
                $this->handleAddBranch();
                break;
            case 'DELETE':
                $this->handleDeleteBranch($branchId);
                break;
            case 'PUT':
            case 'PATCH':
                if ($branchId) {
                    $this->handleUpdateBranch($branchId);
                } else {
                    $response = errorResponse("PUT/PATCH method not implemented");
                    sendResponse(405, $response);
                }
                return;
            default:
                $response = errorResponse("Method not allowed");
                sendResponse(405, $response);
                return;
        }
    }

    private function handleGetActiveBranches()
    {
        $user = require_authenticated_user();
        if ($user['data']['data']) {
            $result = $this->branchModel->getActiveBranches();
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

        $user = require_authenticated_user(); 

        if ($user['data']["role"] !== "super-admin") {
            $response = errorResponse("Forbidden: Only super-admin can add branches");
            sendResponse(403, $response);
            return;
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
            $response = errorResponse("Database error: " . $e->getMessage());
            sendResponse(500, $response);
            return;
        }
    }

    private function handleDeleteBranch($branchId)
    {
        if ($branchId === null) {
            $response = errorResponse("Branch ID required in URL path");
            sendResponse(400, $response);
            return;
        }

        $user = require_authenticated_user();

        if ($user['data']['data'] && $user['data']["role"] === "super-admin") {
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

        // Check authentication AND role
        if ($user['data']['data'] && ($user['data']["role"] === "super-admin" || $user['data']["role"] === "branch-admin")) {
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

        // Check authentication AND role
        if ($user['data']['data'] && ($user['data']["role"] === "super-admin" || $user['data']["role"] === "branch-admin")) {
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
}