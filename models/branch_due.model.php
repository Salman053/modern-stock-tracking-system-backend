<?php
require_once "utils/validation_utils.php";
require_once "config/database.php";

class BranchDueModel
{
    private $conn;
    private $table_name = "branch_dues";
    private $db;

    public function __construct()
    {
        $this->db = new Database();
        $this->conn = $this->db->getConnection();
    }

   
    public function createBranchDue($data)
    {
        try {
            $this->conn->beginTransaction();

            $required_fields = [
                "branch_id",
                "stock_movement_id",
                "due_date",
                "total_amount"
            ];

            $validateErrors = validation_utils::validateRequired($data, $required_fields);

            if (!empty($validateErrors)) {
                $this->conn->rollBack();
                return errorResponse("Validation failed", $validateErrors, "VALIDATION_ERROR");
            }

            if ($data["total_amount"] <= 0) {
                $this->conn->rollBack();
                return errorResponse("Total amount must be positive", [], "INVALID_AMOUNT");
            }

            // Validate branch exists
            if (!$this->branchExists($data['branch_id'])) {
                $this->conn->rollBack();
                return errorResponse("Invalid branch ID", [], "INVALID_BRANCH");
            }

            if (!$this->stockTransferExists($data['stock_movement_id'])) {
                $this->conn->rollBack();
                return errorResponse("Invalid stock transfer ID", [], "INVALID_STOCK_TRANSFER");
            }

            // Check duplicate
            if ($this->dueExistsForTransfer($data['stock_movement_id'])) {
                $this->conn->rollBack();
                return errorResponse("Due already exists for this stock transfer", [], "DUPLICATE_DUE");
            }

            $data = validation_utils::sanitizeInput($data);

            // Calculate remaining amount
            $paid_amount = $data['paid_amount'] ?? 0;
            $remaining_amount = $data['total_amount'] - $paid_amount;
            
            // Determine status
            $status = $remaining_amount <= 0 ? 'paid' : 'pending';

            $query = "INSERT INTO {$this->table_name}
                      SET branch_id = :branch_id,
                          stock_movement_id = :stock_movement_id,
                          due_date = :due_date,
                          total_amount = :total_amount,
                          paid_amount = :paid_amount,
                          remaining_amount = :remaining_amount,
                          status = :status,
                          due_type = :due_type,
                          description = :description,
                          created_at = NOW(),
                          updated_at = NOW()";

            $stmt = $this->conn->prepare($query);

            $stmt->bindParam(":branch_id", $data['branch_id']);
            $stmt->bindParam(":stock_movement_id", $data['stock_movement_id']);
            $stmt->bindParam(":due_date", $data['due_date']);
            $stmt->bindParam(":total_amount", $data['total_amount']);
            $stmt->bindValue(":paid_amount", $paid_amount);
            $stmt->bindValue(":remaining_amount", $remaining_amount);
            $stmt->bindValue(":status", $status);
            $stmt->bindValue(":due_type", $data['due_type'] ?? 'branch-transfer');
            $stmt->bindValue(":description", $data['description'] ?? null);

            if (!$stmt->execute()) {
                throw new Exception("Failed to create branch due");
            }

            $due_id = $this->conn->lastInsertId();
            $this->conn->commit();

            return successResponse("Branch due created successfully", [
                "id" => $due_id,
                "branch_id" => $data['branch_id'],
                "stock_movement_id" => $data['stock_movement_id'],
                "total_amount" => $data['total_amount'],
                "paid_amount" => $paid_amount,
                "remaining_amount" => $remaining_amount,
                "status" => $status,
                "due_date" => $data['due_date']
            ]);

        } catch (Exception $e) {
            $this->conn->rollBack();
            return errorResponse("Database error while creating branch due", ["database" => $e->getMessage()], "DATABASE_EXCEPTION");
        }
    }

    /* --------------------------------------------------------------------
        GET ALL BRANCH DUES
    -------------------------------------------------------------------- */
    public function getBranchDues($branch_id = null, $status = null)
    {
        try {
            $where = "WHERE 1=1";
            $params = [];

            if ($branch_id) {
                $where .= " AND bd.branch_id = :branch_id";
                $params[":branch_id"] = $branch_id;
            }

            if ($status) {
                $where .= " AND bd.status = :status";
                $params[":status"] = $status;
            }

            $query = "SELECT bd.*,
                         b.name AS branch_name,
                         sm.product_id,
                         sm.quantity,
                         sm.unit_price_per_meter,
                         sm.movement_type,
                         p.name as product_name,
                         fb.name as from_branch_name,
                         tb.name as to_branch_name
                      FROM {$this->table_name} bd
                      LEFT JOIN branches b ON bd.branch_id = b.id
                      LEFT JOIN stock_movements sm ON bd.stock_movement_id = sm.id
                      LEFT JOIN products p ON sm.product_id = p.id
                      LEFT JOIN branches fb ON sm.branch_id = fb.id
                      LEFT JOIN branches tb ON sm.to_branch_id = tb.id
                      $where
                      ORDER BY bd.due_date ASC, bd.created_at DESC";

            $stmt = $this->conn->prepare($query);

            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }

            $stmt->execute();
            $dues = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return successResponse("Branch dues retrieved successfully", $dues, ["count" => count($dues)]);

        } catch (PDOException $e) {
            return errorResponse("Database error while fetching branch dues", ["database" => $e->getMessage()], "DATABASE_EXCEPTION");
        }
    }

    /* --------------------------------------------------------------------
        UPDATE BRANCH DUE PAYMENT
    -------------------------------------------------------------------- */
    public function updatePayment($due_id, $payment_amount)
    {
        try {
            $this->conn->beginTransaction();

            $existing = $this->getBranchDueById($due_id);
            if (!$existing['success']) {
                $this->conn->rollBack();
                return $existing;
            }

            $due = $existing['data'];

            if ($payment_amount <= 0) {
                $this->conn->rollBack();
                return errorResponse("Payment amount must be positive", [], "INVALID_PAYMENT_AMOUNT");
            }

            if ($payment_amount > $due['remaining_amount']) {
                $this->conn->rollBack();
                return errorResponse("Payment amount exceeds remaining amount", ["remaining_amount" => $due['remaining_amount']], "EXCESSIVE_PAYMENT");
            }

            $new_paid_amount = $due['paid_amount'] + $payment_amount;
            $new_remaining_amount = $due['total_amount'] - $new_paid_amount;

            // Determine new status
            $new_status = 'partial';
            if ($new_remaining_amount <= 0) {
                $new_status = 'paid';
                $new_paid_amount = $due['total_amount']; // Ensure we don't overpay
                $new_remaining_amount = 0;
            } elseif ($due['due_date'] < date('Y-m-d')) {
                $new_status = 'overdue';
            }

            $query = "UPDATE {$this->table_name}
                      SET paid_amount = :paid_amount,
                          remaining_amount = :remaining_amount,
                          status = :status,
                          updated_at = NOW()
                      WHERE id = :id";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":paid_amount", $new_paid_amount);
            $stmt->bindParam(":remaining_amount", $new_remaining_amount);
            $stmt->bindParam(":status", $new_status);
            $stmt->bindParam(":id", $due_id);

            if (!$stmt->execute()) {
                throw new Exception("Failed to update branch due payment");
            }

            $this->conn->commit();

            return successResponse("Branch due payment updated successfully", [
                "id" => $due_id,
                "paid_amount" => $new_paid_amount,
                "remaining_amount" => $new_remaining_amount,
                "status" => $new_status
            ]);

        } catch (Exception $e) {
            $this->conn->rollBack();
            return errorResponse("Database error while updating payment", ["database" => $e->getMessage()], "DATABASE_EXCEPTION");
        }
    }

    /* --------------------------------------------------------------------
        GET BRANCH DUE BY ID
    -------------------------------------------------------------------- */
    public function getBranchDueById($due_id)
    {
        try {
            $query = "SELECT bd.*,
                         b.name AS branch_name,
                         sm.product_id,
                         sm.quantity,
                         sm.unit_price_per_meter,
                         sm.movement_type,
                         p.name as product_name,
                         fb.name as from_branch_name,
                         tb.name as to_branch_name
                      FROM {$this->table_name} bd
                      LEFT JOIN branches b ON bd.branch_id = b.id
                      LEFT JOIN stock_movements sm ON bd.stock_movement_id = sm.id
                      LEFT JOIN products p ON sm.product_id = p.id
                      LEFT JOIN branches fb ON sm.branch_id = fb.id
                      LEFT JOIN branches tb ON sm.to_branch_id = tb.id
                      WHERE bd.id = ?";

            $stmt = $this->conn->prepare($query);
            $stmt->execute([$due_id]);

            $due = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($due) {
                return successResponse("Branch due retrieved successfully", $due);
            }

            return errorResponse("Branch due not found", [], "DUE_NOT_FOUND");

        } catch (PDOException $e) {
            return errorResponse("Database error while fetching branch due", ["database" => $e->getMessage()], "DATABASE_EXCEPTION");
        }
    }

    /* --------------------------------------------------------------------
        DELETE BRANCH DUE
    -------------------------------------------------------------------- */
    public function deleteBranchDue($due_id)
    {
        try {
            $this->conn->beginTransaction();

            // Check if due exists
            $existing = $this->getBranchDueById($due_id);
            if (!$existing['success']) {
                $this->conn->rollBack();
                return $existing;
            }

            // Delete related due payments first
            $this->deleteDuePaymentsForBranchDue($due_id);

            $query = "DELETE FROM {$this->table_name} WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":id", $due_id);

            if (!$stmt->execute()) {
                throw new Exception("Failed to delete branch due");
            }

            $this->conn->commit();
            return successResponse("Branch due deleted successfully");

        } catch (Exception $e) {
            $this->conn->rollBack();
            return errorResponse("Database error while deleting branch due", ["database" => $e->getMessage()], "DATABASE_EXCEPTION");
        }
    }

    /* --------------------------------------------------------------------
        SUMMARY
    -------------------------------------------------------------------- */
    public function getBranchDueSummary($branch_id = null)
    {
        try {
            $where = "WHERE 1=1";
            $params = [];

            if ($branch_id) {
                $where .= " AND bd.branch_id = :branch_id";
                $params[":branch_id"] = $branch_id;
            }

            $query = "SELECT 
                         bd.status,
                         COUNT(*) AS count,
                         SUM(bd.total_amount) AS total_amount,
                         SUM(bd.paid_amount) AS total_paid,
                         SUM(bd.remaining_amount) AS total_remaining
                      FROM {$this->table_name} bd
                      $where
                      GROUP BY bd.status
                      ORDER BY bd.status";

            $stmt = $this->conn->prepare($query);

            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }

            $stmt->execute();
            $summary = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate totals
            $grand_total = 0;
            $grand_paid = 0;
            $grand_remaining = 0;
            foreach ($summary as $item) {
                $grand_total += $item['total_amount'];
                $grand_paid += $item['total_paid'];
                $grand_remaining += $item['total_remaining'];
            }

            return successResponse("Branch due summary retrieved successfully", $summary, [
                "grand_total" => $grand_total,
                "grand_paid" => $grand_paid,
                "grand_remaining" => $grand_remaining,
                "total_count" => array_sum(array_column($summary, 'count'))
            ]);

        } catch (PDOException $e) {
            return errorResponse("Database error while fetching summary", ["database" => $e->getMessage()], "DATABASE_EXCEPTION");
        }
    }

    /* --------------------------------------------------------------------
        GET OVERDUE DUES
    -------------------------------------------------------------------- */
    public function getOverdueDues($branch_id = null)
    {
        try {
            $where = "WHERE bd.status IN ('pending', 'partial') AND bd.due_date < CURDATE()";
            $params = [];

            if ($branch_id) {
                $where .= " AND bd.branch_id = :branch_id";
                $params[":branch_id"] = $branch_id;
            }

            $query = "SELECT bd.*,
                         b.name AS branch_name,
                         sm.product_id,
                         p.name as product_name
                      FROM {$this->table_name} bd
                      LEFT JOIN branches b ON bd.branch_id = b.id
                      LEFT JOIN stock_movements sm ON bd.stock_movement_id = sm.id
                      LEFT JOIN products p ON sm.product_id = p.id
                      $where
                      ORDER BY bd.due_date ASC";

            $stmt = $this->conn->prepare($query);

            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }

            $stmt->execute();
            $overdue_dues = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return successResponse("Overdue branch dues retrieved successfully", $overdue_dues, [
                "count" => count($overdue_dues)
            ]);

        } catch (PDOException $e) {
            return errorResponse("Database error while fetching overdue dues", ["database" => $e->getMessage()], "DATABASE_EXCEPTION");
        }
    }

    /* --------------------------------------------------------------------
        HELPERS
    -------------------------------------------------------------------- */
    private function branchExists($branch_id)
    {
        $stmt = $this->conn->prepare("SELECT id FROM branches WHERE id = ? AND status = 'active'");
        $stmt->execute([$branch_id]);
        return $stmt->rowCount() > 0;
    }

    private function stockTransferExists($transfer_id)
    {
        $stmt = $this->conn->prepare("SELECT id FROM stock_movements WHERE id = ? AND movement_type IN ('transfer_in', 'transfer_out')");
        $stmt->execute([$transfer_id]);
        return $stmt->rowCount() > 0;
    }

    private function dueExistsForTransfer($transfer_id)
    {
        $stmt = $this->conn->prepare("SELECT id FROM {$this->table_name} WHERE stock_movement_id = ?");
        $stmt->execute([$transfer_id]);
        return $stmt->rowCount() > 0;
    }

    private function deleteDuePaymentsForBranchDue($branch_due_id)
    {
        try {
            $query = "DELETE FROM due_payments WHERE due_type = 'branch' AND due_id = :branch_due_id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":branch_due_id", $branch_due_id);
            return $stmt->execute();
        } catch (Exception $e) {
            throw new Exception("Failed to delete due payments: " . $e->getMessage());
        }
    }

    public function __destruct()
    {
        $this->conn = null;
    }
}