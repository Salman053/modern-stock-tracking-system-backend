<?php

require_once "utils/validation_utils.php";
require_once "config/database.php";

class SalesModel
{
    private $conn;
    private $table_name = "sales";
    private $items_table = "sales_items";
    private $products_table = "products";
    private $db;

    public function __construct()
    {
        $this->db = new Database();
        $this->conn = $this->db->getConnection();
    }

    public function createSale($data)
    {
        try {
            // Validation of required fields
            $required_fields = ["user_id", "branch_id", "sale_date", "total_amount", "paid_amount"];
            $validateErrors = validation_utils::validateRequired($data, $required_fields);

            if (!empty($validateErrors)) {
                return errorResponse("Validation failed", $validateErrors, "VALIDATION_ERROR");
            }

            // Validate numeric fields
            if (!is_numeric($data["total_amount"]) || $data["total_amount"] <= 0) {
                return errorResponse(
                    "Invalid total amount",
                    ["total_amount" => "Total amount must be a positive number"],
                    "INVALID_TOTAL_AMOUNT"
                );
            }

            if (!is_numeric($data["paid_amount"]) || $data["paid_amount"] < 0) {
                return errorResponse(
                    "Invalid paid amount",
                    ["paid_amount" => "Paid amount must be a non-negative number"],
                    "INVALID_PAID_AMOUNT"
                );
            }

            if (isset($data["discount"]) && (!is_numeric($data["discount"]) || $data["discount"] < 0)) {
                return errorResponse(
                    "Invalid discount",
                    ["discount" => "Discount must be a non-negative number"],
                    "INVALID_DISCOUNT"
                );
            }

            if (isset($data["profit"]) && !is_numeric($data["profit"])) {
                return errorResponse(
                    "Invalid profit",
                    ["profit" => "Profit must be a numeric value"],
                    "INVALID_PROFIT"
                );
            }

            if (!$this->userExists($data['user_id'])) {
                return errorResponse("Invalid user ID", [], "INVALID_USER");
            }

            if (!$this->branchExists($data['branch_id'])) {
                return errorResponse("Invalid branch ID", [], "INVALID_BRANCH");
            }

            if (isset($data['customer_id']) && !$this->customerExists($data['customer_id'])) {
                return errorResponse("Invalid customer ID", [], "INVALID_CUSTOMER");
            }

            // Validate sale date format
            if (!validation_utils::validateDate($data["sale_date"])) {
                return errorResponse(
                    "Invalid sale date format",
                    ["sale_date" => "Sale date must be in YYYY-MM-DD format"],
                    "INVALID_DATE_FORMAT"
                );
            }

            $data = validation_utils::sanitizeInput($data);

            // Calculate if fully paid
            $discount = $data['discount'] ?? 0;
            $is_fully_paid = ($data['paid_amount'] >= ($data['total_amount'] - $discount));

            $query = "INSERT INTO " . $this->table_name . " 
                     SET user_id = :user_id, 
                         branch_id = :branch_id,
                         customer_id = :customer_id,
                         sale_date = :sale_date,
                         total_amount = :total_amount,
                         paid_amount = :paid_amount,
                         discount = :discount,
                         profit = :profit,
                         note = :note,
                         is_fully_paid = :is_fully_paid,
                         status = :status,
                         created_at = NOW()";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":user_id", $data['user_id']);
            $stmt->bindParam(":branch_id", $data['branch_id']);
            $customer_id = $data['customer_id'] ?? null;
            $stmt->bindParam(":customer_id", $customer_id);
            $stmt->bindParam(":sale_date", $data['sale_date']);
            $stmt->bindParam(":total_amount", $data['total_amount']);
            $stmt->bindParam(":paid_amount", $data['paid_amount']);
            $stmt->bindParam(":discount", $discount);
            $profit = $data['profit'] ?? 0;
            $stmt->bindParam(":profit", $profit);
            $note = $data['note'] ?? null;
            $stmt->bindParam(":note", $note);
            $stmt->bindParam(":is_fully_paid", $is_fully_paid, PDO::PARAM_BOOL);
            $status = $data['status'] ?? 'pending';
            $stmt->bindParam(":status", $status);

            if ($stmt->execute()) {
                $saleId = $this->conn->lastInsertId();
                $saleData = [
                    "id" => $saleId,
                    "sale_date" => $data['sale_date'],
                    "total_amount" => $data['total_amount'],
                    "paid_amount" => $data['paid_amount'],
                    "discount" => $discount,
                    "profit" => $profit,
                    "is_fully_paid" => $is_fully_paid,
                    "branch_id" => $data['branch_id'],
                    "status" => $status
                ];

                return successResponse(
                    "Sale created successfully",
                    $saleData
                );
            } else {
                return errorResponse(
                    "Failed to create sale",
                    [],
                    "INSERT_FAILED"
                );
            }

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while creating sale",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    public function createSaleWithItems($saleData, $itemsData)
    {
        try {
            $this->conn->beginTransaction();

            // Create sale
            $saleResult = $this->createSale($saleData);

            if (!$saleResult['success']) {
                $this->conn->rollBack();
                return $saleResult;
            }

            $saleId = $saleResult['data']['id'];
            $totalProfit = 0;
            $calculatedTotal = 0;

            // Add sale items
            foreach ($itemsData as $item) {
                $itemResult = $this->addSaleItem($item, $saleId);

                if (!$itemResult['success']) {
                    $this->conn->rollBack();
                    return $itemResult;
                }

                // Accumulate profit and total from items
                if (isset($itemResult['data']['item_profit'])) {
                    $totalProfit += $itemResult['data']['item_profit'];
                }
                if (isset($itemResult['data']['total'])) {
                    $calculatedTotal += $itemResult['data']['total'];
                }
            }

            // Update sale with total profit and calculated total
            $updateQuery = "UPDATE {$this->table_name} 
                           SET profit = :profit, 
                               total_amount = :total_amount 
                           WHERE id = :id";
            $updateStmt = $this->conn->prepare($updateQuery);
            $updateStmt->bindParam(":profit", $totalProfit);
            $updateStmt->bindParam(":total_amount", $calculatedTotal);
            $updateStmt->bindParam(":id", $saleId);
            $updateStmt->execute();

            // Recalculate if fully paid
            $this->recalculateIsFullyPaid($saleId);

            $this->conn->commit();

            // Get complete sale details
            $completeSale = $this->getSaleById($saleId);

            return successResponse(
                "Sale with items created successfully",
                $completeSale['data']
            );

        } catch (PDOException $e) {
            $this->conn->rollBack();
            return errorResponse(
                "Database error occurred while creating sale with items",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    public function addSaleItem($itemData, $saleId = null)
    {
        try {
            // If saleId is not provided, get it from itemData
            if ($saleId === null) {
                $saleId = $itemData['sale_id'] ?? null;
            }
            
            if (!$saleId) {
                return errorResponse("Sale ID is required", [], "MISSING_SALE_ID");
            }

            // Validate required fields
            $required_fields = ["product_id", "quantity", "unit_price"];
            $validateErrors = validation_utils::validateRequired($itemData, $required_fields);

            if (!empty($validateErrors)) {
                return errorResponse("Validation failed", $validateErrors, "VALIDATION_ERROR");
            }

            // Validate numeric fields
            if (!is_numeric($itemData["quantity"]) || $itemData["quantity"] <= 0) {
                return errorResponse(
                    "Invalid quantity",
                    ["quantity" => "Quantity must be a positive number"],
                    "INVALID_QUANTITY"
                );
            }

            if (!is_numeric($itemData["unit_price"]) || $itemData["unit_price"] < 0) {
                return errorResponse(
                    "Invalid unit price",
                    ["unit_price" => "Unit price must be a non-negative number"],
                    "INVALID_UNIT_PRICE"
                );
            }

            // Check if sale exists
            $saleCheck = $this->getSaleById($saleId, false);
            if (!$saleCheck['success']) {
                return $saleCheck;
            }

            // Check if product exists and has sufficient stock
            $productCheck = $this->checkProductStock($itemData['product_id'], $itemData['quantity'], $saleCheck['data']['branch_id']);
            if (!$productCheck['success']) {
                return $productCheck;
            }

            // Get product cost for profit calculation
            $productCost = $this->getProductCost($itemData['product_id']);
            $quantity = (float) $itemData['quantity'];
            $unitPrice = (float) $itemData['unit_price'];
            $total = $quantity * $unitPrice;
            $itemProfit = ($unitPrice - $productCost) * $quantity;

            $query = "INSERT INTO " . $this->items_table . " 
                     SET sale_id = :sale_id, 
                         product_id = :product_id,
                         user_id = :user_id,
                         branch_id = :branch_id,
                         quantity = :quantity,
                         unit_price = :unit_price,
                         total = :total,
                         created_at = NOW()";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":sale_id", $saleId);
            $stmt->bindParam(":product_id", $itemData['product_id']);
            $stmt->bindParam(":user_id", $saleCheck['data']['user_id']);
            $stmt->bindParam(":branch_id", $saleCheck['data']['branch_id']);
            $stmt->bindParam(":quantity", $quantity);
            $stmt->bindParam(":unit_price", $unitPrice);
            $stmt->bindParam(":total", $total);

            if ($stmt->execute()) {
                // Update product stock
                $this->updateProductStock($itemData['product_id'], $itemData['quantity'], $saleCheck['data']['branch_id'], 'subtract');

                $itemData = [
                    "id" => $this->conn->lastInsertId(),
                    "sale_id" => $saleId,
                    "product_id" => $itemData['product_id'],
                    "quantity" => $quantity,
                    "unit_price" => $unitPrice,
                    "total" => $total,
                    "item_profit" => $itemProfit
                ];

                return successResponse(
                    "Sale item added successfully",
                    $itemData
                );
            } else {
                return errorResponse(
                    "Failed to add sale item",
                    [],
                    "INSERT_FAILED"
                );
            }

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while adding sale item",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    public function getSales($branch_id = null, $user_id = null, $start_date = null, $end_date = null, $include_cancelled = false)
    {
        try {
            $whereClause = "WHERE 1=1";
            $params = [];

            if ($branch_id) {
                $whereClause .= " AND s.branch_id = :branch_id";
                $params[":branch_id"] = $branch_id;
            }

            if ($user_id) {
                $whereClause .= " AND s.user_id = :user_id";
                $params[":user_id"] = $user_id;
            }

            if (!$include_cancelled) {
                $whereClause .= " AND s.status != 'cancelled'";
            }

            if ($start_date) {
                $whereClause .= " AND s.sale_date >= :start_date";
                $params[":start_date"] = $start_date;
            }

            if ($end_date) {
                $whereClause .= " AND s.sale_date <= :end_date";
                $params[":end_date"] = $end_date;
            }

            $whereClause .= " AND u.status = 'active' AND b.status = 'active'";

            $query = "SELECT s.*, 
                             u.username as created_by, 
                             b.name as branch_name,
                             c.name as customer_name,
                             c.phone as customer_phone
                      FROM {$this->table_name} s
                      LEFT JOIN users u ON s.user_id = u.id
                      LEFT JOIN branches b ON s.branch_id = b.id
                      LEFT JOIN customers c ON s.customer_id = c.id AND c.status != 'archived'
                      {$whereClause} 
                      ORDER BY s.sale_date DESC, s.created_at DESC";

            $stmt = $this->conn->prepare($query);

            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            $stmt->execute();

            $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Add items to each sale if needed
            if (isset($_GET['include_items']) && $_GET['include_items'] === 'true') {
                foreach ($sales as &$sale) {
                    $itemsResult = $this->getSaleItemsBySaleId($sale['id']);
                    if ($itemsResult['success']) {
                        $sale['items'] = $itemsResult['data'];
                    }
                }
            }

            return successResponse(
                "Sales retrieved successfully",
                $sales
            );

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching sales",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    public function getSaleById($id, $include_items = true)
    {
        if (empty($id)) {
            return errorResponse("Sale ID is required", [], "MISSING_ID");
        }

        try {
            $query = "SELECT s.*, 
                             u.username as created_by, 
                             b.name as branch_name,
                             c.name as customer_name,
                             c.phone as customer_phone,
                             c.address as customer_address
                      FROM {$this->table_name} s
                      LEFT JOIN users u ON s.user_id = u.id AND u.status = 'active'
                      LEFT JOIN branches b ON s.branch_id = b.id AND b.status = 'active'
                      LEFT JOIN customers c ON s.customer_id = c.id AND c.status != 'archived'
                      WHERE s.id = ? AND s.status != 'archived'";

            $stmt = $this->conn->prepare($query);
            $stmt->execute([$id]);

            $sale = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$sale) {
                return errorResponse(
                    "Sale not found",
                    [],
                    "SALE_NOT_FOUND"
                );
            }

            // Include sale items if requested
            if ($include_items) {
                $itemsResult = $this->getSaleItemsBySaleId($id);
                if ($itemsResult['success']) {
                    $sale['items'] = $itemsResult['data'];
                }
            }

            return successResponse("Sale retrieved successfully", $sale);

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching sale",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    public function getSaleItemsBySaleId($saleId)
    {
        if (empty($saleId)) {
            return errorResponse("Sale ID is required", [], "MISSING_SALE_ID");
        }

        try {
            $query = "SELECT si.*, 
                             p.name as product_name,
                             p.sku as product_sku,
                             p.barcode as product_barcode,
                             p.unit as product_unit,
                             p.quantity as available_stock
                      FROM {$this->items_table} si
                      LEFT JOIN products p ON si.product_id = p.id AND p.status != 'archived'
                      WHERE si.sale_id = ? 
                      ORDER BY si.created_at ASC";

            $stmt = $this->conn->prepare($query);
            $stmt->execute([$saleId]);

            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return successResponse("Sale items retrieved successfully", $items);

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching sale items",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    public function getSaleItemById($itemId)
    {
        if (empty($itemId)) {
            return errorResponse("Item ID is required", [], "MISSING_ITEM_ID");
        }

        try {
            $query = "SELECT si.*, 
                             p.name as product_name,
                             p.sku as product_sku,
                             s.branch_id,
                             p.quantity as available_stock
                      FROM {$this->items_table} si
                      LEFT JOIN products p ON si.product_id = p.id
                      LEFT JOIN sales s ON si.sale_id = s.id
                      WHERE si.id = ?";

            $stmt = $this->conn->prepare($query);
            $stmt->execute([$itemId]);

            $item = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$item) {
                return errorResponse(
                    "Sale item not found",
                    [],
                    "ITEM_NOT_FOUND"
                );
            }

            return successResponse("Sale item retrieved successfully", $item);

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching sale item",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    public function updateSale($id, $data)
    {
        if (empty($id)) {
            return errorResponse("Sale ID is required", [], "MISSING_ID");
        }

        if (empty($data)) {
            return errorResponse("No data provided for update", [], "MISSING_UPDATE_DATA");
        }

        try {
            // Check if sale exists
            $existingSale = $this->getSaleById($id, false);
            if (!$existingSale['success']) {
                return $existingSale;
            }

            // Sanitize data
            $data = validation_utils::sanitizeInput($data);

            // Build dynamic update query
            $allowedFields = [
                "customer_id",
                "sale_date",
                "total_amount",
                "paid_amount",
                "discount",
                "profit",
                "note",
                "is_fully_paid",
                "status"
            ];
            $fields = [];
            $params = [":id" => $id];

            foreach ($data as $key => $value) {
                if (in_array($key, $allowedFields)) {
                    // Recalculate is_fully_paid if amount fields are being updated
                    if ($key === 'paid_amount' || $key === 'total_amount' || $key === 'discount') {
                        $fields[] = "is_fully_paid = :is_fully_paid";
                        $new_paid = $data['paid_amount'] ?? $existingSale['data']['paid_amount'];
                        $new_total = $data['total_amount'] ?? $existingSale['data']['total_amount'];
                        $new_discount = $data['discount'] ?? $existingSale['data']['discount'];
                        $is_fully_paid = ($new_paid >= ($new_total - $new_discount));
                        $params[":is_fully_paid"] = $is_fully_paid;
                    }

                    $fields[] = "$key = :$key";
                    $params[":$key"] = $value;
                }
            }

            if (empty($fields)) {
                return errorResponse("No valid fields to update", [], "NO_VALID_FIELDS");
            }

            $setClause = implode(", ", $fields);
            $query = "UPDATE {$this->table_name} 
                      SET $setClause, updated_at = NOW() 
                      WHERE id = :id";

            $stmt = $this->conn->prepare($query);

            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            if ($stmt->execute()) {
                return successResponse("Sale updated successfully");
            } else {
                return errorResponse(
                    "Failed to update sale",
                    [],
                    "UPDATE_FAILED"
                );
            }

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while updating sale",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    public function updateSaleItem($itemId, $data)
    {
        if (empty($itemId)) {
            return errorResponse("Item ID is required", [], "MISSING_ITEM_ID");
        }

        if (empty($data)) {
            return errorResponse("No data provided for update", [], "MISSING_UPDATE_DATA");
        }

        try {
            // Get existing item details
            $existingItem = $this->getSaleItemById($itemId);
            if (!$existingItem['success']) {
                return $existingItem;
            }

            // Get sale details
            $sale = $this->getSaleById($existingItem['data']['sale_id'], false);
            if (!$sale['success']) {
                return $sale;
            }

            // Check if sale is cancelled
            if ($sale['data']['status'] === 'cancelled') {
                return errorResponse("Cannot update items in a cancelled sale", [], "SALE_CANCELLED");
            }

            // Validate data
            $allowedFields = ["quantity", "unit_price"];
            $updateData = [];
            
            foreach ($data as $key => $value) {
                if (in_array($key, $allowedFields)) {
                    if ($key === 'quantity' && (!is_numeric($value) || $value <= 0)) {
                        return errorResponse("Quantity must be a positive number", [], "INVALID_QUANTITY");
                    }
                    if ($key === 'unit_price' && (!is_numeric($value) || $value < 0)) {
                        return errorResponse("Unit price must be a non-negative number", [], "INVALID_UNIT_PRICE");
                    }
                    $updateData[$key] = $value;
                }
            }

            if (empty($updateData)) {
                return errorResponse("No valid fields to update", [], "NO_VALID_FIELDS");
            }

            // Handle quantity changes
            if (isset($updateData['quantity'])) {
                $quantityDiff = $updateData['quantity'] - $existingItem['data']['quantity'];
                if ($quantityDiff != 0) {
                    $stockCheck = $this->checkProductStock(
                        $existingItem['data']['product_id'], 
                        abs($quantityDiff), 
                        $sale['data']['branch_id'],
                        $quantityDiff > 0
                    );
                    
                    if (!$stockCheck['success']) {
                        return $stockCheck;
                    }
                }
            }

            // Calculate new total
            $newQuantity = $updateData['quantity'] ?? $existingItem['data']['quantity'];
            $newUnitPrice = $updateData['unit_price'] ?? $existingItem['data']['unit_price'];
            $newTotal = $newQuantity * $newUnitPrice;

            $query = "UPDATE {$this->items_table} 
                      SET quantity = :quantity,
                          unit_price = :unit_price,
                          total = :total,
                          updated_at = NOW()
                      WHERE id = :id";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":quantity", $newQuantity);
            $stmt->bindParam(":unit_price", $newUnitPrice);
            $stmt->bindParam(":total", $newTotal);
            $stmt->bindParam(":id", $itemId);

            if ($stmt->execute()) {
                // Update product stock if quantity changed
                if (isset($quantityDiff) && $quantityDiff != 0) {
                    $this->updateProductStock(
                        $existingItem['data']['product_id'], 
                        abs($quantityDiff), 
                        $sale['data']['branch_id'],
                        $quantityDiff > 0 ? 'subtract' : 'add'
                    );
                }

                // Recalculate sale totals
                $this->recalculateSaleTotals($existingItem['data']['sale_id']);

                return successResponse("Sale item updated successfully");
            } else {
                return errorResponse(
                    "Failed to update sale item",
                    [],
                    "UPDATE_FAILED"
                );
            }

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while updating sale item",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    public function deleteSaleItem($itemId)
    {
        if (empty($itemId)) {
            return errorResponse("Item ID is required", [], "MISSING_ITEM_ID");
        }

        try {
            // Get existing item details
            $existingItem = $this->getSaleItemById($itemId);
            if (!$existingItem['success']) {
                return $existingItem;
            }

            // Get sale details
            $sale = $this->getSaleById($existingItem['data']['sale_id'], false);
            if (!$sale['success']) {
                return $sale;
            }

            // Check if sale is cancelled
            if ($sale['data']['status'] === 'cancelled') {
                return errorResponse("Cannot delete items from a cancelled sale", [], "SALE_CANCELLED");
            }

            $query = "DELETE FROM {$this->items_table} WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":id", $itemId);

            if ($stmt->execute()) {
                // Restore product stock
                $this->updateProductStock(
                    $existingItem['data']['product_id'], 
                    $existingItem['data']['quantity'], 
                    $sale['data']['branch_id'],
                    'add'
                );

                // Recalculate sale totals
                $this->recalculateSaleTotals($existingItem['data']['sale_id']);

                return successResponse("Sale item deleted successfully");
            } else {
                return errorResponse(
                    "Failed to delete sale item",
                    [],
                    "DELETE_FAILED"
                );
            }

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while deleting sale item",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    public function cancelSale($id)
    {
        if (empty($id)) {
            return errorResponse("Sale ID is required", [], "MISSING_ID");
        }

        try {
            // Check if sale exists
            $existingSale = $this->getSaleById($id, false);
            if (!$existingSale['success']) {
                return $existingSale;
            }

            // Check if already cancelled
            if ($existingSale['data']['status'] === 'cancelled') {
                return errorResponse("Sale is already cancelled", [], "ALREADY_CANCELLED");
            }

            $query = "UPDATE {$this->table_name} 
                      SET status = 'cancelled', updated_at = NOW() 
                      WHERE id = :id";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":id", $id);

            if ($stmt->execute()) {
                // Restore product quantities from sale items
                $this->restoreProductQuantities($id);

                return successResponse("Sale cancelled successfully");
            } else {
                return errorResponse(
                    "Failed to cancel sale",
                    [],
                    "UPDATE_FAILED"
                );
            }

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while cancelling sale",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    private function restoreProductQuantities($saleId)
    {
        try {
            // Get all items for this sale
            $itemsResult = $this->getSaleItemsBySaleId($saleId);
            if (!$itemsResult['success']) {
                return false;
            }

            foreach ($itemsResult['data'] as $item) {
                $this->updateProductStock(
                    $item['product_id'],
                    $item['quantity'],
                    $item['branch_id'],
                    'add'
                );
            }

            return true;

        } catch (Exception $e) {
            error_log("Error restoring product quantities: " . $e->getMessage());
            return false;
        }
    }

    private function recalculateSaleTotals($saleId)
    {
        try {
            // Get all items for this sale
            $itemsResult = $this->getSaleItemsBySaleId($saleId);
            if (!$itemsResult['success']) {
                return;
            }

            // Calculate new totals
            $newTotal = 0;
            $newProfit = 0;

            foreach ($itemsResult['data'] as $item) {
                $newTotal += $item['total'];
                
                // Calculate item profit (unit_price - cost_price) * quantity
                $costPrice = $this->getProductCost($item['product_id']);
                $itemProfit = ($item['unit_price'] - $costPrice) * $item['quantity'];
                $newProfit += $itemProfit;
            }

            // Update sale with new totals
            $updateData = [
                'total_amount' => $newTotal,
                'profit' => $newProfit
            ];

            $this->updateSale($saleId, $updateData);

        } catch (Exception $e) {
            error_log("Error recalculating sale totals: " . $e->getMessage());
        }
    }

    private function recalculateIsFullyPaid($saleId)
    {
        try {
            $query = "UPDATE {$this->table_name} 
                      SET is_fully_paid = CASE 
                          WHEN paid_amount >= (total_amount - discount) THEN 1 
                          ELSE 0 
                      END
                      WHERE id = :id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":id", $saleId);
            $stmt->execute();
            
            return true;
        } catch (Exception $e) {
            error_log("Error recalculating is_fully_paid: " . $e->getMessage());
            return false;
        }
    }

    private function checkProductStock($productId, $quantity, $branchId, $isAddition = false)
    {
        try {
            // Check if product exists and has sufficient quantity in the specific branch
            $query = "SELECT quantity, purchase_price_per_meter, sales_price_per_meter 
                      FROM {$this->products_table} 
                      WHERE id = ? AND branch_id = ? AND status = 'active'";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$productId, $branchId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                return errorResponse("Product not found in this branch", [], "PRODUCT_NOT_FOUND");
            }

            $availableStock = (float) $product['quantity'];

            if (!$isAddition && $availableStock < $quantity) {
                return errorResponse(
                    "Insufficient stock",
                    [
                        "available" => $availableStock,
                        "requested" => $quantity,
                        "product_id" => $productId
                    ],
                    "INSUFFICIENT_STOCK"
                );
            }

            return successResponse("Stock check passed", [
                "available_stock" => $availableStock,
                "purchase_price" => $product['purchase_price_per_meter'],
                "sales_price" => $product['sales_price_per_meter']
            ]);

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while checking stock",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    private function updateProductStock($productId, $quantity, $branchId, $operation = 'subtract')
    {
        try {
            $operator = $operation === 'subtract' ? '-' : '+';
            $query = "UPDATE {$this->products_table} 
                      SET quantity = quantity {$operator} :quantity,
                          updated_at = NOW()
                      WHERE id = :product_id AND branch_id = :branch_id";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":quantity", $quantity);
            $stmt->bindParam(":product_id", $productId);
            $stmt->bindParam(":branch_id", $branchId);
            $stmt->execute();

            return $stmt->rowCount() > 0;

        } catch (Exception $e) {
            error_log("Error updating product stock: " . $e->getMessage());
            return false;
        }
    }

    private function getProductCost($productId)
    {
        try {
            $query = "SELECT purchase_price_per_meter FROM {$this->products_table} WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$productId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            return $product ? (float)$product['purchase_price_per_meter'] : 0;
        } catch (Exception $e) {
            error_log("Error getting product cost: " . $e->getMessage());
            return 0;
        }
    }

    // Additional methods for getting sales summary, date range, etc.
    public function getSalesByDateRange($branch_id, $start_date, $end_date)
    {
        try {
            $query = "SELECT s.*, 
                             u.username as created_by, 
                             b.name as branch_name,
                             c.name as customer_name,
                             SUM(s.total_amount) as daily_total,
                             SUM(s.profit) as daily_profit
                      FROM {$this->table_name} s
                      LEFT JOIN users u ON s.user_id = u.id
                      LEFT JOIN branches b ON s.branch_id = b.id
                      LEFT JOIN customers c ON s.customer_id = c.id AND c.status != 'archived'
                      WHERE s.branch_id = :branch_id 
                        AND s.sale_date BETWEEN :start_date AND :end_date
                        AND s.status != 'cancelled'
                        AND u.status = 'active'
                        AND b.status = 'active'
                      GROUP BY s.sale_date, s.id
                      ORDER BY s.sale_date DESC";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":branch_id", $branch_id);
            $stmt->bindParam(":start_date", $start_date);
            $stmt->bindParam(":end_date", $end_date);
            $stmt->execute();

            $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return successResponse(
                "Sales retrieved successfully",
                $sales
            );

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching sales",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    public function getSalesSummary($branch_id = null, $start_date = null, $end_date = null)
    {
        try {
            $whereClause = "WHERE s.status != 'cancelled'";
            $params = [];

            if ($branch_id) {
                $whereClause .= " AND s.branch_id = :branch_id";
                $params[":branch_id"] = $branch_id;
            }

            if ($start_date) {
                $whereClause .= " AND s.sale_date >= :start_date";
                $params[":start_date"] = $start_date;
            }

            if ($end_date) {
                $whereClause .= " AND s.sale_date <= :end_date";
                $params[":end_date"] = $end_date;
            }

            $query = "SELECT 
                        COUNT(*) as total_sales,
                        SUM(s.total_amount) as total_revenue,
                        SUM(s.paid_amount) as total_paid,
                        SUM(s.discount) as total_discount,
                        SUM(s.profit) as total_profit,
                        AVG(s.total_amount) as average_sale,
                        MIN(s.sale_date) as first_sale_date,
                        MAX(s.sale_date) as last_sale_date
                      FROM {$this->table_name} s
                      {$whereClause}";

            $stmt = $this->conn->prepare($query);

            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            $stmt->execute();

            $summary = $stmt->fetch(PDO::FETCH_ASSOC);

            return successResponse(
                "Sales summary retrieved successfully",
                $summary
            );

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching sales summary",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    public function getSalesByBranchPaginated($branch_id, $page = 1, $limit = 10, $include_cancelled = false)
    {
        if (empty($branch_id)) {
            return errorResponse("Branch ID is required", [], "MISSING_BRANCH_ID");
        }

        try {
            $offset = ($page - 1) * $limit;

            // Count total records
            $countWhere = "WHERE s.branch_id = :branch_id";
            if (!$include_cancelled) {
                $countWhere .= " AND s.status != 'cancelled'";
            }

            $countQuery = "SELECT COUNT(*) as total FROM {$this->table_name} s {$countWhere}";

            $countStmt = $this->conn->prepare($countQuery);
            $countStmt->bindValue(":branch_id", $branch_id);
            $countStmt->execute();
            $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            $totalPages = ceil($totalCount / $limit);

            // Get paginated data
            $whereClause = "WHERE s.branch_id = :branch_id";
            if (!$include_cancelled) {
                $whereClause .= " AND s.status != 'cancelled'";
            }

            $query = "SELECT s.*, 
                             u.username as created_by, 
                             b.name as branch_name,
                             c.name as customer_name
                      FROM {$this->table_name} s
                      LEFT JOIN users u ON s.user_id = u.id AND u.status = 'active'
                      LEFT JOIN branches b ON s.branch_id = b.id AND b.status = 'active'
                      LEFT JOIN customers c ON s.customer_id = c.id AND c.status != 'archived'
                      {$whereClause} 
                      ORDER BY s.sale_date DESC, s.created_at DESC 
                      LIMIT :limit OFFSET :offset";

            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(":branch_id", $branch_id, PDO::PARAM_INT);
            $stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
            $stmt->bindValue(":offset", $offset, PDO::PARAM_INT);
            $stmt->execute();

            $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $paginationMeta = [
                'current_page' => (int) $page,
                'per_page' => (int) $limit,
                'total_sales' => (int) $totalCount,
                'total_pages' => (int) $totalPages,
                'has_next' => $page < $totalPages,
                'has_prev' => $page > 1
            ];

            return successResponse(
                "Sales retrieved successfully",
                $sales,
                $paginationMeta
            );

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching sales",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    private function userExists($user_id)
    {
        try {
            $query = "SELECT id FROM users WHERE id = ? AND status = 'active'";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$user_id]);
            return $stmt->rowCount() > 0;

        } catch (PDOException $e) {
            return false;
        }
    }

    private function branchExists($branch_id)
    {
        try {
            $query = "SELECT id FROM branches WHERE id = ? AND status = 'active'";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$branch_id]);
            return $stmt->rowCount() > 0;

        } catch (PDOException $e) {
            return false;
        }
    }

    private function customerExists($customer_id)
    {
        try {
            $query = "SELECT id FROM customers WHERE id = ? AND status != 'archived'";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$customer_id]);
            return $stmt->rowCount() > 0;

        } catch (PDOException $e) {
            return false;
        }
    }

    public function __destruct()
    {
        $this->conn = null;
    }
}