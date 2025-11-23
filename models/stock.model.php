<?php
require_once "utils/validation_utils.php";
require_once "config/database.php";

class StockModel
{
    private $conn;
    private $table_name = "stock_movements";

    public function __construct()
    {
        $db = new Database();
        $this->conn = $db->getConnection();
    }

    public function addStockMovement($data)
    {
        try {
            $this->conn->beginTransaction();

            // Validate required fields
            $required_fields = ["user_id", "branch_id", "product_id", "movement_type", "quantity", "unit_price_per_meter", "paid_amount", "date"];
            $validateErrors = validation_utils::validateRequired($data, $required_fields);

            if (!empty($validateErrors)) {
                $this->conn->rollBack();
                return errorResponse("Validation failed", $validateErrors, "VALIDATION_ERROR");
            }

            // Validate data
            $validationResult = $this->validateMovementData($data);
            if (!$validationResult['success']) {
                $this->conn->rollBack();
                return $validationResult;
            }

            // Check stock availability for outgoing movements
            $auto_update = $data['auto_update_product'] ?? true;
            if ($auto_update && in_array($data["movement_type"], ['dispatch', 'transfer_out'])) {
                $current_stock = $this->getProductQuantity($data['product_id']);
                if ($current_stock < $data["quantity"]) {
                    $this->conn->rollBack();
                    return errorResponse(
                        "Insufficient stock",
                        ["quantity" => "Available stock: {$current_stock}, Requested: {$data['quantity']}"],
                        "INSUFFICIENT_STOCK"
                    );
                }
            }

            // Calculate total amount
            $data['total_amount'] = $data['quantity'] * $data['unit_price_per_meter'];

            // Insert stock movement
            $id = $this->insertStockMovement($data);
            if (!$id) {
                throw new Exception("Failed to insert stock movement");
            }

            // Update product quantities if auto_update is enabled
            if ($auto_update) {
                $this->handleProductStockUpdate($data);
            }

            $this->conn->commit();
            
            return successResponse("Stock movement recorded successfully", [
                "id" => $id,
                "product_id" => $data['product_id'],
                "movement_type" => $data['movement_type'],
                "quantity" => $data['quantity'],
                "reference_branch_id" => $data['reference_branch_id'] ?? null,
                "auto_updated_product" => $auto_update
            ]);
        } catch (Exception $e) {
            $this->conn->rollBack();
            return errorResponse("Database error: " . $e->getMessage(), [], "DATABASE_EXCEPTION");
        }
    }

    public function updateStockMovement($id, $data)
    {
        try {
            $this->conn->beginTransaction();

            // Get existing movement
            $existing_movement = $this->getStockMovementById($id);
            if (!$existing_movement['success']) {
                $this->conn->rollBack();
                return $existing_movement;
            }

            $old_movement = $existing_movement['data'];

            // Revert previous stock updates if auto_update was enabled
            if ($old_movement['auto_update_product'] && $old_movement['status'] == 'completed') {
                $this->revertStockUpdate($old_movement);
            }

            // Calculate total amount if quantity or unit price is updated
            if (isset($data['quantity']) || isset($data['unit_price_per_meter'])) {
                $quantity = $data['quantity'] ?? $old_movement['quantity'];
                $unit_price = $data['unit_price_per_meter'] ?? $old_movement['unit_price_per_meter'];
                $data['total_amount'] = $quantity * $unit_price;
            }

            // Update movement
            $update_success = $this->updateMovement($id, $data);
            if (!$update_success) {
                throw new Exception("Failed to update stock movement");
            }

            // Apply new quantity change if auto_update is enabled
            $auto_update = $data['auto_update_product'] ?? $old_movement['auto_update_product'];
            if ($auto_update) {
                $new_movement_data = array_merge($old_movement, $data);
                $this->handleProductStockUpdate($new_movement_data);
            }

            $this->conn->commit();
            return successResponse("Stock movement updated successfully", ["id" => $id]);
        } catch (Exception $e) {
            $this->conn->rollBack();
            return errorResponse("Database error: " . $e->getMessage(), [], "DATABASE_EXCEPTION");
        }
    }

    public function cancelStockMovement($id)
    {
        try {
            $this->conn->beginTransaction();

            $movement = $this->getStockMovementById($id);
            if (!$movement['success']) {
                $this->conn->rollBack();
                return $movement;
            }

            $movement_data = $movement['data'];

            // Restore product quantity if movement was auto-updated
            if ($movement_data['auto_update_product'] && $movement_data['status'] == 'completed') {
                $this->revertStockUpdate($movement_data);
            }

            // Update status to cancelled
            $query = "UPDATE {$this->table_name} SET status = 'cancelled', updated_at = NOW() WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":id", $id);

            if (!$stmt->execute()) {
                throw new Exception("Failed to cancel stock movement");
            }

            $this->conn->commit();
            return successResponse("Stock movement cancelled successfully");
        } catch (Exception $e) {
            $this->conn->rollBack();
            return errorResponse("Database error: " . $e->getMessage(), [], "DATABASE_EXCEPTION");
        }
    }

    public function getStockMovements($branch_id = null, $product_id = null, $movement_type = null, $start_date = null, $end_date = null)
    {
        try {
            list($whereClause, $params) = $this->buildWhereClause($branch_id, $product_id, $movement_type, $start_date, $end_date);

            $query = "SELECT sm.*, 
                         u.username as created_by, 
                         b.name as branch_name,
                         rb.name as reference_branch_name,
                         p.name as product_name,
                         p.type as product_type,
                         s.name as supplier_name
                      FROM {$this->table_name} sm
                      LEFT JOIN users u ON sm.user_id = u.id
                      LEFT JOIN branches b ON sm.branch_id = b.id
                      LEFT JOIN branches rb ON sm.reference_branch_id = rb.id
                      LEFT JOIN products p ON sm.product_id = p.id
                      LEFT JOIN suppliers s ON sm.supplier_id = s.id
                      {$whereClause} 
                      ORDER BY sm.date DESC, sm.created_at DESC";

            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);

            $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return successResponse("Stock movements retrieved successfully", $movements, ["count" => count($movements)]);
        } catch (PDOException $e) {
            return errorResponse("Database error: " . $e->getMessage(), [], "DATABASE_EXCEPTION");
        }
    }

    public function getStockMovementById($id)
    {
        if (empty($id)) {
            return errorResponse("Movement ID is required", [], "MISSING_ID");
        }

        try {
            $query = "SELECT sm.*, 
                         u.username as created_by, 
                         b.name as branch_name,
                         rb.name as reference_branch_name,
                         p.name as product_name,
                         p.type as product_type,
                         s.name as supplier_name
                      FROM {$this->table_name} sm
                      LEFT JOIN users u ON sm.user_id = u.id
                      LEFT JOIN branches b ON sm.branch_id = b.id
                      LEFT JOIN branches rb ON sm.reference_branch_id = rb.id
                      LEFT JOIN products p ON sm.product_id = p.id
                      LEFT JOIN suppliers s ON sm.supplier_id = s.id
                      WHERE sm.id = ?";

            $stmt = $this->conn->prepare($query);
            $stmt->execute([$id]);

            $movement = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($movement) {
                return successResponse("Stock movement retrieved successfully", $movement);
            } else {
                return errorResponse("Stock movement not found", [], "MOVEMENT_NOT_FOUND");
            }
        } catch (PDOException $e) {
            return errorResponse("Database error: " . $e->getMessage(), [], "DATABASE_EXCEPTION");
        }
    }

    public function getCurrentStockLevels($branch_id = null)
    {
        try {
            $whereClause = "WHERE p.status != 'archived'";
            $params = [];

            if ($branch_id) {
                $whereClause .= " AND p.branch_id = ?";
                $params[] = $branch_id;
            }

            $query = "SELECT 
                         p.id as product_id,
                         p.name as product_name,
                         p.type as product_type,
                         p.quantity as current_stock,
                         b.name as branch_name,
                         b.id as branch_id
                      FROM products p
                      LEFT JOIN branches b ON p.branch_id = b.id
                      {$whereClause} 
                      ORDER BY p.name ASC";

            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);

            $stock_levels = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return successResponse("Current stock levels retrieved successfully", $stock_levels, ["count" => count($stock_levels)]);
        } catch (PDOException $e) {
            return errorResponse("Database error: " . $e->getMessage(), [], "DATABASE_EXCEPTION");
        }
    }

    public function getStockMovementSummary($product_id = null, $branch_id = null, $start_date = null, $end_date = null)
    {
        try {
            list($whereClause, $params) = $this->buildWhereClause($branch_id, $product_id, null, $start_date, $end_date);
            $whereClause = str_replace('sm.', '', $whereClause); // Remove table alias for summary query

            $query = "SELECT 
                         movement_type,
                         COUNT(*) as movement_count,
                         SUM(quantity) as total_quantity,
                         AVG(unit_price_per_meter) as avg_unit_price,
                         SUM(total_amount) as total_amount
                      FROM {$this->table_name}
                      {$whereClause} 
                      GROUP BY movement_type
                      ORDER BY total_quantity DESC";

            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);

            $summary = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return successResponse("Stock movement summary retrieved successfully", $summary, ["count" => count($summary)]);
        } catch (PDOException $e) {
            return errorResponse("Database error: " . $e->getMessage(), [], "DATABASE_EXCEPTION");
        }
    }

    // Private helper methods
    private function validateMovementData($data)
    {
        // Validate quantity
        if ($data["quantity"] <= 0) {
            return errorResponse("Invalid quantity", ["quantity" => "Quantity must be positive"], "INVALID_QUANTITY");
        }

        // Validate unit price
        if ($data["unit_price_per_meter"] < 0) {
            return errorResponse("Invalid unit price", ["unit_price_per_meter" => "Unit price cannot be negative"], "INVALID_UNIT_PRICE");
        }

        // Validate movement type
        $allowed_types = ['arrival', 'dispatch', 'transfer_in', 'transfer_out', 'adjustment'];
        if (!in_array($data["movement_type"], $allowed_types)) {
            return errorResponse("Invalid movement type", ["movement_type" => "Must be: " . implode(', ', $allowed_types)], "INVALID_MOVEMENT_TYPE");
        }

        // Validate reference_branch_id for transfers
        if (in_array($data["movement_type"], ['transfer_in', 'transfer_out']) && empty($data['reference_branch_id'])) {
            return errorResponse("Reference branch required", ["reference_branch_id" => "Reference branch is required for transfer movements"], "MISSING_REFERENCE_BRANCH");
        }

        // Validate existence of related entities
        if (!$this->entityExists('products', $data['product_id'])) {
            return errorResponse("Invalid product ID", [], "INVALID_PRODUCT");
        }

        if (!$this->entityExists('users', $data['user_id'])) {
            return errorResponse("Invalid user ID", [], "INVALID_USER");
        }

        if (!$this->entityExists('branches', $data['branch_id'])) {
            return errorResponse("Invalid branch ID", [], "INVALID_BRANCH");
        }

        if (!empty($data['reference_branch_id']) && !$this->entityExists('branches', $data['reference_branch_id'])) {
            return errorResponse("Invalid reference branch ID", [], "INVALID_REFERENCE_BRANCH");
        }

        if (!empty($data['supplier_id']) && !$this->entityExists('suppliers', $data['supplier_id'])) {
            return errorResponse("Invalid supplier ID", [], "INVALID_SUPPLIER");
        }

        return ['success' => true];
    }

    private function insertStockMovement($data)
    {
        $query = "INSERT INTO {$this->table_name} 
                 SET user_id = :user_id, branch_id = :branch_id, product_id = :product_id, 
                     movement_type = :movement_type, supplier_id = :supplier_id, 
                     reference_branch_id = :reference_branch_id,
                     quantity = :quantity, unit_price_per_meter = :unit_price_per_meter, 
                     paid_amount = :paid_amount, total_amount = :total_amount,
                     date = :date, notes = :notes,
                     auto_update_product = :auto_update_product, status = 'completed',
                     created_at = NOW()";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(":user_id", $data['user_id']);
        $stmt->bindValue(":branch_id", $data['branch_id']);
        $stmt->bindValue(":product_id", $data['product_id']);
        $stmt->bindValue(":movement_type", $data['movement_type']);
        $stmt->bindValue(":supplier_id", $data['supplier_id'] ?? null);
        $stmt->bindValue(":reference_branch_id", $data['reference_branch_id'] ?? null);
        $stmt->bindValue(":quantity", $data['quantity']);
        $stmt->bindValue(":unit_price_per_meter", $data['unit_price_per_meter']);
        $stmt->bindValue(":paid_amount", $data['paid_amount']);
        $stmt->bindValue(":total_amount", $data['total_amount']);
        $stmt->bindValue(":date", $data['date']);
        $stmt->bindValue(":notes", $data['notes'] ?? null);
        $stmt->bindValue(":auto_update_product", $data['auto_update_product'] ?? true);

        return $stmt->execute() ? $this->conn->lastInsertId() : false;
    }

    private function updateMovement($id, $data)
    {
        $allowedFields = ["movement_type", "supplier_id", "reference_branch_id", "quantity", "unit_price_per_meter", "paid_amount", "total_amount", "date", "notes", "auto_update_product"];
        $fields = [];
        $params = [":id" => $id];

        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                $fields[] = "$key = :$key";
                $params[":$key"] = $value;
            }
        }

        if (empty($fields)) {
            return false;
        }

        $setClause = implode(", ", $fields);
        $query = "UPDATE {$this->table_name} SET $setClause, updated_at = NOW() WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        return $stmt->execute();
    }

    private function handleProductStockUpdate($data)
    {
        switch ($data['movement_type']) {
            case 'arrival':
            case 'transfer_in':
                // Increase stock in current branch
                $this->updateProductStock($data['product_id'], $data['branch_id'], 'increase', $data['quantity']);
                break;
                
            case 'dispatch':
                // Decrease stock from current branch
                $this->updateProductStock($data['product_id'], $data['branch_id'], 'decrease', $data['quantity']);
                break;
                
            case 'transfer_out':
                // Decrease stock from current branch and increase in reference branch
                $this->updateProductStock($data['product_id'], $data['branch_id'], 'decrease', $data['quantity']);
                if (!empty($data['reference_branch_id'])) {
                    $this->updateProductStock($data['product_id'], $data['reference_branch_id'], 'increase', $data['quantity']);
                }
                break;
                
            case 'adjustment':
                // Set specific quantity (you might want to handle this differently)
                $this->adjustProductStock($data['product_id'], $data['branch_id'], $data['quantity']);
                break;
        }
    }

    private function revertStockUpdate($movement_data)
    {
        switch ($movement_data['movement_type']) {
            case 'arrival':
            case 'transfer_in':
                // Revert: decrease stock from current branch
                $this->updateProductStock($movement_data['product_id'], $movement_data['branch_id'], 'decrease', $movement_data['quantity']);
                break;
                
            case 'dispatch':
                // Revert: increase stock in current branch
                $this->updateProductStock($movement_data['product_id'], $movement_data['branch_id'], 'increase', $movement_data['quantity']);
                break;
                
            case 'transfer_out':
                // Revert: increase stock in current branch and decrease from reference branch
                $this->updateProductStock($movement_data['product_id'], $movement_data['branch_id'], 'increase', $movement_data['quantity']);
                if (!empty($movement_data['reference_branch_id'])) {
                    $this->updateProductStock($movement_data['product_id'], $movement_data['reference_branch_id'], 'decrease', $movement_data['quantity']);
                }
                break;
                
            case 'adjustment':
                // Revert adjustment (you might need to store original quantity)
                // This is more complex and might require additional logic
                break;
        }
    }

    private function updateProductStock($product_id, $branch_id, $operation, $quantity)
    {
        $current_stock = $this->getProductQuantityByBranch($product_id, $branch_id);
        
        switch ($operation) {
            case 'increase':
                $new_quantity = $current_stock + $quantity;
                break;
            case 'decrease':
                $new_quantity = $current_stock - $quantity;
                break;
            default:
                $new_quantity = $current_stock;
        }
        
        $query = "UPDATE products SET quantity = :quantity, updated_at = NOW() 
                  WHERE id = :product_id AND branch_id = :branch_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(":quantity", $new_quantity);
        $stmt->bindValue(":product_id", $product_id);
        $stmt->bindValue(":branch_id", $branch_id);
        return $stmt->execute();
    }

    private function adjustProductStock($product_id, $branch_id, $new_quantity)
    {
        $query = "UPDATE products SET quantity = :quantity, updated_at = NOW() 
                  WHERE id = :product_id AND branch_id = :branch_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(":quantity", $new_quantity);
        $stmt->bindValue(":product_id", $product_id);
        $stmt->bindValue(":branch_id", $branch_id);
        return $stmt->execute();
    }

    private function getProductQuantityByBranch($product_id, $branch_id)
    {
        $query = "SELECT quantity FROM products WHERE id = ? AND branch_id = ? AND status != 'archived'";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$product_id, $branch_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['quantity'] : 0;
    }

    private function buildWhereClause($branch_id, $product_id, $movement_type, $start_date, $end_date)
    {
        $whereClause = "WHERE sm.status != 'cancelled'";
        $params = [];

        if ($branch_id) {
            $whereClause .= " AND sm.branch_id = ?";
            $params[] = $branch_id;
        }

        if ($product_id) {
            $whereClause .= " AND sm.product_id = ?";
            $params[] = $product_id;
        }

        if ($movement_type) {
            $whereClause .= " AND sm.movement_type = ?";
            $params[] = $movement_type;
        }

        if ($start_date) {
            $whereClause .= " AND sm.date >= ?";
            $params[] = $start_date;
        }

        if ($end_date) {
            $whereClause .= " AND sm.date <= ?";
            $params[] = $end_date;
        }

        return [$whereClause, $params];
    }

    private function getProductQuantity($product_id)
    {
        $query = "SELECT quantity FROM products WHERE id = ? AND status != 'archived'";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$product_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['quantity'] : 0;
    }

    private function entityExists($table, $id)
    {
        $status_field = $table === 'users' ? 'active' : 'active';
        $status_check = $table === 'products' ? "status != 'archived'" : "status = '{$status_field}'";
        
        $query = "SELECT id FROM {$table} WHERE id = ? AND {$status_check}";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public function __destruct()
    {
        $this->conn = null;
    }
}