<?php

require_once "utils/validation_utils.php";

class SupplierModel
{
    private $conn;
    private $table_name = "suppliers";
    private $db;

    public function __construct()
    {
        $this->db = new Database();
        $this->conn = $this->db->getConnection();
    }

    /* --------------------------------------------------------------------
        ADD SUPPLIER
       -------------------------------------------------------------------- */
    public function addSupplier($data)
    {
        try {
            // Validation of required fields
            $required_fields = ["name", "address", "phone", "cnic", "user_id", "branch_id"];
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
            if (!preg_match('/^\d{13}$/', $data["cnic"])) {
                return errorResponse(
                    "Invalid CNIC format",
                    ["cnic" => "CNIC must be 13 digits without dashes"],
                    "INVALID_CNIC"
                );
            }

            // Validate phone format
            if (!preg_match('/^\+?[\d\s\-\(\)]{10,}$/', $data["phone"])) {
                return errorResponse(
                    "Invalid phone format",
                    ["phone" => "Please provide a valid phone number"],
                    "INVALID_PHONE"
                );
            }

            // Check if CNIC already exists
            if ($this->cnicExists($data['cnic'])) {
                return errorResponse(
                    "CNIC already exists",
                    ["cnic" => "A supplier with this CNIC already exists"],
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
                         email = :email, cnic = :cnic, user_id = :user_id, 
                         branch_id = :branch_id, is_permanent = :is_permanent, 
                         created_at = NOW()";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":name", $data['name']);
            $stmt->bindParam(":address", $data['address']);
            $stmt->bindParam(":phone", $data['phone']);
            $email = $data['email'] ?? null;
            $isPermanent = $data['is_permanent'] ?? false;
            $stmt->bindParam(":email", $email);
            $stmt->bindParam(":cnic", $data['cnic']);
            $stmt->bindParam(":user_id", $data['user_id']);
            $stmt->bindParam(":branch_id", $data['branch_id']);
            $stmt->bindParam(":is_permanent", $isPermanent);

            if ($stmt->execute()) {
                $supplierData = [
                    "id" => $this->conn->lastInsertId(),
                    "name" => $data['name'],
                    "phone" => $data['phone'],
                    "cnic" => $data['cnic'],
                    "branch_id" => $data['branch_id'],
                    "is_permanent" => $data['is_permanent'] ?? false
                ];

                return successResponse(
                    "Supplier {$data['name']} added successfully",
                    $supplierData
                );
            } else {
                return errorResponse(
                    "Failed to add supplier",
                    [],
                    "INSERT_FAILED"
                );
            }
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while adding supplier",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    /* --------------------------------------------------------------------
        GET SUPPLIERS
       -------------------------------------------------------------------- */
    public function getSuppliers($branch_id = null, $user_id = null, $include_archived = false)
    {
        try {
            $whereClause = "WHERE 1=1";
            $params = [];

            if ($branch_id) {
                $whereClause .= " AND s.branch_id = :branch_id";
                $params[":branch_id"] = $branch_id;
            }

            if ($user_id) {
                $whereClause .= " AND s.user_id = :user_id";
                $params[":user_id"] = $user_id;
            }

            if (!$include_archived) {
                $whereClause .= " AND s.status != 'archived'";
            }

            $query = "SELECT s.*, u.username as created_by, b.name as branch_name 
                      FROM {$this->table_name} s
                      LEFT JOIN users u ON s.user_id = u.id
                      LEFT JOIN branches b ON s.branch_id = b.id
                      {$whereClause} 
                      ORDER BY s.created_at DESC";

            $stmt = $this->conn->prepare($query);

            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            $stmt->execute();

            $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return successResponse(
                "Suppliers retrieved successfully",
                $suppliers,
                ["count" => count($suppliers)]
            );
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching suppliers",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    /* --------------------------------------------------------------------
        GET SUPPLIER BY ID
       -------------------------------------------------------------------- */
    public function getSupplierById($id)
    {
        if (empty($id)) {
            return errorResponse("Supplier ID is required", [], "MISSING_id");
        }

        try {
            $query = "SELECT s.*, u.username as created_by, b.name as branch_name 
                      FROM {$this->table_name} s
                      LEFT JOIN users u ON s.user_id = u.id
                      LEFT JOIN branches b ON s.branch_id = b.id
                      WHERE s.id = ? ";

            $stmt = $this->conn->prepare($query);
            $stmt->execute([$id]);

            $supplier = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($supplier) {
                return successResponse("Supplier retrieved successfully", $supplier);
            } else {
                return errorResponse(
                    "Supplier not found",
                    [],
                    "SUPPLIER_NOT_FOUND"
                );
            }
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching supplier",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    /* --------------------------------------------------------------------
        UPDATE SUPPLIER
       -------------------------------------------------------------------- */
    public function updateSupplier($id, $data)
    {
        if (empty($id)) {
            return errorResponse("Supplier ID is required", [], "MISSING_id");
        }

        if (empty($data)) {
            return errorResponse("No data provided for update", [], "MISSING_UPDATE_DATA");
        }

        try {
            // Check if supplier exists
            $existingSupplier = $this->getSupplierById($id);
            if (!$existingSupplier['success']) {
                return $existingSupplier;
            }

            // Sanitize data
            $data = validation_utils::sanitizeInput($data);

            // Build dynamic update query
            $allowedFields = ["name", "address", "phone", "email", "is_permanent", "status"];
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
                return successResponse("Supplier updated successfully");
            } else {
                return errorResponse(
                    "Failed to update supplier",
                    [],
                    "UPDATE_FAILED"
                );
            }
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while updating supplier",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    /* --------------------------------------------------------------------
        DELETE SUPPLIER (SOFT DELETE)
       -------------------------------------------------------------------- */
    public function deleteSupplier($id)
    {
        if (empty($id)) {
            return errorResponse("Supplier ID is required", [], "MISSING_id");
        }

        try {
            // Check if supplier exists
            $existingSupplier = $this->getSupplierById($id);
            if (!$existingSupplier['success']) {
                return $existingSupplier;
            }

            $query = "UPDATE {$this->table_name} 
                      SET status = 'archived', updated_at = NOW() 
                      WHERE id = :id";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":id", $id);

            if ($stmt->execute()) {
                return successResponse("Supplier archived successfully");
            } else {
                return errorResponse(
                    "Failed to archive supplier",
                    [],
                    "UPDATE_FAILED"
                );
            }
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while archiving supplier",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    /* --------------------------------------------------------------------
        GET SUPPLIERS BY BRANCH WITH PAGINATION
       -------------------------------------------------------------------- */
    public function getSuppliersByBranchPaginated($branch_id, $page = 1, $limit = 10, $include_archived = false)
    {
        if (empty($branch_id)) {
            return errorResponse("Branch ID is required", [], "MISSING_BRANCH_ID");
        }

        try {
            $offset = ($page - 1) * $limit;

            // Count total records
            if ($include_archived) {
                $countQuery = "SELECT COUNT(*) as total FROM {$this->table_name} WHERE branch_id = :branch_id";
            } else {
                $countQuery = "SELECT COUNT(*) as total FROM {$this->table_name} WHERE branch_id = :branch_id AND status != 'archived'";
            }

            $countStmt = $this->conn->prepare($countQuery);
            $countStmt->bindValue(":branch_id", $branch_id);
            $countStmt->execute();
            $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            $totalPages = ceil($totalCount / $limit);

            // Get paginated data
            if ($include_archived) {
                $query = "SELECT s.*, u.username as created_by, b.name as branch_name 
                          FROM {$this->table_name} s
                          LEFT JOIN users u ON s.user_id = u.id
                          LEFT JOIN branches b ON s.branch_id = b.id
                          WHERE s.branch_id = :branch_id 
                          ORDER BY s.created_at DESC 
                          LIMIT :limit OFFSET :offset";
            } else {
                $query = "SELECT s.*, u.username as created_by, b.name as branch_name 
                          FROM {$this->table_name} s
                          LEFT JOIN users u ON s.user_id = u.id
                          LEFT JOIN branches b ON s.branch_id = b.id
                          WHERE s.branch_id = :branch_id AND s.status != 'archived' 
                          ORDER BY s.created_at DESC 
                          LIMIT :limit OFFSET :offset";
            }

            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(":branch_id", $branch_id, PDO::PARAM_INT);
            $stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
            $stmt->bindValue(":offset", $offset, PDO::PARAM_INT);
            $stmt->execute();

            $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $paginationMeta = [
                'current_page' => (int)$page,
                'per_page' => (int)$limit,
                'total_suppliers' => (int)$totalCount,
                'total_pages' => (int)$totalPages,
                'has_next' => $page < $totalPages,
                'has_prev' => $page > 1
            ];

            return successResponse(
                "Suppliers retrieved successfully",
                $suppliers,
                $paginationMeta
            );
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching suppliers",
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
