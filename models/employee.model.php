<?php

require_once "utils/validation_utils.php";

require_once "config/database.php";

class EmployeeModel
{
    private $conn;
    private $table_name = "employees";
    private $db;

    public function __construct()
    {
        $this->db = new Database();
        $this->conn = $this->db->getConnection();
    }

    public function addEmployee($data)
    {
        try {
            // Validation of required fields
            $required_fields = ["name", "address", "phone", "designation", "cnic", "user_id", "branch_id", "salary"];
            $validateErrors = validation_utils::validateRequired($data, $required_fields);

            if (!empty($validateErrors)) {
                return errorResponse("Validation failed", $validateErrors, "VALIDATION_ERROR");
            }

            // Validate email if provided
            if (!empty($data["email"]) && !validation_utils::validateEmail($data["email"])) {
                return errorResponse(
                    "Invalid email format",
                    ["email" => "Please provide a valid email address"],
                    "INVALID_EMAIL"
                );
            }

            // Validate CNIC format (13 digits)
            // if (!preg_match('/^\d{13}$/', $data["cnic"])) {
            //     return errorResponse(
            //         "Invalid CNIC format",
            //         ["cnic" => "CNIC must be 13 digits without dashes"],
            //         "INVALID_CNIC"
            //     );
            // }

            // Validate phone format
            if (!preg_match('/^\+?[\d\s\-\(\)]{10,}$/', $data["phone"])) {
                return errorResponse(
                    "Invalid phone format",
                    ["phone" => "Please provide a valid phone number"],
                    "INVALID_PHONE"
                );
            }

            // Validate salary (must be positive)
            if ($data["salary"] <= 0) {
                return errorResponse(
                    "Invalid salary",
                    ["salary" => "Salary must be a positive number"],
                    "INVALID_SALARY"
                );
            }

            // Check if CNIC already exists
            if ($this->cnicExists($data['cnic'])) {
                return errorResponse(
                    "CNIC already exists",
                    ["cnic" => "An employee with this CNIC already exists"],
                    "DUPLICATE_CNIC"
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
                     SET name = :name, address = :address, phone = :phone, 
                         email = :email, designation = :designation, cnic = :cnic, 
                         user_id = :user_id, branch_id = :branch_id, 
                         is_permanent = :is_permanent, salary = :salary, 
                         created_at = NOW()";

            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(":name", $data['name']);
            $stmt->bindValue(":address", $data['address']);
            $stmt->bindValue(":phone", $data['phone']);
            $stmt->bindValue(":email", $data['email'] ?? null);
            $stmt->bindValue(":designation", $data['designation']);
            $stmt->bindValue(":cnic", $data['cnic']);
            $stmt->bindValue(":user_id", $data['user_id']);
            $stmt->bindValue(":branch_id", $data['branch_id']);
            $stmt->bindValue(":is_permanent", $data['is_permanent'] ?? false, PDO::PARAM_BOOL);
            $stmt->bindValue(":salary", $data['salary']);

            if ($stmt->execute()) {
                $employeeData = [
                    "id" => $this->conn->lastInsertId(),
                    "name" => $data['name'],
                    "designation" => $data['designation'],
                    "phone" => $data['phone'],
                    "cnic" => $data['cnic'],
                    "branch_id" => $data['branch_id'],
                    "salary" => $data['salary'],
                    "is_permanent" => $data['is_permanent'] ?? false
                ];

                return successResponse(
                    "Employee {$data['name']} added successfully",
                    $employeeData
                );
            } else {
                return errorResponse(
                    "Failed to add employee",
                    [],
                    "INSERT_FAILED"
                );
            }
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while adding employee",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    public function getEmployees($branch_id = null, $user_id = null, $include_archived = false)
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
                      ORDER BY e.created_at DESC";

            $stmt = $this->conn->prepare($query);

            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            $stmt->execute();

            $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return successResponse(
                "Employees retrieved successfully",
                $employees,
                ["count" => count($employees)]
            );
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching employees",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }
    public function getEmployeeById($id)
    {
        if (empty($id)) {
            return errorResponse("Employee ID is required", [], "MISSING_id");
        }

        try {
            $query = "SELECT e.*, u.username as created_by, b.name as branch_name 
                      FROM {$this->table_name} e
                      LEFT JOIN users u ON e.user_id = u.id AND u.status = 'active'
                      LEFT JOIN branches b ON e.branch_id = b.id AND b.status = 'active'
                      WHERE e.id = ? ";

            $stmt = $this->conn->prepare($query);
            $stmt->execute([$id]);

            $employee = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($employee) {
                return successResponse("Employee retrieved successfully", $employee);
            } else {
                return errorResponse(
                    "Employee not found",
                    [],
                    "EMPLOYEE_NOT_FOUND"
                );
            }
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching employee",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    /* --------------------------------------------------------------------
        UPDATE EMPLOYEE
       -------------------------------------------------------------------- */
    public function updateEmployee($id, $data)
    {
        if (empty($id)) {
            return errorResponse("Employee ID is required", [], "MISSING_id");
        }

        if (empty($data)) {
            return errorResponse("No data provided for update", [], "MISSING_UPDATE_DATA");
        }

        try {
            // Check if employee exists
            $existingEmployee = $this->getEmployeeById($id);
            if (!$existingEmployee['success']) {
                return $existingEmployee;
            }

            // Validate salary if provided
            if (isset($data["salary"]) && $data["salary"] <= 0) {
                return errorResponse(
                    "Invalid salary",
                    ["salary" => "Salary must be a positive number"],
                    "INVALID_SALARY"
                );
            }

            // Sanitize data
            $data = validation_utils::sanitizeInput($data);

            // Build dynamic update query
            $allowedFields = ["name", "address", "phone", "email", "designation", "is_permanent", "salary", "status"];
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
                return successResponse("Employee updated successfully");
            } else {
                return errorResponse(
                    "Failed to update employee",
                    [],
                    "UPDATE_FAILED"
                );
            }
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while updating employee",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }


    public function deleteEmployee($id)
    {
        if (empty($id)) {
            return errorResponse("Employee ID is required", [], "MISSING_ID");
        }

        try {

            $existingEmployee = $this->getEmployeeById($id);
            if (!$existingEmployee['success']) {
                return $existingEmployee;
            }


            $this->conn->beginTransaction();


            $deletePaymentsQuery = "DELETE FROM salary_payments WHERE employee_id = :employee_id";
            $deletePaymentsStmt = $this->conn->prepare($deletePaymentsQuery);
            $deletePaymentsStmt->bindParam(":employee_id", $id, PDO::PARAM_INT);

            if (!$deletePaymentsStmt->execute()) {
                $this->conn->rollBack();
                return errorResponse(
                    "Failed to delete related salary payments",
                    [],
                    "DELETE_SALARY_PAYMENTS_FAILED"
                );
            }


            $deleteEmployeeQuery = "DELETE FROM {$this->table_name} WHERE id = :id";
            $deleteEmployeeStmt = $this->conn->prepare($deleteEmployeeQuery);
            $deleteEmployeeStmt->bindParam(":id", $id, PDO::PARAM_INT);

            if ($deleteEmployeeStmt->execute()) {
                $this->conn->commit();
                return successResponse("Employee and related salary payments deleted successfully");
            } else {
                $this->conn->rollBack();
                return errorResponse(
                    "Failed to delete employee",
                    [],
                    "DELETE_EMPLOYEE_FAILED"
                );
            }
        } catch (PDOException $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return errorResponse(
                "Database error occurred while deleting employee",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    public function getEmployeesByBranchPaginated($branch_id, $page = 1, $limit = 10, $include_archived = false)
    {
        if (empty($branch_id)) {
            return errorResponse("Branch ID is required", [], "MISSING_BRANCH_ID");
        }

        try {
            $offset = ($page - 1) * $limit;

            // Count total records - specify table alias
            if ($include_archived) {
                $countQuery = "SELECT COUNT(*) as total FROM {$this->table_name} e WHERE e.branch_id = :branch_id";
            } else {
                $countQuery = "SELECT COUNT(*) as total FROM {$this->table_name} e WHERE e.branch_id = :branch_id AND e.status != 'archived'";
            }

            $countStmt = $this->conn->prepare($countQuery);
            $countStmt->bindValue(":branch_id", $branch_id);
            $countStmt->execute();
            $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            $totalPages = ceil($totalCount / $limit);

            // Get paginated data
            if ($include_archived) {
                $query = "SELECT e.*, u.username as created_by, b.name as branch_name 
                          FROM {$this->table_name} e
                          LEFT JOIN users u ON e.user_id = u.id AND u.status = 'active'
                          LEFT JOIN branches b ON e.branch_id = b.id AND b.status = 'active'
                          WHERE e.branch_id = :branch_id 
                          ORDER BY e.created_at DESC 
                          LIMIT :limit OFFSET :offset";
            } else {
                $query = "SELECT e.*, u.username as created_by, b.name as branch_name 
                          FROM {$this->table_name} e
                          LEFT JOIN users u ON e.user_id = u.id AND u.status = 'active'
                          LEFT JOIN branches b ON e.branch_id = b.id AND b.status = 'active'
                          WHERE e.branch_id = :branch_id AND e.status != 'archived' 
                          ORDER BY e.created_at DESC 
                          LIMIT :limit OFFSET :offset";
            }

            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(":branch_id", $branch_id, PDO::PARAM_INT);
            $stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
            $stmt->bindValue(":offset", $offset, PDO::PARAM_INT);
            $stmt->execute();

            $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $paginationMeta = [
                'current_page' => (int) $page,
                'per_page' => (int) $limit,
                'total_employees' => (int) $totalCount,
                'total_pages' => (int) $totalPages,
                'has_next' => $page < $totalPages,
                'has_prev' => $page > 1
            ];

            return successResponse(
                "Employees retrieved successfully",
                $employees,
                $paginationMeta
            );
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching employees",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    /* --------------------------------------------------------------------
        GET PERMANENT EMPLOYEES
       -------------------------------------------------------------------- */
    public function getPermanentEmployees($branch_id = null)
    {
        try {
            $whereClause = "WHERE e.is_permanent = 1 AND e.status != 'archived'";
            $params = [];

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
                      ORDER BY e.created_at DESC";

            $stmt = $this->conn->prepare($query);

            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            $stmt->execute();

            $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return successResponse(
                "Permanent employees retrieved successfully",
                $employees,
                ["count" => count($employees)]
            );
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching permanent employees",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    /* --------------------------------------------------------------------
        GET EMPLOYEES BY DESIGNATION
       -------------------------------------------------------------------- */
    public function getEmployeesByDesignation($designation, $branch_id = null)
    {
        if (empty($designation)) {
            return errorResponse("Designation is required", [], "MISSING_DESIGNATION");
        }

        try {
            $whereClause = "WHERE e.designation = :designation AND e.status != 'archived'";
            $params = [":designation" => $designation];

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
                      ORDER BY e.created_at DESC";

            $stmt = $this->conn->prepare($query);

            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            $stmt->execute();

            $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return successResponse(
                "Employees by designation retrieved successfully",
                $employees,
                ["count" => count($employees)]
            );
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching employees by designation",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    /* --------------------------------------------------------------------
        CHECK IF CNIC EXISTS
       -------------------------------------------------------------------- */
    private function cnicExists($cnic)
    {
        try {
            $query = "SELECT id FROM {$this->table_name} WHERE cnic = ? AND status != 'archived'";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$cnic]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }

    /* --------------------------------------------------------------------
        CHECK IF USER EXISTS
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

    /* --------------------------------------------------------------------
        CHECK IF BRANCH EXISTS
       -------------------------------------------------------------------- */
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
