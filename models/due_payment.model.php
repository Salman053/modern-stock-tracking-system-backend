<?php

require_once "utils/validation_utils.php";
require_once "config/database.php";

class DuePaymentModel
{
    private $conn;
    private $table_name = "due_payments";
    private $db;

    public function __construct()
    {
        $this->db = new Database();
        $this->conn = $this->db->getConnection();
    }

    /* --------------------------------------------------------------------
        ADD DUE PAYMENT
       -------------------------------------------------------------------- */
    public function addDuePayment($data)
    {
        $this->conn->beginTransaction();

        try {
            // Validation of required fields
            $required_fields = ["payment_date", "user_id", "branch_id", "due_type", "due_id", "amount"];
            $validateErrors = validation_utils::validateRequired($data, $required_fields);

            if (!empty($validateErrors)) {
                $this->conn->rollBack();
                return errorResponse("Validation failed", $validateErrors, "VALIDATION_ERROR");
            }

            // Validate amount (must be positive)
            if ($data["amount"] <= 0) {
                $this->conn->rollBack();
                return errorResponse(
                    "Invalid amount",
                    ["amount" => "Amount must be a positive number"],
                    "INVALID_AMOUNT"
                );
            }

            // Validate date format
            if (!validation_utils::validateDate($data["payment_date"])) {
                $this->conn->rollBack();
                return errorResponse(
                    "Invalid date format",
                    ["payment_date" => "Date must be in YYYY-MM-DD format"],
                    "INVALID_DATE"
                );
            }

            // Validate due_type
            $validDueTypes = ['supplier', 'customer', 'branch'];
            if (!in_array($data["due_type"], $validDueTypes)) {
                $this->conn->rollBack();
                return errorResponse(
                    "Invalid due type",
                    ["due_type" => "Due type must be supplier, customer, or branch"],
                    "INVALID_DUE_TYPE"
                );
            }

            // Validate payment_method if provided
            $validPaymentMethods = ['cash', 'bank_transfer', 'cheque', 'card', 'digital_wallet'];
            if (!empty($data["payment_method"]) && !in_array($data["payment_method"], $validPaymentMethods)) {
                $this->conn->rollBack();
                return errorResponse(
                    "Invalid payment method",
                    ["payment_method" => "Payment method must be cash, bank_transfer, cheque, card, or digital_wallet"],
                    "INVALID_PAYMENT_METHOD"
                );
            }

            // Check if user and branch exist
            if (!$this->userExists($data['user_id'])) {
                $this->conn->rollBack();
                return errorResponse("Invalid user ID", [], "INVALID_USER");
            }

            if (!$this->branchExists($data['branch_id'])) {
                $this->conn->rollBack();
                return errorResponse("Invalid branch ID", [], "INVALID_BRANCH");
            }

            // Check if due_id exists and get due details
            $dueDetails = $this->getDueDetails($data['due_type'], $data['due_id']);
            if (!$dueDetails) {
                $this->conn->rollBack();
                return errorResponse(
                    "Invalid due reference",
                    ["due_id" => "The referenced due does not exist"],
                    "INVALID_DUE_REFERENCE"
                );
            }

            // Calculate current remaining amount
            $currentPaidAmount = floatval($dueDetails['paid_amount'] ?? 0);
            $totalAmount = floatval($dueDetails['total_amount']);
            $currentRemainingAmount = $totalAmount - $currentPaidAmount;

            // Validate payment amount against remaining amount
            $paymentAmount = floatval($data['amount']);
            if ($paymentAmount > $currentRemainingAmount) {
                $this->conn->rollBack();
                return errorResponse(
                    "Payment amount (Rs. {$paymentAmount}) exceeds the remaining due amount (Rs. {$currentRemainingAmount})",
                    ["amount" => "Payment amount (Rs. {$paymentAmount}) exceeds the remaining due amount (Rs. {$currentRemainingAmount})"],
                    "EXCEEDED_PAYMENT_AMOUNT"
                );
            }

            // Sanitize data
            $data = validation_utils::sanitizeInput($data);

            // Insert due payment
            $query = "INSERT INTO " . $this->table_name . " 
                     SET description = :description, payment_date = :payment_date, 
                         user_id = :user_id, branch_id = :branch_id, due_type = :due_type,
                         due_id = :due_id, amount = :amount, payment_method = :payment_method,
                         created_at = NOW()";

            $stmt = $this->conn->prepare($query);

            $description = $data['description'] ?? null;
            $payment_method = $data['payment_method'] ?? 'cash';

            $stmt->bindParam(":description", $description);
            $stmt->bindParam(":payment_date", $data['payment_date']);
            $stmt->bindParam(":user_id", $data['user_id']);
            $stmt->bindParam(":branch_id", $data['branch_id']);
            $stmt->bindParam(":due_type", $data['due_type']);
            $stmt->bindParam(":due_id", $data['due_id']);
            $stmt->bindParam(":amount", $data['amount']);
            $stmt->bindParam(":payment_method", $payment_method);

            if (!$stmt->execute()) {
                $this->conn->rollBack();
                return errorResponse(
                    "Failed to add due payment",
                    [],
                    "INSERT_FAILED"
                );
            }

            $paymentId = $this->conn->lastInsertId();

            // Update the due record with new paid amount and status
            $newPaidAmount = $currentPaidAmount + $paymentAmount;
            $newRemainingAmount = $totalAmount - $newPaidAmount;

            // Determine new status
            $newStatus = $this->calculateDueStatus($newPaidAmount, $totalAmount);

            if (!$this->updateDueRecord($data['due_type'], $data['due_id'], $newPaidAmount, $newRemainingAmount, $newStatus)) {
                $this->conn->rollBack();
                return errorResponse(
                    "Failed to update due record",
                    [],
                    "DUE_UPDATE_FAILED"
                );
            }

            // Commit transaction
            $this->conn->commit();

            $paymentData = [
                "id" => $paymentId,
                "description" => $description,
                "payment_date" => $data['payment_date'],
                "due_type" => $data['due_type'],
                "due_id" => $data['due_id'],
                "amount" => $data['amount'],
                "payment_method" => $payment_method,
                "user_id" => $data['user_id'],
                "branch_id" => $data['branch_id'],
                "due_status_after_payment" => $newStatus,
                "remaining_amount_after_payment" => $newRemainingAmount
            ];

            return successResponse(
                "Due payment added successfully",
                $paymentData
            );
        } catch (PDOException $e) {
            $this->conn->rollBack();
            return errorResponse(
                "Database error occurred while adding due payment",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }


    public function updateDuePayment($id, $data)
    {
        $this->conn->beginTransaction();

        try {
            if (empty($id)) {
                $this->conn->rollBack();
                return errorResponse("Due payment ID is required", [], "MISSING_ID");
            }

            if (empty($data)) {
                $this->conn->rollBack();
                return errorResponse("No data provided for update", [], "MISSING_UPDATE_DATA");
            }

            $existingPayment = $this->getDuePaymentById($id);
            if (!$existingPayment['success']) {
                $this->conn->rollBack();
                return $existingPayment;
            }

            $currentPayment = $existingPayment['data'];

            if (isset($data["amount"]) && $data["amount"] <= 0) {
                $this->conn->rollBack();
                return errorResponse(
                    "Invalid amount",
                    ["amount" => "Amount must be a positive number"],
                    "INVALID_AMOUNT"
                );
            }

            if (isset($data["payment_date"]) && !validation_utils::validateDate($data["payment_date"])) {
                $this->conn->rollBack();
                return errorResponse(
                    "Invalid date format",
                    ["payment_date" => "Date must be in YYYY-MM-DD format"],
                    "INVALID_DATE"
                );
            }

            if (isset($data["amount"])) {
                $oldAmount = floatval($currentPayment["amount"]);
                $newAmount = floatval($data["amount"]);

                if ($oldAmount != $newAmount) {
                    $dueDetails = $this->getDueDetails($currentPayment['due_type'], $currentPayment['due_id']);
                    if (!$dueDetails) {
                        $this->conn->rollBack();
                        return errorResponse(
                            "Due record not found",
                            [],
                            "DUE_NOT_FOUND"
                        );
                    }

                    $amountDifference = $newAmount - $oldAmount;

                    $currentPaidAmount = floatval($dueDetails['paid_amount'] ?? 0);
                    $totalAmount = floatval($dueDetails['total_amount']);

                    $newPaidAmount = $currentPaidAmount + $amountDifference;
                    $newRemainingAmount = $totalAmount - $newPaidAmount;

                    if ($newPaidAmount < 0) {
                        $this->conn->rollBack();
                        return errorResponse(
                            "Invalid payment amount adjustment",
                            ["amount" => "Payment adjustment would result in negative paid amount"],
                            "INVALID_AMOUNT_ADJUSTMENT"
                        );
                    }

                    if ($newPaidAmount > $totalAmount) {
                        $this->conn->rollBack();
                        return errorResponse(
                            "Invalid payment amount",
                            ["amount" => "Updated payment amount would exceed total due amount"],
                            "EXCEEDED_PAYMENT_AMOUNT"
                        );
                    }

                    $newStatus = $this->calculateDueStatus($newPaidAmount, $totalAmount);

                    if (!$this->updateDueRecord($currentPayment['due_type'], $currentPayment['due_id'], $newPaidAmount, $newRemainingAmount, $newStatus)) {
                        $this->conn->rollBack();
                        return errorResponse(
                            "Failed to update due record",
                            [],
                            "DUE_UPDATE_FAILED"
                        );
                    }
                }
            }

            $data = validation_utils::sanitizeInput($data);

            $allowedFields = ["description", "payment_date", "amount", "payment_method"];
            $fields = [];
            $params = [":id" => $id];

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
                $this->conn->rollBack();
                return errorResponse(
                    "Failed to update due payment",
                    [],
                    "UPDATE_FAILED"
                );
            }

            $this->conn->commit();
            return successResponse("Due payment updated successfully");
        } catch (PDOException $e) {
            $this->conn->rollBack();
            return errorResponse(
                $e->getMessage(),
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }
    public function deleteDuePayment($id)
    {
        $this->conn->beginTransaction();

        try {
            if (empty($id)) {
                $this->conn->rollBack();
                return errorResponse("Due payment ID is required", [], "MISSING_ID");
            }

            $existingPayment = $this->getDuePaymentById($id);
            if (!$existingPayment['success']) {
                $this->conn->rollBack();
                return $existingPayment;
            }

            $currentPayment = $existingPayment['data'];

            $dueDetails = $this->getDueDetails($currentPayment['due_type'], $currentPayment['due_id']);
            if (!$dueDetails) {
                $this->conn->rollBack();
                return errorResponse(
                    "Due record not found",
                    [],
                    "DUE_NOT_FOUND"
                );
            }

            $currentPaidAmount = floatval($dueDetails['paid_amount'] ?? 0);
            $paymentAmount = floatval($currentPayment['amount']);
            $totalAmount = floatval($dueDetails['total_amount']);

            $newPaidAmount = $currentPaidAmount - $paymentAmount;
            $newRemainingAmount = $totalAmount - $newPaidAmount;

            // Determine new status
            $newStatus = $this->calculateDueStatus($newPaidAmount, $totalAmount);

            // Update the due record
            if (!$this->updateDueRecord($currentPayment['due_type'], $currentPayment['due_id'], $newPaidAmount, $newRemainingAmount, $newStatus)) {
                $this->conn->rollBack();
                return errorResponse(
                    "Failed to update due record",
                    [],
                    "DUE_UPDATE_FAILED"
                );
            }

            $query = "DELETE FROM {$this->table_name} WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":id", $id);

            if (!$stmt->execute()) {
                $this->conn->rollBack();
                return errorResponse(
                    "Failed to delete due payment",
                    [],
                    "DELETE_FAILED"
                );
            }

            $this->conn->commit();
            return successResponse("Due payment deleted successfully");
        } catch (PDOException $e) {
            $this->conn->rollBack();
            return errorResponse(
                "Database error occurred while deleting due payment",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }
    private function calculateDueStatus($paidAmount, $totalAmount)
    {
        if ($paidAmount <= 0) {
            return 'pending';
        } elseif ($paidAmount >= $totalAmount) {
            return 'paid';
        } else {
            return 'partial';
        }
    }

    private function updateDueRecord($due_type, $due_id, $paidAmount, $remainingAmount, $status)
    {
        try {
            switch ($due_type) {
                case 'supplier':
                    $table = 'supplier_dues';
                    break;
                case 'customer':
                    $table = 'customer_dues';
                    break;
                case 'branch':
                    $table = 'branch_dues';
                    break;
                default:
                    return false;
            }

            $query = "UPDATE {$table} 
                      SET paid_amount = :paid_amount, 
                          remaining_amount = :remaining_amount,
                          status = :status,
                          updated_at = NOW()
                      WHERE id = :id";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":paid_amount", $paidAmount);
            $stmt->bindParam(":remaining_amount", $remainingAmount);
            $stmt->bindParam(":status", $status);
            $stmt->bindParam(":id", $due_id);

            return $stmt->execute();
        } catch (PDOException $e) {
            return false;
        }
    }

    private function getDueDetails($due_type, $due_id)
    {
        try {
            switch ($due_type) {
                case 'supplier':
                    $table = 'supplier_dues';
                    break;
                case 'customer':
                    $table = 'customer_dues';
                    break;
                case 'branch':
                    $table = 'branch_dues';
                    break;
                default:
                    return false;
            }

            $query = "SELECT * FROM {$table} WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$due_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return false;
        }
    }

    public function getDuePaymentById($id)
    {
        try {
            $query = "SELECT dp.*, u.username as created_by, b.name as branch_name 
                      FROM {$this->table_name} dp
                      LEFT JOIN users u ON dp.user_id = u.id AND u.status = 'active'
                      LEFT JOIN branches b ON dp.branch_id = b.id AND b.status = 'active'
                      WHERE dp.id = ? ";

            $stmt = $this->conn->prepare($query);
            $stmt->execute([$id]);

            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($payment) {
                return successResponse("Due payment retrieved successfully", $payment);
            } else {
                return errorResponse(
                    "Due payment not found",
                    [],
                    "PAYMENT_NOT_FOUND"
                );
            }
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching due payment",
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


    public function getDuePayments($branch_id = null, $user_id = null, $due_type = null, $due_id = null)
    {
        try {
            $query = "SELECT dp.*, u.username as created_by, b.name as branch_name 
                      FROM {$this->table_name} dp
                      LEFT JOIN users u ON dp.user_id = u.id AND u.status = 'active'
                      LEFT JOIN branches b ON dp.branch_id = b.id AND b.status = 'active'
                      WHERE 1=1";

            $params = [];

            if ($branch_id) {
                $query .= " AND dp.branch_id = ?";
                $params[] = $branch_id;
            }

            if ($user_id) {
                $query .= " AND dp.user_id = ?";
                $params[] = $user_id;
            }

            if ($due_type) {
                $query .= " AND dp.due_type = ?";
                $params[] = $due_type;
            }

            if ($due_id) {
                $query .= " AND dp.due_id = ?";
                $params[] = $due_id;
            }

            $query .= " ORDER BY dp.payment_date DESC, dp.created_at DESC";

            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);

            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return successResponse("Due payments retrieved successfully", $payments);
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching due payments",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }
    public function getDuePaymentsByDateRange($start_date, $end_date, $branch_id = null, $due_type = null)
    {
        try {
            if (!validation_utils::validateDate($start_date) || !validation_utils::validateDate($end_date)) {
                return errorResponse("Invalid date format", [], "INVALID_DATE");
            }

            $query = "SELECT dp.*, u.username as created_by, b.name as branch_name 
                  FROM {$this->table_name} dp
                  LEFT JOIN users u ON dp.user_id = u.id AND u.status = 'active'
                  LEFT JOIN branches b ON dp.branch_id = b.id AND b.status = 'active'
                  WHERE dp.payment_date BETWEEN ? AND ?";

            $params = [$start_date, $end_date];

            if ($branch_id) {
                $query .= " AND dp.branch_id = ?";
                $params[] = $branch_id;
            }

            if ($due_type) {
                $query .= " AND dp.due_type = ?";
                $params[] = $due_type;
            }

            $query .= " ORDER BY dp.payment_date ASC, dp.created_at ASC";

            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);

            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return successResponse("Due payments retrieved successfully", $payments);
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching due payments",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    /* --------------------------------------------------------------------
        GET DUE PAYMENTS SUMMARY
       -------------------------------------------------------------------- */
    public function getDuePaymentsSummary($branch_id = null, $start_date = null, $end_date = null)
    {
        try {
            $query = "SELECT 
                     COUNT(*) as total_payments,
                     SUM(amount) as total_amount,
                     due_type,
                     payment_method,
                     COUNT(DISTINCT due_id) as unique_dues,
                     COUNT(DISTINCT user_id) as unique_users
                  FROM {$this->table_name}
                  WHERE 1=1";

            $params = [];

            if ($branch_id) {
                $query .= " AND branch_id = ?";
                $params[] = $branch_id;
            }

            if ($start_date && $end_date) {
                if (!validation_utils::validateDate($start_date) || !validation_utils::validateDate($end_date)) {
                    return errorResponse("Invalid date format", [], "INVALID_DATE");
                }
                $query .= " AND payment_date BETWEEN ? AND ?";
                $params[] = $start_date;
                $params[] = $end_date;
            }

            $query .= " GROUP BY due_type, payment_method";

            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);

            $summary = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return successResponse("Due payments summary retrieved successfully", $summary);
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching due payments summary",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    public function __destruct()
    {
        $this->conn = null;
    }
}