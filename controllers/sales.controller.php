<?php
require_once "models/sales.model.php";
require_once "utils/auth_utils.php";

class SalesController
{
    private $salesModel;

    public function __construct()
    {
        $this->salesModel = new SalesModel();
    }

    public function handleRequest($segments)
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $pathAction = $segments[4] ?? null;
        $saleId = $segments[5] ?? null;
        $itemId = $segments[6] ?? null;

        switch ($method) {
            case 'POST':
                $this->handlePost($pathAction, $saleId, $itemId);
                break;
            case 'GET':
                $this->handleGet($pathAction, $saleId);
                break;
            case 'PUT':
                $this->handlePut($pathAction, $saleId, $itemId);
                break;
            case 'DELETE':
                $this->handleDelete($pathAction, $saleId, $itemId);
                break;
            default:
                $response = errorResponse("Method not allowed");
                sendResponse(405, $response);
        }
    }

    private function handlePost($pathAction, $saleId, $itemId)
    {
        $data = json_decode(file_get_contents("php://input"), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $response = errorResponse("Invalid JSON body");
            sendResponse(400, $response);
            return;
        }

        $authUser = require_authenticated_user();

        if ($pathAction === null) {
            // POST /sales - create sale with or without items
            if (isset($data['items'])) {
                // Bulk create with items
                $saleData = $data['sale_data'] ?? $data;
                $items = $data['items'];
                
                // Add user and branch info
                $saleData['user_id'] = $authUser['data']['id'];
                $saleData['branch_id'] = $authUser['data']['branch_id'];
                
                $result = $this->salesModel->createSaleWithItems($saleData, $items);
            } else {
                // Single sale without items
                $data['user_id'] = $authUser['data']['id'];
                $data['branch_id'] = $authUser['data']['branch_id'];
                $result = $this->salesModel->createSale($data);
            }
            sendResponse($result['success'] ? 201 : 400, $result);
            
        } elseif ($pathAction === 'items' && $saleId !== null) {
            // POST /sales/{id}/items - add item to sale
            $data['user_id'] = $authUser['data']['id'];
            $data['branch_id'] = $authUser['data']['branch_id'];
            $result = $this->salesModel->addSaleItem($data, $saleId);
            sendResponse($result['success'] ? 201 : 400, $result);
            
        } else {
            $response = errorResponse("Invalid endpoint");
            sendResponse(404, $response);
        }
    }

    private function handleGet($pathAction, $saleId)
    {
        $authUser = require_authenticated_user();

        if ($saleId !== null && $pathAction === 'items') {
            // GET /sales/{id}/items
            $result = $this->salesModel->getSaleItemsBySaleId($saleId);
            sendResponse(200, $result);
            
        } elseif ($saleId !== null && $pathAction === null) {
            // GET /sales/{id}
            $include_items = isset($_GET['include_items']) && $_GET['include_items'] === 'true';
            $result = $this->salesModel->getSaleById($saleId, $include_items);
            sendResponse(200, $result);
            
        } elseif ($pathAction === null) {
            // GET /sales
            $branch_id = $this->getBranchIdFromAuth($authUser);
            $user_id = $_GET['user_id'] ?? null;
            $start_date = $_GET['start_date'] ?? null;
            $end_date = $_GET['end_date'] ?? null;
            $include_cancelled = isset($_GET['include_cancelled']) && $_GET['include_cancelled'] === 'true';
            
            $result = $this->salesModel->getSales($branch_id, $user_id, $start_date, $end_date, $include_cancelled);
            sendResponse(200, $result);
            
        } else {
            $response = errorResponse("Resource not found");
            sendResponse(404, $response);
        }
    }

    private function handlePut($pathAction, $saleId, $itemId)
    {
        $data = json_decode(file_get_contents("php://input"), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $response = errorResponse("Invalid JSON body");
            sendResponse(400, $response);
            return;
        }

        $authUser = require_authenticated_user();

        if ($saleId !== null && $itemId !== null) {
            // PUT /sales/{saleId}/items/{itemId}
            $result = $this->salesModel->updateSaleItem($itemId, $data);
            sendResponse($result['success'] ? 200 : 400, $result);
            
        } elseif ($saleId !== null && $pathAction === null) {
            // PUT /sales/{id}
            $result = $this->salesModel->updateSale($saleId, $data);
            sendResponse($result['success'] ? 200 : 400, $result);
            
        } else {
            $response = errorResponse("Invalid endpoint");
            sendResponse(404, $response);
        }
    }

    private function handleDelete($pathAction, $saleId, $itemId)
    {
        $authUser = require_authenticated_user();

        if ($saleId !== null && $itemId !== null) {
            // DELETE /sales/{saleId}/items/{itemId}
            $result = $this->salesModel->deleteSaleItem($itemId);
            sendResponse($result['success'] ? 200 : 400, $result);
            
        } elseif ($saleId !== null && $pathAction === null) {
            // DELETE /sales/{id}
            $result = $this->salesModel->cancelSale($saleId);
            sendResponse($result['success'] ? 200 : 400, $result);
            
        } else {
            $response = errorResponse("Invalid endpoint");
            sendResponse(404, $response);
        }
    }

    private function getBranchIdFromAuth($authUser)
    {
        if ($authUser['data']['role'] === "super-admin") {
            return $_GET['branch_id'] ?? null;
        } else {
            return $authUser['data']['branch_id'];
        }
    }
}