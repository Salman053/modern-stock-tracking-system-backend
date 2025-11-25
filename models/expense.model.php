<?php

require_once "utils/validation_utils.php";

require_once "config/database.php";

class ExpenseModel
{
    private $conn;
    private $table_name = "expenses";
    private $db;

    public function __construct()
    {
        $this->db = new Database();
        $this->conn = $this->db->getConnection();
    }

     public function addExpense($data)
    {
        try {
            // Validation of required fields
            $required_fields = ["type", "date", "user_id", "branch_id", "title", "amount", "domain"];
            $validateErrors = validation_utils::validateRequired($data, $required_fields);

            if (!empty($validateErrors)) {
                return errorResponse("Validation failed", $validateErrors, "VALIDATION_ERROR");
            }

            // Validate amount (must be positive)
            if ($data["amount"] <= 0) {
                return errorResponse(
                    "Invalid amount", 
                    ["amount" => "Amount must be a positive number"], 
                    "INVALID_AMOUNT"
                );
            }

            // Validate date format
            if (!validation_utils::validateDate($data["date"])) {
                return errorResponse(
                    "Invalid date format", 
                    ["date" => "Date must be in YYYY-MM-DD format"], 
                    "INVALID_DATE"
                );
            }

            // Validate currency if provided
            if (!empty($data["currency"]) && !in_array(strtoupper($data["currency"]), ['PKR', 'USD', 'EUR', 'GBP'])) {
                return errorResponse(
                    "Invalid currency", 
                    ["currency" => "Currency must be PKR, USD, EUR, or GBP"], 
                    "INVALID_CURRENCY"
                );
            }

            // Check if user and branch exist
            if (!$this->userExists($data['user_id'])) {
                return errorResponse("Invalid user ID", [], "INVALID_USER");
            }

            if (!$this->branchExists($data['branch_id'])) {
                return errorResponse("Invalid branch ID", [], "INVALID_BRANCH");
            }

            // Sanitize data
            $data = validation_utils::sanitizeInput($data);

            $query = "INSERT INTO " . $this->table_name . " 
                     SET type = :type, date = :date, user_id = :user_id, 
                         branch_id = :branch_id, title = :title, description = :description,
                         amount = :amount, currency = :currency, domain = :domain,
                         created_at = NOW()";

            $stmt = $this->conn->prepare($query);
            $description = $data['description'] ?? null;
            $currency = $data['currency'] ?? 'PKR';
            
            $stmt->bindParam(":type", $data['type']);
            $stmt->bindParam(":date", $data['date']);
            $stmt->bindParam(":user_id", $data['user_id']);
            $stmt->bindParam(":branch_id", $data['branch_id']);
            $stmt->bindParam(":title", $data['title']);
            $stmt->bindParam(":description", $description);
            $stmt->bindParam(":amount", $data['amount']);
            $stmt->bindParam(":currency", $currency);
            $stmt->bindParam(":domain", $data['domain']);

            if ($stmt->execute()) {
                $expenseData = [
                    "id" => $this->conn->lastInsertId(),
                    "type" => $data['type'],
                    "date" => $data['date'],
                    "title" => $data['title'],
                    "amount" => $data['amount'],
                    "currency" => $data['currency'] ?? 'PKR',
                    "domain" => $data['domain'],
                    "branch_id" => $data['branch_id']
                ];

                return successResponse(
                    "Expense '{$data['title']}' added successfully", 
                    $expenseData
                );
            } else {
                return errorResponse(
                    "Failed to add expense", 
                    [], 
                    "INSERT_FAILED"
                );
            }

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while adding expense", 
                ["database" => $e->getMessage()], 
                "DATABASE_EXCEPTION"
            );
        }
    }

    /* --------------------------------------------------------------------
        GET EXPENSES
       -------------------------------------------------------------------- */
    public function getExpenses($branch_id = null, $user_id = null, $include_archived = false)
    {
        try {
            $whereClause = "WHERE 1=1";
            $params = [];

            if ($branch_id) {
                $whereClause .= " AND e.branch_id = :branch_id";
                $params[":branch_id"] = $branch_id;
            }

            if ($user_id) {
                $whereClause .= " AND e.user_id = :user_id";
                $params[":user_id"] = $user_id;
            }

            if (!$include_archived) {
                $whereClause .= " AND e.status != 'archived'";
            }

            // Also ensure we only get active users and branches
            $whereClause .= " AND u.status = 'active' AND b.status = 'active'";

            $query = "SELECT e.*, u.username as created_by, b.name as branch_name 
                      FROM {$this->table_name} e
                      LEFT JOIN users u ON e.user_id = u.id
                      LEFT JOIN branches b ON e.branch_id = b.id
                      {$whereClause} 
                      ORDER BY e.date DESC, e.created_at DESC";

            $stmt = $this->conn->prepare($query);

            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            $stmt->execute();

            $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return successResponse(
                "Expenses retrieved successfully", 
                $expenses,
                ["count" => count($expenses)]
            );

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching expenses", 
                ["database" => $e->getMessage()], 
                "DATABASE_EXCEPTION"
            );
        }
    }

       public function getExpenseById($id)
    {
        if (empty($id)) {
            return errorResponse("Expense ID is required", [], "MISSING_id");
        }

        try {
            $query = "SELECT e.*, u.username as created_by, b.name as branch_name 
                      FROM {$this->table_name} e
                      LEFT JOIN users u ON e.user_id = u.id AND u.status = 'active'
                      LEFT JOIN branches b ON e.branch_id = b.id AND b.status = 'active'
                      WHERE e.id = ? ";

            $stmt = $this->conn->prepare($query);
            $stmt->execute([$id]);

            $expense = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($expense) {
                return successResponse("Expense retrieved successfully", $expense);
            } else {
                return errorResponse(
                    "Expense not found", 
                    [], 
                    "EXPENSE_NOT_FOUND"
                );
            }

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching expense", 
                ["database" => $e->getMessage()], 
                "DATABASE_EXCEPTION"
            );
        }
    }

   
    public function updateExpense($id, $data)
    {
        if (empty($id)) {
            return errorResponse("Expense ID is required", [], "MISSING_id");
        }

        if (empty($data)) {
            return errorResponse("No data provided for update", [], "MISSING_UPDATE_DATA");
        }

        try {
            // Check if expense exists
            $existingExpense = $this->getExpenseById($id);
            if (!$existingExpense['success']) {
                return $existingExpense;
            }

            // Validate amount if provided
            if (isset($data["amount"]) && $data["amount"] <= 0) {
                return errorResponse(
                    "Invalid amount", 
                    ["amount" => "Amount must be a positive number"], 
                    "INVALID_AMOUNT"
                );
            }

            // Validate date if provided
            if (isset($data["date"]) && !validation_utils::validateDate($data["date"])) {
                return errorResponse(
                    "Invalid date format", 
                    ["date" => "Date must be in YYYY-MM-DD format"], 
                    "INVALID_DATE"
                );
            }

            // Sanitize data
            $data = validation_utils::sanitizeInput($data);

            // Build dynamic update query
            $allowedFields = ["type", "date", "title", "description", "amount", "currency", "domain", "status"];
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

            $setClause = implode(", ", $fields);
            $query = "UPDATE {$this->table_name} 
                      SET $setClause, updated_at = NOW() 
                      WHERE id = :id";

            $stmt = $this->conn->prepare($query);

            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            if ($stmt->execute()) {
                return successResponse("Expense updated successfully");
            } else {
                return errorResponse(
                    "Failed to update expense", 
                    [], 
                    "UPDATE_FAILED"
                );
            }

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while updating expense", 
                ["database" => $e->getMessage()], 
                "DATABASE_EXCEPTION"
            );
        }
    }

     public function deleteExpense($id)
    {
        if (empty($id)) {
            return errorResponse("Expense ID is required", [], "MISSING_id");
        }

        try {
            // Check if expense exists
            $existingExpense = $this->getExpenseById($id);
            if (!$existingExpense['success']) {
                return $existingExpense;
            }

            $query = "UPDATE {$this->table_name} 
                      SET status = 'archived', updated_at = NOW() 
                      WHERE id = :id";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":id", $id);

            if ($stmt->execute()) {
                return successResponse("Expense archived successfully");
            } else {
                return errorResponse(
                    "Failed to archive expense", 
                    [], 
                    "UPDATE_FAILED"
                );
            }

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while archiving expense", 
                ["database" => $e->getMessage()], 
                "DATABASE_EXCEPTION"
            );
        }
    }

 
    public function getExpensesByDateRange($start_date, $end_date, $branch_id = null)
    {
        if (empty($start_date) || empty($end_date)) {
            return errorResponse("Start date and end date are required", [], "MISSING_DATE_RANGE");
        }

        try {
            $whereClause = "WHERE e.date BETWEEN :start_date AND :end_date AND e.status != 'archived'";
            $params = [
                ":start_date" => $start_date,
                ":end_date" => $end_date
            ];

            if ($branch_id) {
                $whereClause .= " AND e.branch_id = :branch_id";
                $params[":branch_id"] = $branch_id;
            }

            $whereClause .= " AND u.status = 'active' AND b.status = 'active'";

            $query = "SELECT e.*, u.username as created_by, b.name as branch_name 
                      FROM {$this->table_name} e
                      LEFT JOIN users u ON e.user_id = u.id
                      LEFT JOIN branches b ON e.branch_id = b.id
                      {$whereClause} 
                      ORDER BY e.date DESC, e.created_at DESC";

            $stmt = $this->conn->prepare($query);

            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            $stmt->execute();

            $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return successResponse(
                "Expenses by date range retrieved successfully", 
                $expenses,
                ["count" => count($expenses)]
            );

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching expenses by date range", 
                ["database" => $e->getMessage()], 
                "DATABASE_EXCEPTION"
            );
        }
    }

      public function getExpensesByType($type, $branch_id = null)
    {
        if (empty($type)) {
            return errorResponse("Expense type is required", [], "MISSING_EXPENSE_TYPE");
        }

        try {
            $whereClause = "WHERE e.type = :type AND e.status != 'archived'";
            $params = [":type" => $type];

            if ($branch_id) {
                $whereClause .= " AND e.branch_id = :branch_id";
                $params[":branch_id"] = $branch_id;
            }

            $whereClause .= " AND u.status = 'active' AND b.status = 'active'";

            $query = "SELECT e.*, u.username as created_by, b.name as branch_name 
                      FROM {$this->table_name} e
                      LEFT JOIN users u ON e.user_id = u.id
                      LEFT JOIN branches b ON e.branch_id = b.id
                      {$whereClause} 
                      ORDER BY e.date DESC, e.created_at DESC";

            $stmt = $this->conn->prepare($query);

            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            $stmt->execute();

            $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return successResponse(
                "Expenses by type retrieved successfully", 
                $expenses,
                ["count" => count($expenses)]
            );

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching expenses by type", 
                ["database" => $e->getMessage()], 
                "DATABASE_EXCEPTION"
            );
        }
    }

 
    public function getExpensesSummaryByMonth($year, $month, $branch_id = null)
    {
        if (empty($year) || empty($month)) {
            return errorResponse("Year and month are required", [], "MISSING_YEAR_MONTH");
        }

        try {
            $whereClause = "WHERE YEAR(e.date) = :year AND MONTH(e.date) = :month AND e.status != 'archived'";
            $params = [
                ":year" => $year,
                ":month" => $month
            ];

            if ($branch_id) {
                $whereClause .= " AND e.branch_id = :branch_id";
                $params[":branch_id"] = $branch_id;
            }

            $whereClause .= " AND u.status = 'active' AND b.status = 'active'";

            $query = "SELECT 
                         e.type,
                         e.domain,
                         COUNT(*) as expense_count,
                         SUM(e.amount) as total_amount,
                         e.currency
                      FROM {$this->table_name} e
                      LEFT JOIN users u ON e.user_id = u.id
                      LEFT JOIN branches b ON e.branch_id = b.id
                      {$whereClause} 
                      GROUP BY e.type, e.domain, e.currency
                      ORDER BY total_amount DESC";

            $stmt = $this->conn->prepare($query);

            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            $stmt->execute();

            $summary = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate grand total
            $grandTotal = 0;
            foreach ($summary as $item) {
                $grandTotal += $item['total_amount'];
            }

            return successResponse(
                "Expenses summary retrieved successfully", 
                $summary,
                [
                    "year" => $year,
                    "month" => $month,
                    "grand_total" => $grandTotal,
                    "expense_count" => count($summary)
                ]
            );

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching expenses summary", 
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

    public function __destruct()
    {
        $this->conn = null;
    }
}