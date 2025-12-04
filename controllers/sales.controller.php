<?php
require_once "models/sales.model.php";
require_once "models/sales_item.model.php";
require_once "utils/auth_utils.php";

class SalesController
{
    private $salesModel;
    private $salesItemModel;

    public function __construct()
    {
        $this->salesModel = new SalesModel();
        $this->salesItemModel = new SalesItemModel();
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

    // --- POST Request Handlers ---    
    private function handlePost($pathAction, $saleId, $itemId)
    {
        $data = json_decode(file_get_contents("php://input"), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $response = errorResponse("Invalid JSON body");
            sendResponse(400, $response);
            return;
        }

        // RESTful endpoints
        if ($pathAction === null) {
            // POST /sales
            $this->createSale($data);
        } elseif ($pathAction === 'items' && $saleId !== null) {
            // POST /sales/{id}/items
            $data['sale_id'] = $saleId;
            $this->addSaleItem($data);
        } elseif ($saleId !== null && $pathAction === 'payments') {
            // POST /sales/{id}/payments
            $data['sale_id'] = $saleId;
            $this->addPayment($data);
        } elseif ($pathAction === 'bulk' && $saleId === null) {
            // POST /sales/bulk
            $this->createSaleWithItems($data);
        } else {
            $response = errorResponse("Invalid endpoint");
            sendResponse(404, $response);
        }
    }

    // --- GET Request Handler ---
    private function handleGet($pathAction, $saleId)
    {
        // RESTful endpoints
        if ($saleId !== null && $pathAction === 'items') {
            // GET /sales/{id}/items
            $this->getSaleItems($saleId);
        } elseif ($saleId !== null && $pathAction === null) {
            // GET /sales/{id}
            $this->getSingleSale($saleId);
        } elseif ($pathAction === 'summary') {
            // GET /sales/summary
            $this->getSalesSummary();
        } elseif ($pathAction === 'date-range') {
            // GET /sales/date-range
            $this->getSalesByDateRange();
        } elseif ($pathAction === null) {
            // GET /sales
            $this->getSalesByBranchWithPagination();
        } else {
            $response = errorResponse("Resource not found");
            sendResponse(404, $response);
        }
    }

    // --- PUT Request Handler ---
    private function handlePut($pathAction, $saleId, $itemId)
    {
        $data = json_decode(file_get_contents("php://input"), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $response = errorResponse("Invalid JSON body");
            sendResponse(400, $response);
            return;
        }

        // RESTful endpoints
        if ($saleId !== null && $itemId !== null) {
            // PUT /sales/{saleId}/items/{itemId}
            $data['sale_id'] = $saleId;
            $data['item_id'] = $itemId;
            $this->updateSaleItem($data);
        } elseif ($saleId !== null && $pathAction === null) {
            // PUT /sales/{id}
            $data['sale_id'] = $saleId;
            $this->updateSale($data);
        } else {
            $response = errorResponse("Invalid endpoint");
            sendResponse(404, $response);
        }
    }

    // --- DELETE Request Handler ---
    private function handleDelete($pathAction, $saleId, $itemId)
    {
        // RESTful endpoints
        if ($saleId !== null && $itemId !== null) {
            // DELETE /sales/{saleId}/items/{itemId}
            $data = ['item_id' => $itemId];
            $this->removeSaleItem($data);
        } elseif ($saleId !== null && $pathAction === null) {
            // DELETE /sales/{id}
            $data = ['sale_id' => $saleId];
            $this->cancelSale($data);
        } else {
            $response = errorResponse("Invalid endpoint");
            sendResponse(404, $response);
        }
    }

    // --- Sale Creation Methods ---
    private function createSale($data)
    {
        $authUser = require_authenticated_user();

        // Validate required fields
        $required_fields = ["sale_date", "total_amount", "paid_amount"];
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

        // Set default values
        $data['discount'] = $data['discount'] ?? 0;
        $data['profit'] = $data['profit'] ?? 0;
        $data['note'] = $data['note'] ?? null;
        $data['customer_id'] = $data['customer_id'] ?? null;

        $result = $this->salesModel->createSale($data);

        if ($result['success']) {
            sendResponse(201, $result);
        } else {
            $statusCode = $this->getStatusCodeFromErrorCode($result['code'] ?? '');
            sendResponse($statusCode, $result);
        }
    }

    private function createSaleWithItems($data)
    {
        $authUser = require_authenticated_user();

        // Validate sale data
        if (empty($data['sale_data']) || empty($data['items'])) {
            $response = errorResponse("Sale data and items are required");
            sendResponse(400, $response);
            return;
        }

        if (!is_array($data['items']) || count($data['items']) === 0) {
            $response = errorResponse("At least one item is required");
            sendResponse(400, $response);
            return;
        }

        // Add user and branch info to sale data
        $saleData = $data['sale_data'];
        $saleData['user_id'] = $authUser['data']['id'];
        $saleData['branch_id'] = $authUser['data']['branch_id'];

        // Validate items
        foreach ($data['items'] as $index => $item) {
            if (empty($item['product_id']) || empty($item['quantity']) || empty($item['unit_price'])) {
                $response = errorResponse("Item at index {$index} missing required fields");
                sendResponse(400, $response);
                return;
            }
        }

        $result = $this->salesModel->createSaleWithItems($saleData, $data['items']);

        if ($result['success']) {
            sendResponse(201, $result);
        } else {
            $statusCode = $this->getStatusCodeFromErrorCode($result['code'] ?? '');
            sendResponse($statusCode, $result);
        }
    }

    // --- Sale Retrieval Methods ---
    private function getSingleSale($saleId)
    {
        $authUser = require_authenticated_user();

        if (empty($saleId)) {
            $response = errorResponse("Sale ID is required");
            sendResponse(400, $response);
            return;
        }

        // Check permissions
        if ($authUser['data']['role'] === 'branch-admin' || $authUser['data']['role'] === 'sales-manager') {
            $saleResult = $this->salesModel->getSaleById($saleId, false);
            if ($saleResult['success'] && $saleResult['data']['branch_id'] !== $authUser['data']['branch_id']) {
                $response = errorResponse("Access denied. You can only view sales from your branch");
                sendResponse(403, $response);
                return;
            }
        }

        $include_items = isset($_GET['include_items']) && $_GET['include_items'] === 'true';
        $result = $this->salesModel->getSaleById($saleId, $include_items);

        if ($result['success']) {
            sendResponse(200, $result);
        } else {
            $statusCode = ($result['code'] ?? null) === 'SALE_NOT_FOUND' ? 404 : 400;
            sendResponse($statusCode, $result);
        }
    }

    private function getSalesByBranchWithPagination()
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

        $include_cancelled = isset($_GET['include_cancelled']) && $_GET['include_cancelled'] === 'true';
        $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;

        if ($page < 1)
            $page = 1;
        if ($limit < 1 || $limit > 100)
            $limit = 10;

        $result = $this->salesModel->getSalesByBranchPaginated($branch_id, $page, $limit, $include_cancelled);

        if ($result['success']) {
            sendResponse(200, $result);
        } else {
            sendResponse(400, $result);
        }
    }

    private function getSalesSummary()
    {
        $authUser = require_authenticated_user();

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

        $result = $this->salesModel->getSalesSummary($branch_id, $start_date, $end_date);

        if ($result['success']) {
            sendResponse(200, $result);
        } else {
            sendResponse(400, $result);
        }
    }

    private function getSalesByDateRange()
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

        $start_date = $_GET['start_date'] ?? null;
        $end_date = $_GET['end_date'] ?? null;

        if (empty($start_date) || empty($end_date)) {
            $response = errorResponse("start_date and end_date parameters are required");
            sendResponse(400, $response);
            return;
        }

        if (!$this->validateDate($start_date) || !$this->validateDate($end_date)) {
            $response = errorResponse("Invalid date format. Use YYYY-MM-DD");
            sendResponse(400, $response);
            return;
        }

        $result = $this->salesModel->getSalesByDateRange($branch_id, $start_date, $end_date);

        if ($result['success']) {
            sendResponse(200, $result);
        } else {
            sendResponse(400, $result);
        }
    }

    private function updateSale($data)
    {
        $authUser = require_authenticated_user();

        $sale_id = $data['sale_id'] ?? null;
        if (empty($sale_id)) {
            $response = errorResponse("sale_id is required");
            sendResponse(400, $response);
            return;
        }

        // Check permissions and get sale details
        $saleResult = $this->salesModel->getSaleById($sale_id, false);
        if (!$saleResult['success']) {
            sendResponse(404, $saleResult);
            return;
        }

        $saleData = $saleResult['data'];

        // Permission checks
        if ($authUser['data']['role'] === 'sales-manager' && $saleData['user_id'] !== $authUser['data']['id']) {
            $response = errorResponse("You can only update your own sales");
            sendResponse(403, $response);
            return;
        }

        if (
            ($authUser['data']['role'] === 'branch-admin' || $authUser['data']['role'] === 'sales-manager') &&
            $saleData['branch_id'] !== $authUser['data']['branch_id']
        ) {
            $response = errorResponse("You can only update sales from your branch");
            sendResponse(403, $response);
            return;
        }

        // Check if sale is already cancelled
        if ($saleData['status'] === 'cancelled') {
            $response = errorResponse("Cannot update a cancelled sale");
            sendResponse(400, $response);
            return;
        }

        // Prepare update data
        $allowedFields = [
            "customer_id",
            "sale_date",
            "total_amount",
            "paid_amount",
            "discount",
            "profit",
            "note",
            "is_fully_paid"
        ];
        $updateData = array_intersect_key($data, array_flip($allowedFields));

        if (empty($updateData)) {
            $response = errorResponse("No valid fields to update");
            sendResponse(400, $response);
            return;
        }

        $result = $this->salesModel->updateSale($sale_id, $updateData);

        if ($result["success"]) {
            $response = successResponse("Sale updated successfully");
            sendResponse(200, $response);
        } else {
            $response = errorResponse($result["message"] ?? "Update failed");
            sendResponse(400, $response);
        }
    }

    private function addPayment($data)
    {
        $authUser = require_authenticated_user();

        $sale_id = $data['sale_id'] ?? null;
        $amount = $data['amount'] ?? null;

        if (empty($sale_id) || empty($amount)) {
            $response = errorResponse("sale_id and amount are required");
            sendResponse(400, $response);
            return;
        }

        if (!is_numeric($amount) || $amount <= 0) {
            $response = errorResponse("Amount must be a positive number");
            sendResponse(400, $response);
            return;
        }

        // Check permissions and get sale details
        $saleResult = $this->salesModel->getSaleById($sale_id, false);
        if (!$saleResult['success']) {
            sendResponse(404, $saleResult);
            return;
        }

        $saleData = $saleResult['data'];

        // Permission checks
        if (
            ($authUser['data']['role'] === 'branch-admin' || $authUser['data']['role'] === 'sales-manager') &&
            $saleData['branch_id'] !== $authUser['data']['branch_id']
        ) {
            $response = errorResponse("You can only add payments to sales from your branch");
            sendResponse(403, $response);
            return;
        }

        // Calculate new paid amount
        $new_paid_amount = $saleData['paid_amount'] + $amount;

        // Check if payment exceeds total amount after discount
        $total_after_discount = $saleData['total_amount'] - $saleData['discount'];
        if ($new_paid_amount > $total_after_discount) {
            $response = errorResponse("Payment amount exceeds the remaining balance");
            sendResponse(400, $response);
            return;
        }

        // Update sale with new payment
        $updateData = [
            'paid_amount' => $new_paid_amount
        ];

        $result = $this->salesModel->updateSale($sale_id, $updateData);

        if ($result["success"]) {
            $responseData = [
                'sale_id' => $sale_id,
                'previous_paid' => $saleData['paid_amount'],
                'new_paid' => $new_paid_amount,
                'amount_added' => $amount,
                'remaining_balance' => $total_after_discount - $new_paid_amount
            ];

            $response = successResponse("Payment added successfully", $responseData);
            sendResponse(200, $response);
        } else {
            $response = errorResponse($result["message"] ?? "Failed to add payment");
            sendResponse(400, $response);
        }
    }

    // --- Sale Cancellation Method ---
    private function cancelSale($data)
    {
        $authUser = require_authenticated_user();

        $sale_id = $data['sale_id'] ?? null;
        if (empty($sale_id)) {
            $response = errorResponse("sale_id is required");
            sendResponse(400, $response);
            return;
        }

        // Check permissions and get sale details
        $saleResult = $this->salesModel->getSaleById($sale_id, false);
        if (!$saleResult['success']) {
            sendResponse(404, $saleResult);
            return;
        }

        $saleData = $saleResult['data'];

        // Permission checks
        if ($authUser['data']['role'] === 'sales-manager' && $saleData['user_id'] !== $authUser['data']['id']) {
            $response = errorResponse("You can only cancel your own sales");
            sendResponse(403, $response);
            return;
        }

        if (
            ($authUser['data']['role'] === 'branch-admin' || $authUser['data']['role'] === 'sales-manager') &&
            $saleData['branch_id'] !== $authUser['data']['branch_id']
        ) {
            $response = errorResponse("You can only cancel sales from your branch");
            sendResponse(403, $response);
            return;
        }

        // Check if sale is already cancelled
        if ($saleData['status'] === 'cancelled') {
            $response = errorResponse("Sale is already cancelled");
            sendResponse(400, $response);
            return;
        }

        $result = $this->salesModel->cancelSale($sale_id);

        if ($result["success"]) {
            $response = successResponse("Sale cancelled successfully");
            sendResponse(200, $response);
        } else {
            $response = errorResponse($result["message"] ?? "Cancellation failed");
            sendResponse(400, $response);
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

        // Check sale exists and has permission
        $saleResult = $this->salesModel->getSaleById($data['sale_id'], false);
        if (!$saleResult['success']) {
            sendResponse(404, $saleResult);
            return;
        }

        $saleData = $saleResult['data'];

        // Permission checks
        if ($authUser['data']['role'] === 'sales-manager' && $saleData['user_id'] !== $authUser['data']['id']) {
            $response = errorResponse("You can only add items to your own sales");
            sendResponse(403, $response);
            return;
        }

        if (
            ($authUser['data']['role'] === 'branch-admin' || $authUser['data']['role'] === 'sales-manager') &&
            $saleData['branch_id'] !== $authUser['data']['branch_id']
        ) {
            $response = errorResponse("You can only add items to sales from your branch");
            sendResponse(403, $response);
            return;
        }

        // Check if sale is already cancelled
        if ($saleData['status'] === 'cancelled') {
            $response = errorResponse("Cannot add items to a cancelled sale");
            sendResponse(400, $response);
            return;
        }

        // Add user and branch info to data
        $data['user_id'] = $authUser['data']['id'];
        $data['branch_id'] = $authUser['data']['branch_id'];

        $result = $this->salesItemModel->addSaleItem($data);

        if ($result['success']) {
            // Recalculate sale total and profit
            $this->recalculateSaleTotals($data['sale_id']);

            sendResponse(201, $result);
        } else {
            $statusCode = $this->getStatusCodeFromErrorCode($result['code'] ?? '');
            sendResponse($statusCode, $result);
        }
    }

    private function getSaleItems($saleId)
    {
        $authUser = require_authenticated_user();

        if (empty($saleId)) {
            $response = errorResponse("sale_id parameter is required");
            sendResponse(400, $response);
            return;
        }

        // Check permissions
        $saleResult = $this->salesModel->getSaleById($saleId, false);
        if ($saleResult['success']) {
            $saleData = $saleResult['data'];

            if (
                ($authUser['data']['role'] === 'branch-admin' || $authUser['data']['role'] === 'sales-manager') &&
                $saleData['branch_id'] !== $authUser['data']['branch_id']
            ) {
                $response = errorResponse("Access denied. You can only view items from sales in your branch");
                sendResponse(403, $response);
                return;
            }
        }

        $result = $this->salesItemModel->getSaleItemsBySaleId($saleId);

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

        // Check sale status
        $saleResult = $this->salesModel->getSaleById($itemData['sale_id'], false);
        if ($saleResult['success'] && $saleResult['data']['status'] === 'cancelled') {
            $response = errorResponse("Cannot update items in a cancelled sale");
            sendResponse(400, $response);
            return;
        }

        // Permission checks
        if ($authUser['data']['role'] === 'sales-manager' && $itemData['user_id'] !== $authUser['data']['id']) {
            $response = errorResponse("You can only update your own sale items");
            sendResponse(403, $response);
            return;
        }

        if (
            ($authUser['data']['role'] === 'branch-admin' || $authUser['data']['role'] === 'sales-manager') &&
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
            // Recalculate sale total and profit
            $this->recalculateSaleTotals($itemData['sale_id']);

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

        // Check sale status
        $saleResult = $this->salesModel->getSaleById($itemData['sale_id'], false);
        if ($saleResult['success'] && $saleResult['data']['status'] === 'cancelled') {
            $response = errorResponse("Cannot remove items from a cancelled sale");
            sendResponse(400, $response);
            return;
        }

        // Permission checks
        if ($authUser['data']['role'] === 'sales-manager' && $itemData['user_id'] !== $authUser['data']['id']) {
            $response = errorResponse("You can only remove your own sale items");
            sendResponse(403, $response);
            return;
        }

        if (
            ($authUser['data']['role'] === 'branch-admin' || $authUser['data']['role'] === 'sales-manager') &&
            $itemData['branch_id'] !== $authUser['data']['branch_id']
        ) {
            $response = errorResponse("You can only remove items from your branch");
            sendResponse(403, $response);
            return;
        }

        $result = $this->salesItemModel->deleteSaleItem($item_id);

        if ($result["success"]) {
            // Recalculate sale total and profit
            $this->recalculateSaleTotals($itemData['sale_id']);

            $response = successResponse("Sale item removed successfully");
            sendResponse(200, $response);
        } else {
            $response = errorResponse($result["message"] ?? "Removal failed");
            sendResponse(400, $response);
        }
    }

    // --- Helper Methods ---
    private function recalculateSaleTotals($sale_id)
    {
        // Get all items for this sale
        $itemsResult = $this->salesItemModel->getSaleItemsBySaleId($sale_id);

        if (!$itemsResult['success']) {
            return;
        }

        $items = $itemsResult['data'];

        // Calculate new totals
        $new_total = 0;
        $new_profit = 0;

        foreach ($items as $item) {
            $new_total += $item['total'];

            // Calculate profit for this item
            // Note: This would need product purchase price from database
            // For now, we'll just use a placeholder calculation
            // You should implement proper profit calculation based on your business logic
        }

        // Update sale with new totals
        $updateData = [
            'total_amount' => $new_total,
            'profit' => $new_profit
        ];

        $this->salesModel->updateSale($sale_id, $updateData);
    }

    private function validateDate($date, $format = 'Y-m-d')
    {
        $d = DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }

    private function getStatusCodeFromErrorCode($errorCode)
    {
        $statusCodes = [
            'VALIDATION_ERROR' => 400,
            'INVALID_TOTAL_AMOUNT' => 400,
            'INVALID_PAID_AMOUNT' => 400,
            'INVALID_DISCOUNT' => 400,
            'INVALID_PROFIT' => 400,
            'INVALID_DATE_FORMAT' => 400,
            'INVALID_USER' => 400,
            'INVALID_BRANCH' => 400,
            'INVALID_CUSTOMER' => 400,
            'SALE_NOT_FOUND' => 404,
            'INSERT_FAILED' => 500,
            'UPDATE_FAILED' => 500,
            'INVALID_QUANTITY' => 400,
            'INVALID_UNIT_PRICE' => 400,
            'INVALID_SALE' => 400,
            'PRODUCT_NOT_FOUND' => 404,
            'INSUFFICIENT_STOCK' => 400,
            'DELETE_FAILED' => 500,
            'RESTORE_FAILED' => 500
        ];

        return $statusCodes[$errorCode] ?? 400;
    }
}