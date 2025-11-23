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
            $validPaymentMethods = ['cash', 'bank_transfer', 'cheque', 'card'];
            if (!empty($data["payment_method"]) && !in_array($data["payment_method"], $validPaymentMethods)) {
                $this->conn->rollBack();
                return errorResponse(
                    "Invalid payment method",
                    ["payment_method" => "Payment method must be cash, bank_transfer, cheque, or card"],
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

            // Validate payment amount against remaining amount
            if (!$this->validatePaymentAmount($data['due_type'], $data['due_id'], $data['amount'])) {
                $this->conn->rollBack();
                return errorResponse(
                    "Invalid payment amount",
                    ["amount" => "Payment amount exceeds the remaining due amount"],
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

            // Update the due record (increment paid_amount, decrement remaining_amount)
            if (!$this->updateDueRecord($data['due_type'], $data['due_id'], $data['amount'])) {
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
                "payment_date" => $data['payment_date'],
                "due_type" => $data['due_type'],
                "due_id" => $data['due_id'],
                "amount" => $data['amount'],
                "payment_method" => $payment_method,
                "branch_id" => $data['branch_id']
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

    /* --------------------------------------------------------------------
        UPDATE DUE PAYMENT
       -------------------------------------------------------------------- */
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

            // Check if payment exists and get current payment details
            $existingPayment = $this->getDuePaymentById($id);
            if (!$existingPayment['success']) {
                $this->conn->rollBack();
                return $existingPayment;
            }

            $currentPayment = $existingPayment['data'];

            // Validate amount if provided
            if (isset($data["amount"]) && $data["amount"] <= 0) {
                $this->conn->rollBack();
                return errorResponse(
                    "Invalid amount",
                    ["amount" => "Amount must be a positive number"],
                    "INVALID_AMOUNT"
                );
            }

            // Validate date if provided
            if (isset($data["payment_date"]) && !validation_utils::validateDate($data["payment_date"])) {
                $this->conn->rollBack();
                return errorResponse(
                    "Invalid date format",
                    ["payment_date" => "Date must be in YYYY-MM-DD format"],
                    "INVALID_DATE"
                );
            }

            // If amount is being updated, validate against due record
            if (isset($data["amount"])) {
                $amountDifference = $data["amount"] - $currentPayment["amount"];

                if ($amountDifference > 0) {
                    // Check if we can add more payment
                    if (!$this->validatePaymentAmount($currentPayment['due_type'], $currentPayment['due_id'], $amountDifference)) {
                        $this->conn->rollBack();
                        return errorResponse(
                            "Invalid payment amount",
                            ["amount" => "Updated payment amount exceeds the remaining due amount"],
                            "EXCEEDED_PAYMENT_AMOUNT"
                        );
                    }
                }

                // Update due record with the difference
                if (!$this->updateDueRecord($currentPayment['due_type'], $currentPayment['due_id'], $amountDifference)) {
                    $this->conn->rollBack();
                    return errorResponse(
                        "Failed to update due record",
                        [],
                        "DUE_UPDATE_FAILED"
                    );
                }
            }

            // Sanitize data
            $data = validation_utils::sanitizeInput($data);

            // Build dynamic update query
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
                      SET $setClause
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
                "Database error occurred while updating due payment",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    /* --------------------------------------------------------------------
        DELETE DUE PAYMENT
       -------------------------------------------------------------------- */
    public function deleteDuePayment($id)
    {
        $this->conn->beginTransaction();

        try {
            if (empty($id)) {
                $this->conn->rollBack();
                return errorResponse("Due payment ID is required", [], "MISSING_ID");
            }

            // Check if payment exists and get current payment details
            $existingPayment = $this->getDuePaymentById($id);
            if (!$existingPayment['success']) {
                $this->conn->rollBack();
                return $existingPayment;
            }

            $currentPayment = $existingPayment['data'];

            // Reverse the payment from the due record (decrement paid_amount, increment remaining_amount)
            if (!$this->reverseDueRecordUpdate($currentPayment['due_type'], $currentPayment['due_id'], $currentPayment['amount'])) {
                $this->conn->rollBack();
                return errorResponse(
                    "Failed to reverse due record update",
                    [],
                    "DUE_REVERSE_FAILED"
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

    /* --------------------------------------------------------------------
        VALIDATION HELPER METHODS
       -------------------------------------------------------------------- */
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

    private function getDueDetails($due_type, $due_id)
    {
        try {
            // Determine which table to query based on due_type
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

    private function validatePaymentAmount($due_type, $due_id, $payment_amount)
    {
        try {
            $dueDetails = $this->getDueDetails($due_type, $due_id);

            if (!$dueDetails) {
                return false;
            }

            // Check if payment amount exceeds remaining amount
            $remaining_amount = $dueDetails['remaining_amount'];

            return $payment_amount <= $remaining_amount;
        } catch (PDOException $e) {
            return false;
        }
    }

    private function updateDueRecord($due_type, $due_id, $payment_amount)
    {
        try {
            // Determine which table to update based on due_type
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

            // Update paid_amount and remaining_amount
            $query = "UPDATE {$table} 
                      SET paid_amount = paid_amount + :payment_amount,
                          remaining_amount = remaining_amount - :payment_amount,
                          status = CASE 
                              WHEN (remaining_amount - :payment_amount) <= 0 THEN 'paid'
                              ELSE 'partial'
                          END,
                          updated_at = NOW()
                      WHERE id = :due_id";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":payment_amount", $payment_amount);
            $stmt->bindParam(":due_id", $due_id);

            return $stmt->execute();
        } catch (PDOException $e) {
            return false;
        }
    }

    private function reverseDueRecordUpdate($due_type, $due_id, $payment_amount)
    {
        try {
            // Determine which table to update based on due_type
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

            // Reverse the payment (decrement paid_amount, increment remaining_amount)
            $query = "UPDATE {$table} 
                      SET paid_amount = paid_amount - :payment_amount,
                          remaining_amount = remaining_amount + :payment_amount,
                          status = CASE 
                              WHEN (paid_amount - :payment_amount) <= 0 THEN 'pending'
                              ELSE 'partial'
                          END,
                          updated_at = NOW()
                      WHERE id = :due_id";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":payment_amount", $payment_amount);
            $stmt->bindParam(":due_id", $due_id);

            return $stmt->execute();
        } catch (PDOException $e) {
            return false;
        }
    }



    public function getDuePaymentById($id)
    {
        if (empty($id)) {
            return errorResponse("Due payment ID is required", [], "MISSING_ID");
        }

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


    public function __destruct()
    {
        $this->conn = null;
    }
}
