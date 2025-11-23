<?php
require_once "utils/validation_utils.php";
require_once "config/database.php";

class CustomerDueModel
{
    private $conn;
    private $table_name = "customer_dues";
    private $db;

    public function __construct()
    {
        $this->db = new Database();
        $this->conn = $this->db->getConnection();
    }

    /* ----------------------------------------------------------
        CREATE CUSTOMER DUE
    ----------------------------------------------------------- */
    public function createCustomerDue($data)
    {
        try {
            $this->conn->beginTransaction();

            $required = ["branch_id", "stock_movement_id", "due_date", "total_amount"];
            $errors = validation_utils::validateRequired($data, $required);

            if (!empty($errors)) {
                $this->conn->rollBack();
                return errorResponse("Validation failed", $errors, "VALIDATION_ERROR");
            }

            if ($data["total_amount"] <= 0) {
                $this->conn->rollBack();
                return errorResponse("Total amount must be positive", [], "INVALID_AMOUNT");
            }

            // Exists checks
            if (!$this->branchExists($data['branch_id'])) {
                $this->conn->rollBack();
                return errorResponse("Invalid branch ID", [], "INVALID_BRANCH");
            }

            if (!$this->stockMovementExists($data['stock_movement_id'])) {
                $this->conn->rollBack();
                return errorResponse("Invalid stock movement ID", [], "INVALID_STOCK_MOVEMENT");
            }

            if ($this->dueExistsForStockMovement($data['stock_movement_id'])) {
                $this->conn->rollBack();
                return errorResponse("Due already exists for this stock movement", [], "DUPLICATE_DUE");
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
            $stmt->bindValue(":due_type", $data['due_type'] ?? 'purchase');
            $stmt->bindValue(":description", $data['description'] ?? null);

            if (!$stmt->execute()) {
                throw new Exception("Failed to create customer due");
            }

            $due_id = $this->conn->lastInsertId();

            $this->conn->commit();

            return successResponse("Customer due created successfully", [
                "id" => $due_id,
                "branch_id" => $data['branch_id'],
                "stock_movement_id" => $data['stock_movement_id'],
                "total_amount" => $data['total_amount'],
                "paid_amount" => $paid_amount,
                "remaining_amount" => $remaining_amount,
                "status" => $status,
                "due_date" => $data['due_date'],
                "due_type" => $data['due_type'] ?? 'purchase'
            ]);

        } catch (Exception $e) {
            $this->conn->rollBack();
            return errorResponse("Database error creating customer due", ["database" => $e->getMessage()], "DATABASE_EXCEPTION");
        }
    }

    /* ----------------------------------------------------------
        GET CUSTOMER DUES
    ----------------------------------------------------------- */
    public function getCustomerDues($branch_id = null, $status = null)
    {
        try {
            $where = "WHERE 1=1";
            $params = [];

            if ($branch_id) {
                $where .= " AND cd.branch_id = :branch_id";
                $params[":branch_id"] = $branch_id;
            }

            if ($status) {
                $where .= " AND cd.status = :status";
                $params[":status"] = $status;
            }

            $query = "SELECT cd.*, 
                         b.name AS branch_name,
                         sm.product_id,
                         sm.quantity,
                         sm.unit_price_per_meter,
                         sm.movement_type,
                         p.name as product_name,
                         c.name as customer_name,
                         c.phone as customer_phone
                      FROM {$this->table_name} cd
                      LEFT JOIN branches b ON cd.branch_id = b.id
                      LEFT JOIN stock_movements sm ON cd.stock_movement_id = sm.id
                      LEFT JOIN products p ON sm.product_id = p.id
                      LEFT JOIN customers c ON sm.customer_id = c.id
                      {$where}
                      ORDER BY cd.due_date ASC, cd.created_at DESC";

            $stmt = $this->conn->prepare($query);

            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }

            $stmt->execute();
            $dues = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return successResponse("Customer dues retrieved successfully", $dues, ["count" => count($dues)]);

        } catch (PDOException $e) {
            return errorResponse("Database error fetching customer dues", ["database" => $e->getMessage()], "DATABASE_EXCEPTION");
        }
    }

    /* ----------------------------------------------------------
        UPDATE PAYMENT
    ----------------------------------------------------------- */
    public function updatePayment($due_id, $payment_amount)
    {
        try {
            $this->conn->beginTransaction();

            $current = $this->getCustomerDueById($due_id);
            if (!$current["success"]) {
                $this->conn->rollBack();
                return $current;
            }

            $due = $current["data"];

            if ($payment_amount <= 0) {
                $this->conn->rollBack();
                return errorResponse("Payment amount must be positive", [], "INVALID_PAYMENT");
            }

            if ($payment_amount > $due["remaining_amount"]) {
                $this->conn->rollBack();
                return errorResponse("Payment amount exceeds remaining amount", [
                    "payment_amount" => $payment_amount,
                    "remaining_amount" => $due["remaining_amount"]
                ], "EXCESSIVE_PAYMENT");
            }

            $new_paid_amount = $due["paid_amount"] + $payment_amount;
            $new_remaining_amount = $due["total_amount"] - $new_paid_amount;

            // Determine new status
            $new_status = 'partial';
            if ($new_remaining_amount <= 0) {
                $new_status = 'paid';
                $new_paid_amount = $due["total_amount"]; // Ensure we don't overpay
                $new_remaining_amount = 0;
            } elseif ($due["due_date"] < date('Y-m-d')) {
                $new_status = 'pending'; // Keep as pending if overdue
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
                throw new Exception("Failed to update customer due payment");
            }

            $this->conn->commit();

            return successResponse("Payment updated successfully", [
                "id" => $due_id,
                "paid_amount" => $new_paid_amount,
                "remaining_amount" => $new_remaining_amount,
                "status" => $new_status,
                "payment_added" => $payment_amount
            ]);

        } catch (Exception $e) {
            $this->conn->rollBack();
            return errorResponse("Database error updating payment", ["database" => $e->getMessage()], "DATABASE_EXCEPTION");
        }
    }

    /* ----------------------------------------------------------
        GET BY ID
    ----------------------------------------------------------- */
    public function getCustomerDueById($due_id)
    {
        try {
            $query = "SELECT cd.*, 
                         b.name AS branch_name,
                         sm.product_id,
                         sm.quantity,
                         sm.unit_price_per_meter,
                         sm.movement_type,
                         p.name as product_name,
                         c.name as customer_name,
                         c.phone as customer_phone
                      FROM {$this->table_name} cd
                      LEFT JOIN branches b ON cd.branch_id = b.id
                      LEFT JOIN stock_movements sm ON cd.stock_movement_id = sm.id
                      LEFT JOIN products p ON sm.product_id = p.id
                      LEFT JOIN customers c ON sm.customer_id = c.id
                      WHERE cd.id = ?";

            $stmt = $this->conn->prepare($query);
            $stmt->execute([$due_id]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($data) {
                return successResponse("Customer due retrieved successfully", $data);
            }

            return errorResponse("Customer due not found", [], "NOT_FOUND");

        } catch (PDOException $e) {
            return errorResponse("Database error fetching customer due", ["database" => $e->getMessage()], "DATABASE_EXCEPTION");
        }
    }

    /* ----------------------------------------------------------
        DELETE CUSTOMER DUE
    ----------------------------------------------------------- */
    public function deleteCustomerDue($due_id)
    {
        try {
            $this->conn->beginTransaction();

            // Check if due exists
            $existing = $this->getCustomerDueById($due_id);
            if (!$existing['success']) {
                $this->conn->rollBack();
                return $existing;
            }

            // Delete related due payments first
            $this->deleteDuePaymentsForCustomerDue($due_id);

            $query = "DELETE FROM {$this->table_name} WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":id", $due_id);

            if (!$stmt->execute()) {
                throw new Exception("Failed to delete customer due");
            }

            $this->conn->commit();
            return successResponse("Customer due deleted successfully");

        } catch (Exception $e) {
            $this->conn->rollBack();
            return errorResponse("Database error deleting customer due", ["database" => $e->getMessage()], "DATABASE_EXCEPTION");
        }
    }

    /* ----------------------------------------------------------
        SUMMARY
    ----------------------------------------------------------- */
    public function getCustomerDueSummary($branch_id = null)
    {
        try {
            $where = "WHERE 1=1";
            $params = [];

            if ($branch_id) {
                $where .= " AND branch_id = :branch_id";
                $params[":branch_id"] = $branch_id;
            }

            $query = "SELECT status,
                             COUNT(*) AS count,
                             SUM(total_amount) AS total_amount,
                             SUM(paid_amount) AS total_paid,
                             SUM(remaining_amount) AS total_remaining
                      FROM {$this->table_name}
                      {$where}
                      GROUP BY status
                      ORDER BY status";

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

            return successResponse("Customer due summary retrieved successfully", $summary, [
                "grand_total" => $grand_total,
                "grand_paid" => $grand_paid,
                "grand_remaining" => $grand_remaining,
                "total_count" => array_sum(array_column($summary, 'count'))
            ]);

        } catch (PDOException $e) {
            return errorResponse("Database error fetching summary", ["database" => $e->getMessage()], "DATABASE_EXCEPTION");
        }
    }

    /* ----------------------------------------------------------
        GET OVERDUE DUES
    ----------------------------------------------------------- */
    public function getOverdueDues($branch_id = null)
    {
        try {
            $where = "WHERE cd.status IN ('pending', 'partial') AND cd.due_date < CURDATE()";
            $params = [];

            if ($branch_id) {
                $where .= " AND cd.branch_id = :branch_id";
                $params[":branch_id"] = $branch_id;
            }

            $query = "SELECT cd.*,
                         b.name AS branch_name,
                         sm.product_id,
                         p.name as product_name,
                         c.name as customer_name
                      FROM {$this->table_name} cd
                      LEFT JOIN branches b ON cd.branch_id = b.id
                      LEFT JOIN stock_movements sm ON cd.stock_movement_id = sm.id
                      LEFT JOIN products p ON sm.product_id = p.id
                      LEFT JOIN customers c ON sm.customer_id = c.id
                      {$where}
                      ORDER BY cd.due_date ASC";

            $stmt = $this->conn->prepare($query);

            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }

            $stmt->execute();
            $overdue_dues = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return successResponse("Overdue customer dues retrieved successfully", $overdue_dues, [
                "count" => count($overdue_dues)
            ]);

        } catch (PDOException $e) {
            return errorResponse("Database error fetching overdue dues", ["database" => $e->getMessage()], "DATABASE_EXCEPTION");
        }
    }

    /* ----------------------------------------------------------
        HELPERS
    ----------------------------------------------------------- */
    private function branchExists($id)
    {
        $stmt = $this->conn->prepare("SELECT id FROM branches WHERE id = ? AND status = 'active'");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    private function stockMovementExists($id)
    {
        $stmt = $this->conn->prepare("SELECT id FROM stock_movements WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    private function dueExistsForStockMovement($stock_movement_id)
    {
        $stmt = $this->conn->prepare("SELECT id FROM {$this->table_name} WHERE stock_movement_id = ?");
        $stmt->execute([$stock_movement_id]);
        return $stmt->rowCount() > 0;
    }

    private function deleteDuePaymentsForCustomerDue($customer_due_id)
    {
        try {
            $query = "DELETE FROM due_payments WHERE due_type = 'customer' AND due_id = :customer_due_id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":customer_due_id", $customer_due_id);
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