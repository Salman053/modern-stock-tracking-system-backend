<?php
require_once "utils/validation_utils.php";
require_once "config/database.php";

class SupplierDueModel
{
    private $conn;
    private $table_name = "supplier_dues";
    private $db;

    public function __construct()
    {
        $this->db = new Database();
        $this->conn = $this->db->getConnection();
    }

    /* --------------------------------------------------------------------
        CREATE SUPPLIER DUE
       -------------------------------------------------------------------- */
    public function createSupplierDue($data)
    {
        try {
            // Start transaction
            $this->conn->beginTransaction();

            // Validation of required fields
            $required_fields = ["supplier_id", "branch_id", "stock_movement_id", "due_date", "total_amount"];
            $validateErrors = validation_utils::validateRequired($data, $required_fields);

            if (!empty($validateErrors)) {
                $this->conn->rollBack();
                return errorResponse("Validation failed", $validateErrors, "VALIDATION_ERROR");
            }

            // Validate amounts
            if ($data["total_amount"] <= 0) {
                $this->conn->rollBack();
                return errorResponse("Total amount must be positive", [], "INVALID_AMOUNT");
            }

            // Check if supplier, branch, and stock movement exist
            if (!$this->supplierExists($data['supplier_id'])) {
                $this->conn->rollBack();
                return errorResponse("Invalid supplier ID", [], "INVALID_SUPPLIER");
            }

            if (!$this->branchExists($data['branch_id'])) {
                $this->conn->rollBack();
                return errorResponse("Invalid branch ID", [], "INVALID_BRANCH");
            }

            if (!$this->stockMovementExists($data['stock_movement_id'])) {
                $this->conn->rollBack();
                return errorResponse("Invalid stock movement ID", [], "INVALID_STOCK_MOVEMENT");
            }

            // Check if due already exists for this stock movement
            if ($this->dueExistsForStockMovement($data['stock_movement_id'])) {
                $this->conn->rollBack();
                return errorResponse("Due already exists for this stock movement", [], "DUPLICATE_DUE");
            }

            // Sanitize data
            $data = validation_utils::sanitizeInput($data);

            // Insert supplier due
            $query = "INSERT INTO " . $this->table_name . " 
                     SET supplier_id = :supplier_id, branch_id = :branch_id, 
                         stock_movement_id = :stock_movement_id, due_date = :due_date,
                         total_amount = :total_amount, paid_amount = :paid_amount,
                         due_type = :due_type, description = :description,
                         created_at = NOW()";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":supplier_id", $data['supplier_id']);
            $stmt->bindParam(":branch_id", $data['branch_id']);
            $stmt->bindParam(":stock_movement_id", $data['stock_movement_id']);
            $stmt->bindParam(":due_date", $data['due_date']);
            $stmt->bindParam(":total_amount", $data['total_amount']);
            $stmt->bindValue(":paid_amount", $data['paid_amount'] ?? 0);
            $stmt->bindValue(":due_type", $data['due_type'] ?? 'purchase');
            $stmt->bindValue(":description", $data['description'] ?? null);

            if (!$stmt->execute()) {
                throw new Exception("Failed to create supplier due");
            }

            $due_id = $this->conn->lastInsertId();

            // Commit transaction
            $this->conn->commit();

            $dueData = [
                "due_id" => $due_id,
                "supplier_id" => $data['supplier_id'],
                "stock_movement_id" => $data['stock_movement_id'],
                "total_amount" => $data['total_amount'],
                "paid_amount" => $data['paid_amount'] ?? 0,
                "remaining_amount" => $data['total_amount'] - ($data['paid_amount'] ?? 0),
                "due_date" => $data['due_date'],
                "status" => ($data['paid_amount'] ?? 0) >= $data['total_amount'] ? 'paid' : 'pending'
            ];

            return successResponse("Supplier due created successfully", $dueData);

        } catch (Exception $e) {
            $this->conn->rollBack();
            return errorResponse(
                "Database error occurred while creating supplier due", 
                ["database" => $e->getMessage()], 
                "DATABASE_EXCEPTION"
            );
        }
    }

    /* --------------------------------------------------------------------
        GET SUPPLIER DUES
       -------------------------------------------------------------------- */
    public function getSupplierDues($supplier_id = null, $branch_id = null, $status = null)
    {
        try {
            $whereClause = "WHERE 1=1";
            $params = [];

            if ($supplier_id) {
                $whereClause .= " AND sd.supplier_id = :supplier_id";
                $params[":supplier_id"] = $supplier_id;
            }

            if ($branch_id) {
                $whereClause .= " AND sd.branch_id = :branch_id";
                $params[":branch_id"] = $branch_id;
            }

            if ($status) {
                $whereClause .= " AND sd.status = :status";
                $params[":status"] = $status;
            }

            $whereClause .= " AND s.status != 'archived' AND b.status = 'active'";

            $query = "SELECT sd.*, 
                         s.name as supplier_name,
                         s.phone as supplier_phone,
                         b.name as branch_name,
                         sm.movement_type,
                         sm.quantity,
                         sm.unit_price_per_meter
                      FROM {$this->table_name} sd
                      LEFT JOIN suppliers s ON sd.supplier_id = s.id
                      LEFT JOIN branches b ON sd.branch_id = b.id
                      LEFT JOIN stock_movements sm ON sd.stock_movement_id = sm.id
                      {$whereClause} 
                      ORDER BY sd.due_date ASC, sd.created_at DESC";

            $stmt = $this->conn->prepare($query);

            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            $stmt->execute();

            $dues = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return successResponse(
                "Supplier dues retrieved successfully", 
                $dues,
                ["count" => count($dues)]
            );

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching supplier dues", 
                ["database" => $e->getMessage()], 
                "DATABASE_EXCEPTION"
            );
        }
    }

    /* --------------------------------------------------------------------
        UPDATE SUPPLIER DUE PAYMENT
       -------------------------------------------------------------------- */
    public function updatePayment($due_id, $payment_amount)
    {
        try {
            // Start transaction
            $this->conn->beginTransaction();

            // Get current due
            $current_due = $this->getSupplierDueById($due_id);
            if (!$current_due['success']) {
                $this->conn->rollBack();
                return $current_due;
            }

            $due_data = $current_due['data'];

            // Validate payment amount
            if ($payment_amount <= 0) {
                $this->conn->rollBack();
                return errorResponse("Payment amount must be positive", [], "INVALID_PAYMENT_AMOUNT");
            }

            $new_paid_amount = $due_data['paid_amount'] + $payment_amount;
            $remaining_amount = $due_data['total_amount'] - $new_paid_amount;

            // Determine new status
            $new_status = 'partial';
            if ($remaining_amount <= 0) {
                $new_status = 'paid';
                $new_paid_amount = $due_data['total_amount']; // Don't overpay
            } elseif ($due_data['due_date'] < date('Y-m-d')) {
                $new_status = 'overdue';
            }   

            // Update supplier due
            $query = "UPDATE {$this->table_name} 
                      SET paid_amount = :paid_amount, status = :status, updated_at = NOW() 
                      WHERE id = :due_id";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":paid_amount", $new_paid_amount);
            $stmt->bindParam(":status", $new_status);
            $stmt->bindParam(":due_id", $due_id);

            if (!$stmt->execute()) {
                throw new Exception("Failed to update supplier due payment");
            }

            // Commit transaction
            $this->conn->commit();

            $result_data = [
                "due_id" => $due_id,
                "previous_paid" => $due_data['paid_amount'],
                "new_paid" => $new_paid_amount,
                "payment_added" => $payment_amount,
                "remaining_amount" => $remaining_amount,
                "new_status" => $new_status
            ];

            return successResponse("Supplier due payment updated successfully", $result_data);

        } catch (Exception $e) {
            $this->conn->rollBack();
            return errorResponse(
                "Database error occurred while updating supplier due payment", 
                ["database" => $e->getMessage()], 
                "DATABASE_EXCEPTION"
            );
        }
    }

    /* --------------------------------------------------------------------
        GET SUPPLIER DUE BY ID
       -------------------------------------------------------------------- */
    public function getSupplierDueById($due_id)
    {
        if (empty($due_id)) {
            return errorResponse("Due ID is required", [], "MISSING_DUE_ID");
        }

        try {
            $query = "SELECT sd.*, 
                         s.name as supplier_name,
                         b.name as branch_name,
                         sm.movement_type
                      FROM {$this->table_name} sd
                      LEFT JOIN suppliers s ON sd.supplier_id = s.id
                      LEFT JOIN branches b ON sd.branch_id = b.id
                      LEFT JOIN stock_movements sm ON sd.stock_movement_id = sm.id
                      WHERE sd.id = ?";

            $stmt = $this->conn->prepare($query);
            $stmt->execute([$due_id]);

            $due = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($due) {
                return successResponse("Supplier due retrieved successfully", $due);
            } else {
                return errorResponse("Supplier due not found", [], "DUE_NOT_FOUND");
            }

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching supplier due", 
                ["database" => $e->getMessage()], 
                "DATABASE_EXCEPTION"
            );
        }
    }

    /* --------------------------------------------------------------------
        GET SUPPLIER DUE SUMMARY
       -------------------------------------------------------------------- */
    public function getSupplierDueSummary($branch_id = null)
    {
        try {
            $whereClause = "";
            $params = [];

            if ($branch_id) {
                $whereClause = "WHERE sd.branch_id = :branch_id";
                $params[":branch_id"] = $branch_id;
            }

            $query = "SELECT 
                         COUNT(*) as total_dues,
                         SUM(sd.total_amount) as total_amount,
                         SUM(sd.paid_amount) as total_paid,
                         SUM(sd.remaining_amount) as total_remaining,
                         sd.status,
                         COUNT(*) as status_count
                      FROM {$this->table_name} sd
                      {$whereClause} 
                      GROUP BY sd.status";

            $stmt = $this->conn->prepare($query);

            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            $stmt->execute();

            $summary = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return successResponse(
                "Supplier due summary retrieved successfully", 
                $summary
            );

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching supplier due summary", 
                ["database" => $e->getMessage()], 
                "DATABASE_EXCEPTION"
            );
        }
    }

    /* --------------------------------------------------------------------
        HELPER METHODS
       -------------------------------------------------------------------- */
    private function supplierExists($supplier_id)
    {
        $query = "SELECT id FROM suppliers WHERE id = ? AND status != 'archived'";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$supplier_id]);
        return $stmt->rowCount() > 0;
    }

    private function branchExists($branch_id)
    {
        $query = "SELECT id FROM branches WHERE id = ? AND status = 'active'";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$branch_id]);
        return $stmt->rowCount() > 0;
    }

    private function stockMovementExists($stock_movement_id)
    {
        $query = "SELECT id FROM stock_movements WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$stock_movement_id]);
        return $stmt->rowCount() > 0;
    }

    private function dueExistsForStockMovement($stock_movement_id)
    {
        $query = "SELECT id FROM {$this->table_name} WHERE stock_movement_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$stock_movement_id]);
        return $stmt->rowCount() > 0;
    }

    public function __destruct()
    {
        $this->conn = null;
    }
}