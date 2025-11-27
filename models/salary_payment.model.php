<?php
require_once "utils/validation_utils.php";
require_once "config/database.php";

class SalaryPaymentModel
{
    private $conn;
    private $table_name = "salary_payments";

    public function __construct()
    {
        $db = new Database();
        $this->conn = $db->getConnection();
    }

    /* --------------------------------------------------------------------
        CREATE SALARY PAYMENT
       -------------------------------------------------------------------- */
    public function createSalaryPayment($data)
    {
        try {
            $this->conn->beginTransaction();

            // Validate required fields
            $required_fields = ["user_id", "branch_id", "employee_id", "amount", "date"];
            $validateErrors = validation_utils::validateRequired($data, $required_fields);

            if (!empty($validateErrors)) {
                $this->conn->rollBack();
                return errorResponse("Validation failed", $validateErrors, "VALIDATION_ERROR");
            }

            // Validate data
            $validationResult = $this->validateSalaryPaymentData($data);
            if (!$validationResult['success']) {
                $this->conn->rollBack();
                return $validationResult;
            }

            // Check if payment already exists for this employee in the same month/year
            $existingPayment = $this->checkExistingPayment($data['employee_id'], $data['date']);
            if ($existingPayment) {
                $this->conn->rollBack();
                return errorResponse(
                    "Salary payment already exists for this employee for " . date('F Y', strtotime($data['date'])),
                    ["date" => "Duplicate payment found for this month"],
                    "DUPLICATE_PAYMENT"
                );
            }

            // Check if amount exceeds employee's salary
            $salaryCheck = $this->checkSalaryAmount($data['employee_id'], $data['amount']);
            if (!$salaryCheck['success']) {
                $this->conn->rollBack();
                return $salaryCheck;
            }

            // Insert salary payment
            $payment_id = $this->insertSalaryPayment($data);
            if (!$payment_id) {
                throw new Exception("Failed to create salary payment");
            }

            $this->conn->commit();

            return successResponse("Salary payment created successfully", [
                "payment_id" => $payment_id,
                "employee_id" => $data['employee_id'],
                "amount" => $data['amount'],
                "date" => $data['date']
            ]);
        } catch (Exception $e) {
            $this->conn->rollBack();
            return errorResponse("Database error: " . $e->getMessage(), [], "DATABASE_EXCEPTION");
        }
    }

    /* --------------------------------------------------------------------
        GET SALARY PAYMENTS
       -------------------------------------------------------------------- */
    public function getSalaryPayments($branch_id = null, $employee_id = null, $start_date = null, $end_date = null, $month = null, $year = null, $include_archived = false)
    {
        try {
            list($whereClause, $params) = $this->buildWhereClause($branch_id, $employee_id, $start_date, $end_date, $month, $year, $include_archived);

            $query = "SELECT sp.*, 
                         u.username as created_by,
                         b.name as branch_name,
                         e.name as employee_name,
                         e.designation as employee_designation,
                         e.salary as employee_salary,
                         e.status as employee_status
                      FROM {$this->table_name} sp
                      LEFT JOIN users u ON sp.user_id = u.id
                      LEFT JOIN branches b ON sp.branch_id = b.id
                      LEFT JOIN employees e ON sp.employee_id = e.id
                      {$whereClause} 
                      ORDER BY sp.date DESC, sp.created_at DESC";

            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);

            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate summary
            $summary = $this->calculatePaymentSummary($payments);

            return successResponse("Salary payments retrieved successfully", $payments, [
                "count" => count($payments),
                "summary" => $summary
            ]);
        } catch (PDOException $e) {
            return errorResponse("Database error: " . $e->getMessage(), [], "DATABASE_EXCEPTION");
        }
    }

    /* --------------------------------------------------------------------
        GET SALARY PAYMENT BY ID
       -------------------------------------------------------------------- */
    public function getSalaryPaymentById($id)
    {
        if (empty($id)) {
            return errorResponse("Payment ID is required", [], "MISSING_ID");
        }

        try {
            $query = "SELECT sp.*, 
                         u.username as created_by,
                         b.name as branch_name,
                         e.name as employee_name,
                         e.designation as employee_designation,
                         e.salary as employee_salary,
                         e.phone as employee_phone,
                         e.email as employee_email,
                         e.status as employee_status
                      FROM {$this->table_name} sp
                      LEFT JOIN users u ON sp.user_id = u.id
                      LEFT JOIN branches b ON sp.branch_id = b.id
                      LEFT JOIN employees e ON sp.employee_id = e.id
                      WHERE sp.id = ?";

            $stmt = $this->conn->prepare($query);
            $stmt->execute([$id]);

            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($payment) {
                return successResponse("Salary payment retrieved successfully", $payment);
            } else {
                return errorResponse("Salary payment not found", [], "PAYMENT_NOT_FOUND");
            }
        } catch (PDOException $e) {
            return errorResponse("Database error: " . $e->getMessage(), [], "DATABASE_EXCEPTION");
        }
    }

    /* --------------------------------------------------------------------
        UPDATE SALARY PAYMENT
       -------------------------------------------------------------------- */
    public function updateSalaryPayment($payment_id, $data)
    {
        try {
            $this->conn->beginTransaction();

            // Check if payment exists
            $existing_payment = $this->getSalaryPaymentById($payment_id);
            if (!$existing_payment['success']) {
                $this->conn->rollBack();
                return $existing_payment;
            }

            // If employee_id is being changed, check if new employee exists
            if (isset($data['employee_id']) && $data['employee_id'] != $existing_payment['data']['employee_id']) {
                if (!$this->entityExists('employees', $data['employee_id'])) {
                    $this->conn->rollBack();
                    return errorResponse("Invalid employee ID", [], "INVALID_EMPLOYEE");
                }
            }

            // If amount is being updated, check if it exceeds employee's salary
            if (isset($data['amount'])) {
                $employee_id = $data['employee_id'] ?? $existing_payment['data']['employee_id'];
                $salaryCheck = $this->checkSalaryAmount($employee_id, $data['amount']);
                if (!$salaryCheck['success']) {
                    $this->conn->rollBack();
                    return $salaryCheck;
                }
            }

            // If date is being updated, check for duplicate payment
            if (isset($data['date'])) {
                $employee_id = $data['employee_id'] ?? $existing_payment['data']['employee_id'];
                $existingPayment = $this->checkExistingPayment($employee_id, $data['date'], $payment_id);
                if ($existingPayment) {
                    $this->conn->rollBack();
                    return errorResponse(
                        "Salary payment already exists for this employee for " . date('F Y', strtotime($data['date'])),
                        ["date" => "Duplicate payment found for this month"],
                        "DUPLICATE_PAYMENT"
                    );
                }
            }

            // Update payment
            $update_success = $this->updatePayment($payment_id, $data);
            if (!$update_success) {
                throw new Exception("Failed to update salary payment");
            }

            $this->conn->commit();
            return successResponse("Salary payment updated successfully", ["payment_id" => $payment_id]);
        } catch (Exception $e) {
            $this->conn->rollBack();
            return errorResponse("Database error: " . $e->getMessage(), [], "DATABASE_EXCEPTION");
        }
    }

    /* --------------------------------------------------------------------
        DELETE SALARY PAYMENT
       -------------------------------------------------------------------- */
    public function deleteSalaryPayment($payment_id)
    {
        try {
            $this->conn->beginTransaction();

            // Check if payment exists
            $existing_payment = $this->getSalaryPaymentById($payment_id);
            if (!$existing_payment['success']) {
                $this->conn->rollBack();
                return $existing_payment;
            }

            // Delete payment
            $query = "DELETE FROM {$this->table_name} WHERE id = :payment_id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":payment_id", $payment_id);

            if (!$stmt->execute()) {
                throw new Exception("Failed to delete salary payment");
            }

            $this->conn->commit();
            return successResponse("Salary payment deleted successfully");
        } catch (Exception $e) {
            $this->conn->rollBack();
            return errorResponse("Database error: " . $e->getMessage(), [], "DATABASE_EXCEPTION");
        }
    }

    /* --------------------------------------------------------------------
        GET EMPLOYEE PAYMENT SUMMARY
       -------------------------------------------------------------------- */
    public function getEmployeePaymentSummary($employee_id, $year = null, $month = null)
    {
        if (empty($employee_id)) {
            return errorResponse("Employee ID is required", [], "MISSING_EMPLOYEE_ID");
        }

        try {
            // Validate employee exists
            if (!$this->entityExists('employees', $employee_id)) {
                return errorResponse("Invalid employee ID", [], "INVALID_EMPLOYEE");
            }

            $whereClause = "WHERE employee_id = ?";
            $params = [$employee_id];

            if ($year) {
                $whereClause .= " AND YEAR(date) = ?";
                $params[] = $year;
            }

            if ($month) {
                $whereClause .= " AND MONTH(date) = ?";
                $params[] = $month;
            }

            $query = "SELECT 
                         COUNT(*) as payment_count,
                         SUM(amount) as total_paid,
                         MIN(date) as first_payment_date,
                         MAX(date) as last_payment_date,
                         AVG(amount) as average_payment
                      FROM {$this->table_name}
                      {$whereClause}";

            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);

            $summary = $stmt->fetch(PDO::FETCH_ASSOC);

            // Get employee details
            $employee_query = "SELECT name, designation, salary FROM employees WHERE id = ?";
            $employee_stmt = $this->conn->prepare($employee_query);
            $employee_stmt->execute([$employee_id]);
            $employee = $employee_stmt->fetch(PDO::FETCH_ASSOC);

            return successResponse("Employee payment summary retrieved successfully", [
                "employee" => $employee,
                "payment_summary" => $summary
            ]);
        } catch (PDOException $e) {
            return errorResponse("Database error: " . $e->getMessage(), [], "DATABASE_EXCEPTION");
        }
    }

    /* --------------------------------------------------------------------
        GET BRANCH PAYMENT SUMMARY
       -------------------------------------------------------------------- */
    public function getBranchPaymentSummary($branch_id, $start_date = null, $end_date = null, $month = null, $year = null)
    {
        try {
            $whereClause = "WHERE branch_id = ?";
            $params = [$branch_id];

            if ($start_date) {
                $whereClause .= " AND date >= ?";
                $params[] = $start_date;
            }

            if ($end_date) {
                $whereClause .= " AND date <= ?";
                $params[] = $end_date;
            }

            if ($year) {
                $whereClause .= " AND YEAR(date) = ?";
                $params[] = $year;
            }

            if ($month) {
                $whereClause .= " AND MONTH(date) = ?";
                $params[] = $month;
            }

            $query = "SELECT 
                         COUNT(*) as total_payments,
                         SUM(amount) as total_amount,
                         COUNT(DISTINCT employee_id) as employees_paid,
                         MIN(date) as period_start,
                         MAX(date) as period_end
                      FROM {$this->table_name}
                      {$whereClause}";

            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);

            $summary = $stmt->fetch(PDO::FETCH_ASSOC);

            // Get top employees by payment amount
            $top_employees_query = "SELECT 
                         e.name as employee_name,
                         e.designation,
                         SUM(sp.amount) as total_paid,
                         COUNT(sp.id) as payment_count
                      FROM {$this->table_name} sp
                      LEFT JOIN employees e ON sp.employee_id = e.id
                      WHERE sp.branch_id = ?
                      GROUP BY sp.employee_id
                      ORDER BY total_paid DESC
                      LIMIT 5";

            $top_stmt = $this->conn->prepare($top_employees_query);
            $top_stmt->execute([$branch_id]);
            $top_employees = $top_stmt->fetchAll(PDO::FETCH_ASSOC);

            return successResponse("Branch payment summary retrieved successfully", [
                "summary" => $summary,
                "top_employees" => $top_employees
            ]);
        } catch (PDOException $e) {
            return errorResponse("Database error: " . $e->getMessage(), [], "DATABASE_EXCEPTION");
        }
    }

    // ==================== PRIVATE HELPER METHODS ====================

    private function validateSalaryPaymentData($data)
    {
        // Validate amount
        if ($data["amount"] <= 0) {
            return errorResponse("Invalid amount", ["amount" => "Amount must be positive"], "INVALID_AMOUNT");
        }

        // Validate date format
        if (!strtotime($data['date'])) {
            return errorResponse("Invalid date format", ["date" => "Date must be valid"], "INVALID_DATE");
        }

        // Validate existence of related entities
        if (!$this->entityExists('users', $data['user_id'])) {
            return errorResponse("Invalid user ID", [], "INVALID_USER");
        }

        if (!$this->entityExists('branches', $data['branch_id'])) {
            return errorResponse("Invalid branch ID", [], "INVALID_BRANCH");
        }

        if (!$this->entityExists('employees', $data['employee_id'])) {
            return errorResponse("Invalid employee ID", [], "INVALID_EMPLOYEE");
        }

        return ['success' => true];
    }

    private function checkSalaryAmount($employee_id, $amount)
    {
        $query = "SELECT salary FROM employees WHERE id = ? AND status = 'active'";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$employee_id]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$employee) {
            return errorResponse("Employee not found or inactive", [], "EMPLOYEE_NOT_FOUND");
        }

        $employee_salary = $employee['salary'];

        if ($amount > $employee_salary) {
            return errorResponse(
                "Payment amount exceeds employee's salary",
                [
                    "amount" => "Payment amount (Rs. " . number_format($amount) . ") exceeds employee's salary (Rs. " . number_format($employee_salary) . ")"
                ],
                "AMOUNT_EXCEEDS_SALARY"
            );
        }

        return ['success' => true];
    }

    private function checkExistingPayment($employee_id, $date, $exclude_payment_id = null)
    {
        $query = "SELECT id FROM {$this->table_name} 
                 WHERE employee_id = ? 
                 AND YEAR(date) = YEAR(?) 
                 AND MONTH(date) = MONTH(?)";

        $params = [$employee_id, $date, $date];

        if ($exclude_payment_id) {
            $query .= " AND id != ?";
            $params[] = $exclude_payment_id;
        }

        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function insertSalaryPayment($data)
    {
        $query = "INSERT INTO {$this->table_name} 
                 SET user_id = :user_id, branch_id = :branch_id, employee_id = :employee_id, 
                     amount = :amount, date = :date, description = :description,
                     created_at = NOW()";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(":user_id", $data['user_id']);
        $stmt->bindValue(":branch_id", $data['branch_id']);
        $stmt->bindValue(":employee_id", $data['employee_id']);
        $stmt->bindValue(":amount", $data['amount']);
        $stmt->bindValue(":date", $data['date']);
        $stmt->bindValue(":description", $data['description'] ?? '');

        return $stmt->execute() ? $this->conn->lastInsertId() : false;
    }

    private function updatePayment($payment_id, $data)
    {
        $allowedFields = ["employee_id", "amount", "date", "description"];
        $fields = [];
        $params = [":payment_id" => $payment_id];

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
        $query = "UPDATE {$this->table_name} SET $setClause, updated_at = NOW() WHERE id = :payment_id";
        $stmt = $this->conn->prepare($query);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        return $stmt->execute();
    }

    private function buildWhereClause($branch_id, $employee_id, $start_date, $end_date, $month, $year, $include_archived)
    {
        $whereClause = "WHERE 1=1";
        $params = [];

        if ($branch_id) {
            $whereClause .= " AND sp.branch_id = ?";
            $params[] = $branch_id;
        }

        if ($employee_id) {
            $whereClause .= " AND sp.employee_id = ?";
            $params[] = $employee_id;
        }

        if ($start_date) {
            $whereClause .= " AND sp.date >= ?";
            $params[] = $start_date;
        }

        if ($end_date) {
            $whereClause .= " AND sp.date <= ?";
            $params[] = $end_date;
        }

        if ($year) {
            $whereClause .= " AND YEAR(sp.date) = ?";
            $params[] = $year;
        }

        if ($month) {
            $whereClause .= " AND MONTH(sp.date) = ?";
            $params[] = $month;
        }

        if (!$include_archived) {
            $whereClause .= " AND e.status = 'active'";
        }

        return [$whereClause, $params];
    }

    private function calculatePaymentSummary($payments)
    {
        $total_amount = 0;
        $payment_count = count($payments);
        $employees_paid = [];

        foreach ($payments as $payment) {
            $total_amount += $payment['amount'];
            $employees_paid[$payment['employee_id']] = true;
        }

        return [
            "total_amount" => $total_amount,
            "payment_count" => $payment_count,
            "employees_count" => count($employees_paid),
            "average_payment" => $payment_count > 0 ? $total_amount / $payment_count : 0
        ];
    }

    private function entityExists($table, $id)
    {
        $query = "SELECT id FROM {$table} WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public function __destruct()
    {
        $this->conn = null;
    }
}