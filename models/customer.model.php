<?php

require_once "utils/validation_utils.php";

require_once "config/database.php";

class CustomerModel
{
    private $conn;
    private $table_name = "customers";
    private $db;

    public function __construct()
    {
        $this->db = new Database();
        $this->conn = $this->db->getConnection();
    }

    /* --------------------------------------------------------------------
        ADD CUSTOMER
       -------------------------------------------------------------------- */
    public function addCustomer($data)
    {
        try {
            // Validation of required fields
            $required_fields = ["name", "address", "phone", "cnic", "user_id", "branch_id"];
            $validateErrors = validation_utils::validateRequired($data, $required_fields);

            if (!empty($validateErrors)) {
                return errorResponse("Validation failed", $validateErrors, "VALIDATION_ERROR");
            }

            if (!empty($data["email"]) && !validation_utils::validateEmail($data["email"])) {
                return errorResponse(
                    "Invalid email format",
                    ["email" => "Please provide a valid email address"],
                    "INVALID_EMAIL"
                );
            }

            if (!preg_match('/^\d{13}$/', $data["cnic"])) {
                return errorResponse(
                    "Invalid CNIC format",
                    ["cnic" => "CNIC must be 13 digits without dashes"],
                    "INVALID_CNIC"
                );
            }

            if (!preg_match('/^\+?[\d\s\-\(\)]{10,}$/', $data["phone"])) {
                return errorResponse(
                    "Invalid phone format",
                    ["phone" => "Please provide a valid phone number"],
                    "INVALID_PHONE"
                );
            }

            if ($this->cnicExists($data['cnic'])) {
                return errorResponse(
                    "CNIC already exists",
                    ["cnic" => "A customer with this CNIC already exists"],
                    "DUPLICATE_CNIC"
                );
            }

            if (!$this->userExists($data['user_id'])) {
                return errorResponse("Invalid user ID", [], "INVALID_USER");
            }

            if (!$this->branchExists($data['branch_id'])) {
                return errorResponse("Invalid branch ID", [], "INVALID_BRANCH");
            }

            $data = validation_utils::sanitizeInput($data);

            $query = "INSERT INTO " . $this->table_name . " 
                     SET name = :name, address = :address, phone = :phone, 
                         email = :email, cnic = :cnic, user_id = :user_id, 
                         branch_id = :branch_id, is_regular = :is_regular, 
                         created_at = NOW()";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":name", $data['name']);
            $stmt->bindParam(":address", $data['address']);
            $stmt->bindParam(":phone", $data['phone']);
            $email = $data['email'] ?? null;
            $stmt->bindParam(":email", $email);
            $stmt->bindParam(":cnic", $data['cnic']);
            $stmt->bindParam(":user_id", $data['user_id']);
            $stmt->bindParam(":branch_id", $data['branch_id']);
            $is_regular = $data['is_regular'] ?? false;
            $stmt->bindParam(":is_regular", $is_regular);

            if ($stmt->execute()) {
                $customerData = [
                    "id" => $this->conn->lastInsertId(),
                    "name" => $data['name'],
                    "phone" => $data['phone'],
                    "cnic" => $data['cnic'],
                    "branch_id" => $data['branch_id'],
                    "is_regular" => $data['is_regular'] ?? false
                ];

                return successResponse(
                    "Customer {$data['name']} added successfully",
                    $customerData
                );
            } else {
                return errorResponse(
                    "Failed to add customer",
                    [],
                    "INSERT_FAILED"
                );
            }

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while adding customer",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    public function getCustomers($branch_id = null, $user_id = null, $include_archived = false)
    {
        try {
            $whereClause = "WHERE 1=1";
            $params = [];

            if ($branch_id) {
                $whereClause .= " AND c.branch_id = :branch_id";
                $params[":branch_id"] = $branch_id;
            }

            if ($user_id) {
                $whereClause .= " AND c.user_id = :user_id";
                $params[":user_id"] = $user_id;
            }

            if (!$include_archived) {
                $whereClause .= " AND c.status != 'archived'";
            }

            $whereClause .= " AND u.status = 'active' AND b.status = 'active'";

            $query = "SELECT c.*, u.username as created_by, b.name as branch_name 
                        FROM {$this->table_name} c
                        LEFT JOIN users u ON c.user_id = u.id
                        LEFT JOIN branches b ON c.branch_id = b.id
                        {$whereClause} 
                        ORDER BY c.created_at DESC";

            $stmt = $this->conn->prepare($query);

            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            $stmt->execute();

            $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return successResponse(
                "Customers retrieved successfully",
                $customers,
                // ["count" => count($customers)]
            );

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching customers",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    public function getCustomerById($id)
    {
        if (empty($id)) {
            return errorResponse("Customer ID is required", [], "MISSING_id");
        }

        try {
            $query = "SELECT c.*, u.username as created_by, b.name as branch_name 
                      FROM {$this->table_name} c
                      LEFT JOIN users u ON c.user_id = u.id AND u.status = 'active'
                      LEFT JOIN branches b ON c.branch_id = b.id AND b.status = 'active'
                      WHERE c.id = ? AND c.status != 'archived'";

            $stmt = $this->conn->prepare($query);
            $stmt->execute([$id]);

            $customer = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($customer) {
                return successResponse("Customer retrieved successfully", $customer);
            } else {
                return errorResponse(
                    "Customer not found",
                    [],
                    "CUSTOMER_NOT_FOUND"
                );
            }

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching customer",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }
    public function updateCustomer($id, $data)
    {
        if (empty($id)) {
            return errorResponse("Customer ID is required", [], "MISSING_id");
        }

        if (empty($data)) {
            return errorResponse("No data provided for update", [], "MISSING_UPDATE_DATA");
        }

        try {
            // Check if customer exists
            $existingCustomer = $this->getCustomerById($id);
            if (!$existingCustomer['success']) {
                return $existingCustomer;
            }

            // Sanitize data
            $data = validation_utils::sanitizeInput($data);

            // Build dynamic update query
            $allowedFields = ["name", "address", "phone", "email", "is_regular", "status"];
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
                return successResponse("Customer updated successfully");
            } else {
                return errorResponse(
                    "Failed to update customer",
                    [],
                    "UPDATE_FAILED"
                );
            }

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while updating customer",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }
    public function deleteCustomer($id)
    {
        if (empty($id)) {
            return errorResponse("Customer ID is required", [], "MISSING_id");
        }

        try {
            // Check if customer exists
            $existingCustomer = $this->getCustomerById($id);
            if (!$existingCustomer['success']) {
                return $existingCustomer;
            }

            $query = "UPDATE {$this->table_name} 
                      SET status = 'archived', updated_at = NOW() 
                      WHERE id = :id";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":id", $id);

            if ($stmt->execute()) {
                return successResponse("Customer archived successfully");
            } else {
                return errorResponse(
                    "Failed to archive customer",
                    [],
                    "UPDATE_FAILED"
                );
            }

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while archiving customer",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    public function getCustomersByBranchPaginated($branch_id, $page = 1, $limit = 10, $include_archived = false)
    {
        if (empty($branch_id)) {
            return errorResponse("Branch ID is required", [], "MISSING_BRANCH_ID");
        }

        try {
            $offset = ($page - 1) * $limit;

            // Count total records - specify table alias
            if ($include_archived) {
                $countQuery = "SELECT COUNT(*) as total FROM {$this->table_name} c WHERE c.branch_id = :branch_id";
            } else {
                $countQuery = "SELECT COUNT(*) as total FROM {$this->table_name} c WHERE c.branch_id = :branch_id AND c.status != 'archived'";
            }

            $countStmt = $this->conn->prepare($countQuery);
            $countStmt->bindValue(":branch_id", $branch_id);
            $countStmt->execute();
            $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            $totalPages = ceil($totalCount / $limit);

            // Get paginated data
            if ($include_archived) {
                $query = "SELECT c.*, u.username as created_by, b.name as branch_name 
                          FROM {$this->table_name} c
                          LEFT JOIN users u ON c.user_id = u.id AND u.status = 'active'
                          LEFT JOIN branches b ON c.branch_id = b.id AND b.status = 'active'
                          WHERE c.branch_id = :branch_id 
                          ORDER BY c.created_at DESC 
                          LIMIT :limit OFFSET :offset";
            } else {
                $query = "SELECT c.*, u.username as created_by, b.name as branch_name 
                          FROM {$this->table_name} c
                          LEFT JOIN users u ON c.user_id = u.id AND u.status = 'active'
                          LEFT JOIN branches b ON c.branch_id = b.id AND b.status = 'active'
                          WHERE c.branch_id = :branch_id AND c.status != 'archived' 
                          ORDER BY c.created_at DESC 
                          LIMIT :limit OFFSET :offset";
            }

            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(":branch_id", $branch_id, PDO::PARAM_INT);
            $stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
            $stmt->bindValue(":offset", $offset, PDO::PARAM_INT);
            $stmt->execute();

            $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $paginationMeta = [
                'current_page' => (int) $page,
                'per_page' => (int) $limit,
                'total_customers' => (int) $totalCount,
                'total_pages' => (int) $totalPages,
                'has_next' => $page < $totalPages,
                'has_prev' => $page > 1
            ];

            return successResponse(
                "Customers retrieved successfully",
                $customers,
                $paginationMeta
            );

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching customers",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }
    public function getRegularCustomers($branch_id = null)
    {
        try {
            $whereClause = "WHERE c.is_regular = 1 AND c.status != 'archived'";
            $params = [];

            if ($branch_id) {
                $whereClause .= " AND c.branch_id = :branch_id";
                $params[":branch_id"] = $branch_id;
            }

            $whereClause .= " AND u.status = 'active' AND b.status = 'active'";

            $query = "SELECT c.*, u.username as created_by, b.name as branch_name 
                      FROM {$this->table_name} c
                      LEFT JOIN users u ON c.user_id = u.id
                      LEFT JOIN branches b ON c.branch_id = b.id
                      {$whereClause} 
                      ORDER BY c.created_at DESC";

            $stmt = $this->conn->prepare($query);

            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            $stmt->execute();

            $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return successResponse(
                "Regular customers retrieved successfully",
                $customers,
                ["count" => count($customers)]
            );

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching regular customers",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }


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