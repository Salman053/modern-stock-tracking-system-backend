<?php

require_once "utils/validation_utils.php";
require_once "config/database.php";

class SalesItemModel
{
    private $conn;
    private $table_name = "sales_items";
    private $db;

    public function __construct()
    {
        $this->db = new Database();
        $this->conn = $this->db->getConnection();
    }
    public function addSaleItem($data)
    {
        try {
            // Validation of required fields
            $required_fields = ["sale_id", "product_id", "user_id", "branch_id", "quantity", "unit_price"];
            $validateErrors = validation_utils::validateRequired($data, $required_fields);

            if (!empty($validateErrors)) {
                return errorResponse("Validation failed", $validateErrors, "VALIDATION_ERROR");
            }

            // Validate numeric fields
            if (!is_numeric($data["quantity"]) || $data["quantity"] <= 0) {
                return errorResponse(
                    "Invalid quantity",
                    ["quantity" => "Quantity must be a positive number"],
                    "INVALID_QUANTITY"
                );
            }

            if (!is_numeric($data["unit_price"]) || $data["unit_price"] <= 0) {
                return errorResponse(
                    "Invalid unit price",
                    ["unit_price" => "Unit price must be a positive number"],
                    "INVALID_UNIT_PRICE"
                );
            }

            // Check if sale exists
            if (!$this->saleExists($data['sale_id'])) {
                return errorResponse("Invalid sale ID", [], "INVALID_SALE");
            }

            if (!$this->userExists($data['user_id'])) {
                return errorResponse("Invalid user ID", [], "INVALID_USER");
            }

            if (!$this->branchExists($data['branch_id'])) {
                return errorResponse("Invalid branch ID", [], "INVALID_BRANCH");
            }

            // Check product availability and get product details
            $productInfo = $this->getProductInfo($data['product_id'], $data['branch_id']);
            if (!$productInfo) {
                return errorResponse("Product not found or not available in this branch", [], "PRODUCT_NOT_FOUND");
            }

            // Check if enough quantity is available
            if ($productInfo['quantity'] < $data['quantity']) {
                return errorResponse(
                    "Insufficient stock",
                    [
                        "available_quantity" => $productInfo['quantity'],
                        "requested_quantity" => $data['quantity']
                    ],
                    "INSUFFICIENT_STOCK"
                );
            }

            $data = validation_utils::sanitizeInput($data);

            // Calculate total
            $total = $data['quantity'] * $data['unit_price'];

            // Calculate profit per item
            $purchase_price = $productInfo['purchase_price_per_meter'];
            $item_profit = ($data['unit_price'] - $purchase_price) * $data['quantity'];

            // Start transaction for product quantity update
            $this->conn->beginTransaction();

            try {
                // Add sale item
                $query = "INSERT INTO " . $this->table_name . " 
                         SET sale_id = :sale_id, 
                             product_id = :product_id,
                             user_id = :user_id,
                             branch_id = :branch_id,
                             quantity = :quantity,
                             unit_price = :unit_price,
                             total = :total,
                             created_at = NOW()";

                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(":sale_id", $data['sale_id']);
                $stmt->bindParam(":product_id", $data['product_id']);
                $stmt->bindParam(":user_id", $data['user_id']);
                $stmt->bindParam(":branch_id", $data['branch_id']);
                $stmt->bindParam(":quantity", $data['quantity']);
                $stmt->bindParam(":unit_price", $data['unit_price']);
                $stmt->bindParam(":total", $total);

                if (!$stmt->execute()) {
                    $this->conn->rollBack();
                    return errorResponse(
                        "Failed to add sale item",
                        [],
                        "INSERT_FAILED"
                    );
                }

                // Update product quantity
                $updateQuery = "UPDATE products 
                               SET quantity = quantity - :quantity, 
                                   updated_at = NOW() 
                               WHERE id = :product_id AND branch_id = :branch_id";

                $updateStmt = $this->conn->prepare($updateQuery);
                $updateStmt->bindParam(":quantity", $data['quantity']);
                $updateStmt->bindParam(":product_id", $data['product_id']);
                $updateStmt->bindParam(":branch_id", $data['branch_id']);

                if (!$updateStmt->execute()) {
                    $this->conn->rollBack();
                    return errorResponse(
                        "Failed to update product quantity",
                        [],
                        "UPDATE_FAILED"
                    );
                }

                $this->conn->commit();

                $itemData = [
                    "id" => $this->conn->lastInsertId(),
                    "sale_id" => $data['sale_id'],
                    "product_id" => $data['product_id'],
                    "quantity" => $data['quantity'],
                    "unit_price" => $data['unit_price'],
                    "total" => $total,
                    "item_profit" => $item_profit
                ];

                return successResponse(
                    "Sale item added successfully",
                    $itemData
                );

            } catch (PDOException $e) {
                $this->conn->rollBack();
                throw $e;
            }

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while adding sale item",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    public function getSaleItemsBySaleId($sale_id)
    {
        if (empty($sale_id)) {
            return errorResponse("Sale ID is required", [], "MISSING_SALE_ID");
        }

        try {
            $query = "SELECT si.*, 
                             p.name as product_name,
                             p.type as product_type,
                             p.company as product_company,
                             p.sales_price_per_meter as product_sales_price
                      FROM {$this->table_name} si
                      LEFT JOIN products p ON si.product_id = p.id AND p.status = 'active'
                      WHERE si.sale_id = ?
                      ORDER BY si.created_at ASC";

            $stmt = $this->conn->prepare($query);
            $stmt->execute([$sale_id]);

            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return successResponse(
                "Sale items retrieved successfully",
                $items
            );

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching sale items",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    public function getSaleItemById($id)
    {
        if (empty($id)) {
            return errorResponse("Sale item ID is required", [], "MISSING_id");
        }

        try {
            $query = "SELECT si.*, 
                             p.name as product_name,
                             p.type as product_type,
                             p.company as product_company,
                             u.username as created_by,
                             b.name as branch_name
                      FROM {$this->table_name} si
                      LEFT JOIN products p ON si.product_id = p.id
                      LEFT JOIN users u ON si.user_id = u.id AND u.status = 'active'
                      LEFT JOIN branches b ON si.branch_id = b.id AND b.status = 'active'
                      WHERE si.id = ?";

            $stmt = $this->conn->prepare($query);
            $stmt->execute([$id]);

            $item = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($item) {
                return successResponse("Sale item retrieved successfully", $item);
            } else {
                return errorResponse(
                    "Sale item not found",
                    [],
                    "ITEM_NOT_FOUND"
                );
            }

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching sale item",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    public function updateSaleItem($id, $data)
    {
        if (empty($id)) {
            return errorResponse("Sale item ID is required", [], "MISSING_id");
        }

        if (empty($data)) {
            return errorResponse("No data provided for update", [], "MISSING_UPDATE_DATA");
        }

        try {
            // Check if sale item exists
            $existingItem = $this->getSaleItemById($id);
            if (!$existingItem['success']) {
                return $existingItem;
            }

            // Sanitize data
            $data = validation_utils::sanitizeInput($data);

            // Build dynamic update query
            $allowedFields = ["quantity", "unit_price"];
            $fields = [];
            $params = [":id" => $id];

            foreach ($data as $key => $value) {
                if (in_array($key, $allowedFields)) {
                    $fields[] = "$key = :$key";
                    $params[":$key"] = $value;
                }
            }

            if (empty($fields)) {
                return errorResponse("No valid fields to update", [], "NO_VALID_FIELDS");
            }

            // Recalculate total if quantity or unit_price is updated
            if (in_array('quantity', array_keys($data)) || in_array('unit_price', array_keys($data))) {
                $new_quantity = $data['quantity'] ?? $existingItem['data']['quantity'];
                $new_unit_price = $data['unit_price'] ?? $existingItem['data']['unit_price'];
                $new_total = $new_quantity * $new_unit_price;

                $fields[] = "total = :total";
                $params[":total"] = $new_total;
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

    public function deleteSaleItem($id)
    {
        if (empty($id)) {
            return errorResponse("Sale item ID is required", [], "MISSING_id");
        }

        try {
            // Get item details before deletion
            $itemDetails = $this->getSaleItemById($id);
            if (!$itemDetails['success']) {
                return $itemDetails;
            }

            // Start transaction for restoring product quantity
            $this->conn->beginTransaction();

            try {
                // Restore product quantity
                $updateQuery = "UPDATE products 
                               SET quantity = quantity + :quantity, 
                                   updated_at = NOW() 
                               WHERE id = :product_id AND branch_id = :branch_id";

                $updateStmt = $this->conn->prepare($updateQuery);
                $updateStmt->bindParam(":quantity", $itemDetails['data']['quantity']);
                $updateStmt->bindParam(":product_id", $itemDetails['data']['product_id']);
                $updateStmt->bindParam(":branch_id", $itemDetails['data']['branch_id']);

                if (!$updateStmt->execute()) {
                    $this->conn->rollBack();
                    return errorResponse(
                        "Failed to restore product quantity",
                        [],
                        "UPDATE_FAILED"
                    );
                }

                // Delete sale item
                $query = "DELETE FROM {$this->table_name} WHERE id = :id";
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(":id", $id);

                if (!$stmt->execute()) {
                    $this->conn->rollBack();
                    return errorResponse(
                        "Failed to delete sale item",
                        [],
                        "DELETE_FAILED"
                    );
                }

                $this->conn->commit();

                return successResponse("Sale item deleted successfully");

            } catch (PDOException $e) {
                $this->conn->rollBack();
                throw $e;
            }

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while deleting sale item",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    public function restoreProductQuantities($sale_id)
    {
        try {
            // Get all items for this sale
            $itemsResult = $this->getSaleItemsBySaleId($sale_id);

            if (!$itemsResult['success']) {
                return $itemsResult;
            }

            $items = $itemsResult['data'];

            $this->conn->beginTransaction();

            foreach ($items as $item) {
                $updateQuery = "UPDATE products 
                               SET quantity = quantity + :quantity, 
                                   updated_at = NOW() 
                               WHERE id = :product_id AND branch_id = :branch_id";

                $updateStmt = $this->conn->prepare($updateQuery);
                $updateStmt->bindParam(":quantity", $item['quantity']);
                $updateStmt->bindParam(":product_id", $item['product_id']);
                $updateStmt->bindParam(":branch_id", $item['branch_id']);

                if (!$updateStmt->execute()) {
                    $this->conn->rollBack();
                    return errorResponse(
                        "Failed to restore quantity for product ID: {$item['product_id']}",
                        [],
                        "RESTORE_FAILED"
                    );
                }
            }

            $this->conn->commit();

            return successResponse("Product quantities restored successfully");

        } catch (PDOException $e) {
            $this->conn->rollBack();
            return errorResponse(
                "Database error occurred while restoring product quantities",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    public function getSaleItemsSummary($sale_id = null, $product_id = null, $branch_id = null, $start_date = null, $end_date = null)
    {
        try {
            $whereClause = "WHERE 1=1";
            $params = [];

            if ($sale_id) {
                $whereClause .= " AND si.sale_id = :sale_id";
                $params[":sale_id"] = $sale_id;
            }

            if ($product_id) {
                $whereClause .= " AND si.product_id = :product_id";
                $params[":product_id"] = $product_id;
            }

            if ($branch_id) {
                $whereClause .= " AND si.branch_id = :branch_id";
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
                        si.product_id,
                        p.name as product_name,
                        p.type as product_type,
                        SUM(si.quantity) as total_quantity_sold,
                        SUM(si.total) as total_revenue,
                        AVG(si.unit_price) as average_price,
                        COUNT(DISTINCT si.sale_id) as number_of_sales
                      FROM {$this->table_name} si
                      LEFT JOIN products p ON si.product_id = p.id
                      LEFT JOIN sales s ON si.sale_id = s.id
                      {$whereClause}
                      GROUP BY si.product_id, p.name, p.type
                      ORDER BY total_quantity_sold DESC";

            $stmt = $this->conn->prepare($query);

            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            $stmt->execute();

            $summary = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return successResponse(
                "Sale items summary retrieved successfully",
                $summary
            );

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching sale items summary",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    private function saleExists($sale_id)
    {
        try {
            $query = "SELECT id FROM sales WHERE id = ? AND status != 'cancelled'";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$sale_id]);
            return $stmt->rowCount() > 0;

        } catch (PDOException $e) {
            return false;
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

    private function getProductInfo($product_id, $branch_id)
    {
        try {
            $query = "SELECT id, name, quantity, purchase_price_per_meter, sales_price_per_meter 
                      FROM products 
                      WHERE id = ? AND branch_id = ? AND status = 'active'";

            $stmt = $this->conn->prepare($query);
            $stmt->execute([$product_id, $branch_id]);

            return $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (PDOException $e) {
            return false;
        }
    }

    public function __destruct()
    {
        $this->conn = null;
    }
}
