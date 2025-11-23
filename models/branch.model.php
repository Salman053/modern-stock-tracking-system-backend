<?php
require_once "utils/validation_utils.php";
require_once "config/database.php";

class BranchDuesModel
{
    private $conn;
    private $table_name = "branch_dues";
    private $db;

    public function __construct()
    {
        $this->db = new Database();
        $this->conn = $this->db->getConnection();
    }

    /* --------------------------------------------------------------------
        ADD BRANCH DUE
       -------------------------------------------------------------------- */
    public function addBranchDue($data)
    {
        try {
            $this->conn->beginTransaction();

            // Validation of required fields
            $required_fields = ["branch_id", "stock_movement_id", "due_date", "total_amount", "due_type"];
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

            // Check if branch and stock movement exist
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

            // Calculate remaining amount
            $remaining_amount = $data['total_amount'] - ($data['paid_amount'] ?? 0);

            // Insert branch due
            $query = "INSERT INTO {$this->table_name} 
                     SET branch_id = :branch_id, supplier_id = :supplier_id,
                         stock_movement_id = :stock_movement_id, due_date = :due_date,
                         total_amount = :total_amount, paid_amount = :paid_amount,
                         remaining_amount = :remaining_amount,
                         due_type = :due_type, description = :description,
                         status = :status, created_at = NOW()";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":branch_id", $data['branch_id']);
            $stmt->bindValue(":supplier_id", $data['supplier_id'] ?? null);
            $stmt->bindParam(":stock_movement_id", $data['stock_movement_id']);
            $stmt->bindParam(":due_date", $data['due_date']);
            $stmt->bindParam(":total_amount", $data['total_amount']);
            $stmt->bindValue(":paid_amount", $data['paid_amount'] ?? 0);
            $stmt->bindValue(":remaining_amount", $remaining_amount);
            $stmt->bindParam(":due_type", $data['due_type']);
            $stmt->bindValue(":description", $data['description'] ?? null);
            
            // Set initial status
            $initial_status = ($data['paid_amount'] ?? 0) >= $data['total_amount'] ? 'paid' : 'pending';
            $stmt->bindValue(":status", $initial_status);

            if (!$stmt->execute()) {
                throw new Exception("Failed to create branch due");
            }

            $due_id = $this->conn->lastInsertId();

            $this->conn->commit();

            $dueData = [
                "due_id" => $due_id,
                "branch_id" => $data['branch_id'],
                "stock_movement_id" => $data['stock_movement_id'],
                "total_amount" => $data['total_amount'],
                "paid_amount" => $data['paid_amount'] ?? 0,
                "remaining_amount" => $remaining_amount,
                "due_date" => $data['due_date'],
                "due_type" => $data['due_type'],
                "status" => $initial_status
            ];

            return successResponse("Branch due created successfully", $dueData);

        } catch (Exception $e) {
            $this->conn->rollBack();
            return errorResponse(
                "Database error occurred while creating branch due", 
                ["database" => $e->getMessage()], 
                "DATABASE_EXCEPTION"
            );
        }
    }

    /* --------------------------------------------------------------------
        UPDATE BRANCH DUE
       -------------------------------------------------------------------- */
    public function updateBranchDue($due_id, $data)
    {
        try {
            $this->conn->beginTransaction();

            // Get current due
            $current_due = $this->getBranchDueById($due_id);
            if (!$current_due['success']) {
                $this->conn->rollBack();
                return $current_due;
            }

            // Build dynamic update query
            $allowedFields = ["total_amount", "paid_amount", "remaining_amount", "status", "due_date", "description"];
            $fields = [];
            $params = [":id" => $due_id];

            foreach ($data as $key => $value) {
                if (in_array($key, $allowedFields)) {
                    $fields[] = "$key = :$key";
                    $params[":$key"] = $value;
                }
            }

            if (empty($fields)) {
                $this->conn->rollBack();
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

            if (!$stmt->execute()) {
                throw new Exception("Failed to update branch due");
            }

            $this->conn->commit();
            return successResponse("Branch due updated successfully", ["due_id" => $due_id]);

        } catch (Exception $e) {
            $this->conn->rollBack();
            return errorResponse(
                "Database error occurred while updating branch due", 
                ["database" => $e->getMessage()], 
                "DATABASE_EXCEPTION"
            );
        }
    }

    /* --------------------------------------------------------------------
        CANCEL DUE
       -------------------------------------------------------------------- */
    public function cancelDue($due_id)
    {
        try {
            $query = "UPDATE {$this->table_name} 
                     SET status = 'cancelled', updated_at = NOW() 
                     WHERE id = ? AND status != 'cancelled'";

            $stmt = $this->conn->prepare($query);
            $stmt->execute([$due_id]);

            if ($stmt->rowCount() > 0) {
                return successResponse("Branch due cancelled successfully");
            } else {
                return errorResponse("Branch due not found or already cancelled", [], "DUE_NOT_FOUND");
            }

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while cancelling branch due", 
                ["database" => $e->getMessage()], 
                "DATABASE_EXCEPTION"
            );
        }
    }

    /* --------------------------------------------------------------------
        GET DUE BY STOCK MOVEMENT
       -------------------------------------------------------------------- */
    public function getDueByStockMovement($stock_movement_id)
    {
        if (empty($stock_movement_id)) {
            return errorResponse("Stock movement ID is required", [], "MISSING_STOCK_MOVEMENT_ID");
        }

        try {
            $query = "SELECT * FROM {$this->table_name} 
                     WHERE stock_movement_id = ? AND status != 'cancelled'";

            $stmt = $this->conn->prepare($query);
            $stmt->execute([$stock_movement_id]);

            $due = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($due) {
                return successResponse("Branch due retrieved successfully", $due);
            } else {
                return successResponse("No branch due found for this stock movement", []);
            }

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching branch due", 
                ["database" => $e->getMessage()], 
                "DATABASE_EXCEPTION"
            );
        }
    }

    /* --------------------------------------------------------------------
        GET BRANCH DUE BY ID
       -------------------------------------------------------------------- */
    public function getBranchDueById($due_id)
    {
        if (empty($due_id)) {
            return errorResponse("Due ID is required", [], "MISSING_DUE_ID");
        }

        try {
            $query = "SELECT * FROM {$this->table_name} WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$due_id]);

            $due = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($due) {
                return successResponse("Branch due retrieved successfully", $due);
            } else {
                return errorResponse("Branch due not found", [], "DUE_NOT_FOUND");
            }

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching branch due", 
                ["database" => $e->getMessage()], 
                "DATABASE_EXCEPTION"
            );
        }
    }

    /* --------------------------------------------------------------------
        HELPER METHODS
       -------------------------------------------------------------------- */
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