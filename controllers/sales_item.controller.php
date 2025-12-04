<?php
require_once "models/sales_item.model.php";
require_once "utils/auth_utils.php";

class SalesItemController
{
    private $salesItemModel;

    public function __construct()
    {
        $this->salesItemModel = new SalesItemModel();
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
            case 'PUT':
                $this->handlePut($pathAction);
                break;
            case 'DELETE':
                $this->handleDelete($pathAction);
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

        if (json_last_error() !== JSON_ERROR_NONE) {
            $response = errorResponse("Invalid JSON body");
            sendResponse(400, $response);
            return;
        }

        switch ($pathAction) {
            case 'add':
                $this->addSaleItem($data);
                break;
            case 'batch':
                $this->addBatchSaleItems($data);
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
            case 'single':
                $this->getSingleSaleItem();
                break;
            case 'sale':
                $this->getSaleItemsBySale();
                break;
            case 'summary':
                $this->getSaleItemsSummary();
                break;
            case 'product':
                $this->getSaleItemsByProduct();
                break;
            case null:
                $response = errorResponse("Resource not found");
                sendResponse(404, $response);
                break;
            default:
                $response = errorResponse("Resource not found");
                sendResponse(404, $response);
        }
    }

    // --- PUT Request Handler ---
    private function handlePut($pathAction)
    {
        $data = json_decode(file_get_contents("php://input"), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $response = errorResponse("Invalid JSON body");
            sendResponse(400, $response);
            return;
        }

        switch ($pathAction) {
            case 'update':
                $this->updateSaleItem($data);
                break;
            default:
                $response = errorResponse("Action not found or invalid route for PUT");
                sendResponse(404, $response);
        }
    }

    // --- DELETE Request Handler ---
    private function handleDelete($pathAction)
    {
        $data = json_decode(file_get_contents("php://input"), true);

        switch ($pathAction) {
            case 'remove':
                $this->removeSaleItem($data);
                break;
            default:
                $response = errorResponse("Action not found or invalid route for DELETE");
                sendResponse(404, $response);
        }
    }

    // --- Sale Item Methods ---
    private function addSaleItem($data)
    {
        $authUser = require_authenticated_user();

        // Validate required fields
        $required_fields = ["sale_id", "product_id", "quantity", "unit_price"];
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                $response = errorResponse("{$field} is required");
                sendResponse(400, $response);
                return;
            }
        }

        // Add user and branch info to data
        $data['user_id'] = $authUser['data']['id'];
        $data['branch_id'] = $authUser['data']['branch_id'];

        $result = $this->salesItemModel->addSaleItem($data);

        if ($result['success']) {
            sendResponse(201, $result);
        } else {
            $statusCode = $this->getStatusCodeFromErrorCode($result['code'] ?? '');
            sendResponse($statusCode, $result);
        }
    }

    private function addBatchSaleItems($data)
    {
        $authUser = require_authenticated_user();

        if (empty($data['sale_id']) || empty($data['items'])) {
            $response = errorResponse("sale_id and items are required");
            sendResponse(400, $response);
            return;
        }

        if (!is_array($data['items']) || count($data['items']) === 0) {
            $response = errorResponse("At least one item is required");
            sendResponse(400, $response);
            return;
        }

        $sale_id = $data['sale_id'];
        $results = [];
        $allSuccess = true;

        foreach ($data['items'] as $index => $item) {
            $item['sale_id'] = $sale_id;
            $item['user_id'] = $authUser['data']['id'];
            $item['branch_id'] = $authUser['data']['branch_id'];

            // Validate required fields for each item
            $required_fields = ["product_id", "quantity", "unit_price"];
            foreach ($required_fields as $field) {
                if (empty($item[$field])) {
                    $results[] = [
                        'index' => $index,
                        'success' => false,
                        'message' => "{$field} is required for item at index {$index}"
                    ];
                    $allSuccess = false;
                    continue 2;
                }
            }

            $result = $this->salesItemModel->addSaleItem($item);

            if ($result['success']) {
                $results[] = [
                    'index' => $index,
                    'success' => true,
                    'data' => $result['data']
                ];
            } else {
                $results[] = [
                    'index' => $index,
                    'success' => false,
                    'message' => $result['message'],
                    'code' => $result['code'] ?? null
                ];
                $allSuccess = false;
            }
        }

        if ($allSuccess) {
            $response = successResponse("All items added successfully", $results);
            sendResponse(201, $response);
        } else {
            $response = errorResponse("Some items failed to add", $results, "BATCH_PARTIAL_FAILURE");
            sendResponse(207, $response); // 207 Multi-Status
        }
    }

    private function getSingleSaleItem()
    {
        $authUser = require_authenticated_user();

        $item_id = $_GET['item_id'] ?? null;
        if (empty($item_id)) {
            $response = errorResponse("item_id parameter is required");
            sendResponse(400, $response);
            return;
        }

        $result = $this->salesItemModel->getSaleItemById($item_id);

        if (!$result['success']) {
            $statusCode = ($result['code'] ?? null) === 'ITEM_NOT_FOUND' ? 404 : 400;
            sendResponse($statusCode, $result);
            return;
        }

        // Check permissions
        $itemData = $result['data'];

        if (
            ($authUser['data']['role'] === 'branch-admin' || $authUser['data']['role'] === 'staff') &&
            $itemData['branch_id'] !== $authUser['data']['branch_id']
        ) {
            $response = errorResponse("Access denied. You can only view items from your branch");
            sendResponse(403, $response);
            return;
        }

        if ($authUser['data']['role'] === 'staff' && $itemData['user_id'] !== $authUser['data']['id']) {
            $response = errorResponse("Access denied. You can only view your own items");
            sendResponse(403, $response);
            return;
        }

        sendResponse(200, $result);
    }

    private function getSaleItemsBySale()
    {
        $authUser = require_authenticated_user();

        $sale_id = $_GET['sale_id'] ?? null;
        if (empty($sale_id)) {
            $response = errorResponse("sale_id parameter is required");
            sendResponse(400, $response);
            return;
        }

        $result = $this->salesItemModel->getSaleItemsBySaleId($sale_id);

        if ($result['success']) {
            sendResponse(200, $result);
        } else {
            sendResponse(400, $result);
        }
    }

    private function getSaleItemsSummary()
    {
        $authUser = require_authenticated_user();

        $sale_id = $_GET['sale_id'] ?? null;
        $product_id = $_GET['product_id'] ?? null;
        $branch_id = null;

        if ($authUser["data"]["role"] === "super-admin") {
            $branch_id = $_GET['branch_id'] ?? null;
        } else {
            $branch_id = $authUser['data']['branch_id'];
        }

        $start_date = $_GET['start_date'] ?? null;
        $end_date = $_GET['end_date'] ?? null;

        // Validate date format if provided
        if ($start_date && !$this->validateDate($start_date)) {
            $response = errorResponse("Invalid start_date format. Use YYYY-MM-DD");
            sendResponse(400, $response);
            return;
        }

        if ($end_date && !$this->validateDate($end_date)) {
            $response = errorResponse("Invalid end_date format. Use YYYY-MM-DD");
            sendResponse(400, $response);
            return;
        }

        $result = $this->salesItemModel->getSaleItemsSummary($sale_id, $product_id, $branch_id, $start_date, $end_date);

        if ($result['success']) {
            sendResponse(200, $result);
        } else {
            sendResponse(400, $result);
        }
    }

    private function getSaleItemsByProduct()
    {
        $authUser = require_authenticated_user();

        $product_id = $_GET['product_id'] ?? null;
        if (empty($product_id)) {
            $response = errorResponse("product_id parameter is required");
            sendResponse(400, $response);
            return;
        }

        $branch_id = null;
        if ($authUser["data"]["role"] === "super-admin") {
            $branch_id = $_GET['branch_id'] ?? null;
        } else {
            $branch_id = $authUser['data']['branch_id'];
        }

        // Since we don't have a direct method for this in the model,
        // we'll use the summary method with product_id filter
        $result = $this->salesItemModel->getSaleItemsSummary(null, $product_id, $branch_id);

        if ($result['success']) {
            sendResponse(200, $result);
        } else {
            sendResponse(400, $result);
        }
    }

    private function updateSaleItem($data)
    {
        $authUser = require_authenticated_user();

        $item_id = $data['item_id'] ?? null;
        if (empty($item_id)) {
            $response = errorResponse("item_id is required");
            sendResponse(400, $response);
            return;
        }

        // Get item details to check permissions
        $itemResult = $this->salesItemModel->getSaleItemById($item_id);
        if (!$itemResult['success']) {
            sendResponse(404, $itemResult);
            return;
        }

        $itemData = $itemResult['data'];

        // Permission checks
        if ($authUser['data']['role'] === 'staff' && $itemData['user_id'] !== $authUser['data']['id']) {
            $response = errorResponse("You can only update your own sale items");
            sendResponse(403, $response);
            return;
        }

        if (
            ($authUser['data']['role'] === 'branch-admin' || $authUser['data']['role'] === 'staff') &&
            $itemData['branch_id'] !== $authUser['data']['branch_id']
        ) {
            $response = errorResponse("You can only update items from your branch");
            sendResponse(403, $response);
            return;
        }

        // Prepare update data
        $allowedFields = ["quantity", "unit_price"];
        $updateData = array_intersect_key($data, array_flip($allowedFields));

        if (empty($updateData)) {
            $response = errorResponse("No valid fields to update");
            sendResponse(400, $response);
            return;
        }

        $result = $this->salesItemModel->updateSaleItem($item_id, $updateData);

        if ($result["success"]) {
            $response = successResponse("Sale item updated successfully");
            sendResponse(200, $response);
        } else {
            $response = errorResponse($result["message"] ?? "Update failed");
            sendResponse(400, $response);
        }
    }

    private function removeSaleItem($data)
    {
        $authUser = require_authenticated_user();

        $item_id = $data['item_id'] ?? null;
        if (empty($item_id)) {
            $response = errorResponse("item_id is required");
            sendResponse(400, $response);
            return;
        }

        // Get item details to check permissions
        $itemResult = $this->salesItemModel->getSaleItemById($item_id);
        if (!$itemResult['success']) {
            sendResponse(404, $itemResult);
            return;
        }

        $itemData = $itemResult['data'];

        // Permission checks
        if ($authUser['data']['role'] === 'staff' && $itemData['user_id'] !== $authUser['data']['id']) {
            $response = errorResponse("You can only remove your own sale items");
            sendResponse(403, $response);
            return;
        }

        if (
            ($authUser['data']['role'] === 'branch-admin' || $authUser['data']['role'] === 'staff') &&
            $itemData['branch_id'] !== $authUser['data']['branch_id']
        ) {
            $response = errorResponse("You can only remove items from your branch");
            sendResponse(403, $response);
            return;
        }

        $result = $this->salesItemModel->deleteSaleItem($item_id);

        if ($result["success"]) {
            $response = successResponse("Sale item removed successfully");
            sendResponse(200, $response);
        } else {
            $response = errorResponse($result["message"] ?? "Removal failed");
            sendResponse(400, $response);
        }
    }

    // --- Helper Methods ---
    private function validateDate($date, $format = 'Y-m-d')
    {
        $d = DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }

    private function getStatusCodeFromErrorCode($errorCode)
    {
        $statusCodes = [
            'VALIDATION_ERROR' => 400,
            'INVALID_QUANTITY' => 400,
            'INVALID_UNIT_PRICE' => 400,
            'INVALID_SALE' => 400,
            'INVALID_USER' => 400,
            'INVALID_BRANCH' => 400,
            'PRODUCT_NOT_FOUND' => 404,
            'INSUFFICIENT_STOCK' => 400,
            'ITEM_NOT_FOUND' => 404,
            'INSERT_FAILED' => 500,
            'UPDATE_FAILED' => 500,
            'DELETE_FAILED' => 500,
            'RESTORE_FAILED' => 500,
            'NO_VALID_FIELDS' => 400,
            'BATCH_PARTIAL_FAILURE' => 207
        ];

        return $statusCodes[$errorCode] ?? 400;
    }
}