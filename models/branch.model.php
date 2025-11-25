<?php

require_once "utils/validation_utils.php";
require_once "config/database.php";

class BranchModel
{
    private $conn;
    private $table_name = "branches";
    private $db;

    public function __construct()
    {
        $this->db = new Database();
        $this->conn = $this->db->getConnection();
    }

    /* --------------------------------------------------------------------
        GET ACTIVE BRANCHES
       -------------------------------------------------------------------- */
    public function getActiveBranches()
    {
        try {
            $query = "SELECT * FROM " . $this->table_name . " WHERE status = 'active' ORDER BY name ASC";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();

            $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($branches)) {
                return successResponse(
                    "Active branches retrieved successfully",
                    $branches,
                    ["count" => count($branches)]
                );
            } else {
                return errorResponse(
                    "No active branches found",
                    [],
                    "NO_ACTIVE_BRANCHES"
                );
            }
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    /* --------------------------------------------------------------------
        ADD NEW BRANCH
       -------------------------------------------------------------------- */
    public function addNewBranch($data)
    {
        try {
            // Validation of required fields
            $required_fields = ["name", "status", "country", "address", "city"];
            $validateErrors = validation_utils::validateRequired($data, $required_fields);

            if (!empty($validateErrors)) {
                return errorResponse("Validation failed", $validateErrors, "VALIDATION_ERROR");
            }

            // Validate status value
            $allowedStatuses = ["active", "in-active", "archived"];

            if (!in_array($data["status"], $allowedStatuses)) {
                return errorResponse(
                    "Invalid status value",
                    ["status" => "Status must be either 'active' or 'inactive'"],
                    "INVALID_STATUS"
                );
            }

            // Check if branch name already exists
            if ($this->branchNameExists($data['name'])) {
                return errorResponse(
                    "Branch name already exists",
                    ["name" => "A branch with this name already exists"],
                    "DUPLICATE_BRANCH_NAME"
                );
            }
            if ($data["is_main_branch"] && $this->mainBranchExists()) {
                return errorResponse("Main branch already exists");
            }
            if (empty($data["is_main_branch"])) {
                $is_main_branch = false;
            } else {
                $is_main_branch = $data["is_main_branch"];
            }

            // Sanitize data
            $data = validation_utils::sanitizeInput($data);

            $query = "INSERT INTO " . $this->table_name . " 
                     SET name = :name, status = :status,is_main_branch=:is_main_branch, country = :country, 
                         address = :address, city = :city, created_at = NOW()";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":name", $data['name']);
            $stmt->bindParam(":status", $data["status"]);
            $stmt->bindParam(":country", $data['country']);
            $stmt->bindParam(":address", $data['address']);
            $stmt->bindParam(":city", $data['city']);
            $stmt->bindParam(":is_main_branch", $is_main_branch);

            if ($stmt->execute()) {
                $branchData = [
                    "branch_id" => $this->conn->lastInsertId(),
                    "name" => $data['name'],
                    "status" => $data['status'],
                    "country" => $data['country'],
                    "city" => $data['city'],

                ];

                return successResponse(
                    "Branch {$data['name']} added successfully",
                    $branchData
                );
            } else {
                return errorResponse(
                    "Failed to add branch",
                    "INSERT_FAILED"
                );
            }
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while adding branch" . $e->getMessage(),
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    /* --------------------------------------------------------------------
        DELETE BRANCH (SOFT DELETE)
       -------------------------------------------------------------------- */
    public function deleteBranch(int $branchId)
    {
        if (empty($branchId)) {
            return errorResponse("Branch ID is required", [], "MISSING_BRANCH_ID");
        }


        try {
            // Check if branch exists
            $existingBranch = $this->getBranchById($branchId);
            if (!$existingBranch['success']) {
                return $existingBranch;
            }

            $query = "UPDATE " . $this->table_name . " 
                      SET status = :status, updated_at = NOW()
                      WHERE id = :id";

            $stmt = $this->conn->prepare($query);

            $status = "archived";
            $stmt->bindParam(":status", $status);
            $stmt->bindParam(":id", $branchId);

            if ($stmt->execute()) {
                return successResponse("Branch archived successfully");
            } else {
                return errorResponse(
                    "Failed to archive branch",
                    [],
                    "UPDATE_FAILED"
                );
            }
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while archiving branch",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    /* --------------------------------------------------------------------
        UPDATE BRANCH
       -------------------------------------------------------------------- */
    public function updateBranch(int $branchId, array $data)
    {
        if (empty($branchId)) {
            return errorResponse("Branch ID is required", [], "MISSING_BRANCH_ID");
        }

        if (empty($data)) {
            return errorResponse("No data provided for update", [], "MISSING_UPDATE_DATA");
        }

        try {
            // Check if branch exists
            $existingBranch = $this->getBranchById($branchId);
            if (!$existingBranch['success']) {
                return $existingBranch;
            }

            // Sanitize data
            $data = validation_utils::sanitizeInput($data);

            // Build dynamic update query
            $allowedFields = ["name", "status", "country", "address", "city"];
            $fields = [];
            $params = [":id" => $branchId];

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
            $query = "UPDATE " . $this->table_name . " 
                      SET $setClause, updated_at = NOW() 
                      WHERE id = :id";

            $stmt = $this->conn->prepare($query);

            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            if ($stmt->execute()) {
                return successResponse("Branch updated successfully");
            } else {
                return errorResponse(
                    "Failed to update branch",
                    [],
                    "UPDATE_FAILED"
                );
            }
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while updating branch",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    /* --------------------------------------------------------------------
        GET BRANCH BY ID
       -------------------------------------------------------------------- */
    public function getBranchById(int $branchId)
    {
        if (empty($branchId)) {
            return errorResponse("Branch ID is required", [], "MISSING_BRANCH_ID");
        }

        try {
            $query = "SELECT * FROM " . $this->table_name . " WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$branchId]);

            $branch = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($branch) {
                return successResponse("Branch retrieved successfully", $branch);
            } else {
                return errorResponse(
                    "Branch not found",
                    [],
                    "BRANCH_NOT_FOUND"
                );
            }
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching branch",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    public function getAllBranches($status = null, $include_archived = false)
    {
        try {
            $whereClause = "";
            $params = [];

            if ($status) {
                $whereClause = "WHERE b.status = ?";
                $params[] = $status;
            } elseif (!$include_archived) {
                $whereClause = "WHERE b.status != 'archived'";
            }

            $query = "SELECT 
                    b.*, 
                    COUNT(u.id) as user_count,
                    COUNT(CASE WHEN u.status = 'active' THEN 1 END) as active_users_count,
                    COUNT(CASE WHEN u.status = 'archived' THEN 1 END) as inactive_users_count
                  FROM {$this->table_name} b 
                  LEFT JOIN users u ON b.id = u.branch_id
                  {$whereClause}
                  GROUP BY b.id 
                  ORDER BY b.name ASC";

            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);

            $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Format the response to match your existing structure and add user counts
            $formattedBranches = array_map(function ($branch) {
                return [
                    'id' => (int)$branch['id'],
                    'name' => $branch['name'],
                    'status' => $branch['status'],
                    'country' => $branch['country'],
                    'city' => $branch['city'],
                    'address' => $branch['address'],
                    'is_main_branch' => (bool)$branch['is_main_branch'],
                    'created_at' => $branch['created_at'],
                    'updated_at' => $branch['updated_at'],
                    'user_count' => (int)$branch['user_count'],
                    'active_users_count' => (int)$branch['active_users_count'],
                    'inactive_users_count' => (int)$branch['inactive_users_count']
                ];
            }, $branches);

            // Calculate totals for meta information
            $totalUsers = array_sum(array_column($formattedBranches, 'user_count'));
            $totalActiveUsers = array_sum(array_column($formattedBranches, 'active_users_count'));
            $totalInactiveUsers = array_sum(array_column($formattedBranches, 'inactive_users_count'));

            return successResponse(
                "Branches retrieved successfully",
                $formattedBranches,
                [
                    "count" => count($branches),
                    "total_users" => $totalUsers,
                    "total_active_users" => $totalActiveUsers,
                    "total_inactive_users" => $totalInactiveUsers
                ]
            );
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching branches",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    /* --------------------------------------------------------------------
        CHECK IF BRANCH NAME EXISTS
       -------------------------------------------------------------------- */
    private function branchNameExists($name)
    {
        try {
            $query = "SELECT id FROM " . $this->table_name . " WHERE name = ? AND  status != 'archived' ";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$name]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }

    private function mainBranchExists()
    {
        try {
            $query = "SELECT id FROM " . $this->table_name . " WHERE is_main_branch = ? AND status != 'archived'";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([1]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return errorResponse($e->getMessage(), ["" => $e->getMessage()], 300);
        }
    }

    /* --------------------------------------------------------------------
        GET BRANCHES WITH USER COUNT
       -------------------------------------------------------------------- */
    public function getBranchesWithUserCount()
    {
        try {
            $query = "SELECT b.*, COUNT(u.id) as user_count 
                      FROM {$this->table_name} b 
                      LEFT JOIN users u ON b.id = u.branch_id 
                      WHERE b.status != 'archived'
                      GROUP BY b.id 
                      ORDER BY b.name ASC";

            $stmt = $this->conn->prepare($query);
            $stmt->execute();

            $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return successResponse(
                "Branches with user count retrieved successfully",
                $branches,
                ["count" => count($branches)]
            );
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching branches with user count",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    /* --------------------------------------------------------------------
        GET BRANCH USERS
       -------------------------------------------------------------------- */
    public function getBranchUsers(int $branchId)
    {
        if (empty($branchId)) {
            return errorResponse("Branch ID is required", [], "MISSING_BRANCH_ID");
        }

        try {
            $query = "SELECT u.id, u.username, u.email, u.role, u.status, u.created_at 
                      FROM users u 
                      WHERE u.branch_id = ? AND u.status != 'archived' 
                      ORDER BY u.created_at DESC";

            $stmt = $this->conn->prepare($query);
            $stmt->execute([$branchId]);

            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return successResponse(
                "Branch users retrieved successfully",
                $users,
                ["count" => count($users)]
            );
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching branch users",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    /* --------------------------------------------------------------------
        GET BRANCH STATISTICS
       -------------------------------------------------------------------- */
    public function getBranchStatistics(int $branchId)
    {
        if (empty($branchId)) {
            return errorResponse("Branch ID is required", [], "MISSING_BRANCH_ID");
        }

        try {
            // User statistics
            $userQuery = "SELECT 
                         COUNT(*) as total_users,
                         SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_users,
                         SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_users
                         FROM users WHERE branch_id = ? AND status != 'archived'";

            $userStmt = $this->conn->prepare($userQuery);
            $userStmt->execute([$branchId]);
            $userStats = $userStmt->fetch(PDO::FETCH_ASSOC);

            // Branch details
            $branchQuery = "SELECT name, status, country, city, address, created_at 
                           FROM branches WHERE id = ?";
            $branchStmt = $this->conn->prepare($branchQuery);
            $branchStmt->execute([$branchId]);
            $branchDetails = $branchStmt->fetch(PDO::FETCH_ASSOC);

            if (!$branchDetails) {
                return errorResponse("Branch not found", [], "BRANCH_NOT_FOUND");
            }

            $stats = [
                'branch' => $branchDetails,
                'user_statistics' => $userStats
            ];

            return successResponse("Branch statistics retrieved successfully", $stats);
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching branch statistics",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    /* --------------------------------------------------------------------
        GET BRANCHES SUMMARY
       -------------------------------------------------------------------- */
    public function getBranchesSummary()
    {
        try {
            $query = "SELECT 
                     COUNT(*) as total_branches,
                     SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_branches,
                     SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_branches,
                     SUM(CASE WHEN status = 'archived' THEN 1 ELSE 0 END) as archived_branches,
                     COUNT(DISTINCT country) as countries_count,
                     COUNT(DISTINCT city) as cities_count
                     FROM {$this->table_name}";

            $stmt = $this->conn->prepare($query);
            $stmt->execute();

            $summary = $stmt->fetch(PDO::FETCH_ASSOC);

            return successResponse("Branches summary retrieved successfully", $summary);
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching branches summary",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    /* --------------------------------------------------------------------
        RESTORE BRANCH
       -------------------------------------------------------------------- */
    public function restoreBranch(int $branchId)
    {
        if (empty($branchId)) {
            return errorResponse("Branch ID is required", [], "MISSING_BRANCH_ID");
        }

        try {
            // Check if branch exists and is archived
            $existingBranch = $this->getBranchById($branchId);
            if (!$existingBranch['success']) {
                return $existingBranch;
            }

            $query = "UPDATE {$this->table_name} 
                      SET status = :status, updated_at = NOW()
                      WHERE id = :id AND status = 'archived'";

            $stmt = $this->conn->prepare($query);

            $status = "active";
            $stmt->bindParam(":status", $status);
            $stmt->bindParam(":id", $branchId);

            if ($stmt->execute() && $stmt->rowCount() > 0) {
                return successResponse("Branch restored successfully");
            } else {
                return errorResponse(
                    "Branch not found or not archived",
                    [],
                    "RESTORE_FAILED"
                );
            }
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while restoring branch",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    /* --------------------------------------------------------------------
        UPDATE BRANCH STATUS
       -------------------------------------------------------------------- */
    public function updateBranchStatus(int $branchId, string $status)
    {
        if (empty($branchId)) {
            return errorResponse("Branch ID is required", [], "MISSING_BRANCH_ID");
        }

        if (!in_array($status, ['active', 'inactive'])) {
            return errorResponse("Invalid status value", [], "INVALID_STATUS");
        }

        try {
            $query = "UPDATE {$this->table_name} 
                      SET status = :status, updated_at = NOW()
                      WHERE id = :id AND status != 'archived'";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":status", $status);
            $stmt->bindParam(":id", $branchId);

            if ($stmt->execute() && $stmt->rowCount() > 0) {
                return successResponse("Branch status updated successfully");
            } else {
                return errorResponse(
                    "Branch not found or is archived",
                    [],
                    "UPDATE_FAILED"
                );
            }
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while updating branch status",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    /* --------------------------------------------------------------------
        BULK ADD BRANCHES
       -------------------------------------------------------------------- */
    public function bulkAddBranches(array $branches)
    {
        if (empty($branches) || !is_array($branches)) {
            return errorResponse("No branches data provided", [], "MISSING_BRANCHES_DATA");
        }

        try {
            $this->conn->beginTransaction();

            $successCount = 0;
            $errorCount = 0;
            $errors = [];

            $query = "INSERT INTO {$this->table_name} 
                     (name, status, country, address, city, created_at) 
                     VALUES (?, ?, ?, ?, ?, NOW())";

            $stmt = $this->conn->prepare($query);

            foreach ($branches as $index => $branch) {
                $required_fields = ["name", "status", "country", "address", "city"];
                $validateErrors = validation_utils::validateRequired($branch, $required_fields);

                if (!empty($validateErrors)) {
                    $errors[] = "Branch {$index}: " . implode(", ", $validateErrors);
                    $errorCount++;
                    continue;
                }

                // Check if branch name already exists
                if ($this->branchNameExists($branch['name'])) {
                    $errors[] = "Branch {$index}: Name '{$branch['name']}' already exists";
                    $errorCount++;
                    continue;
                }

                $sanitizedBranch = validation_utils::sanitizeInput($branch);

                try {
                    $stmt->execute([
                        $sanitizedBranch['name'],
                        $sanitizedBranch['status'],
                        $sanitizedBranch['country'],
                        $sanitizedBranch['address'],
                        $sanitizedBranch['city']
                    ]);
                    $successCount++;
                } catch (PDOException $e) {
                    $errors[] = "Branch {$index}: " . $e->getMessage();
                    $errorCount++;
                }
            }

            $this->conn->commit();

            $result = [
                "success_count" => $successCount,
                "error_count" => $errorCount,
                "errors" => $errors
            ];

            if ($successCount > 0) {
                return successResponse("Bulk branch addition completed", $result);
            } else {
                return errorResponse("All branch additions failed", $result, "BULK_INSERT_FAILED");
            }
        } catch (Exception $e) {
            $this->conn->rollBack();
            return errorResponse(
                "Database error occurred during bulk branch addition",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    /* --------------------------------------------------------------------
        BULK DELETE BRANCHES
       -------------------------------------------------------------------- */
    public function bulkDeleteBranches(array $branchIds)
    {
        if (empty($branchIds) || !is_array($branchIds)) {
            return errorResponse("No branch IDs provided", [], "MISSING_BRANCH_IDS");
        }

        try {
            // Create placeholders for IN clause
            $placeholders = str_repeat('?,', count($branchIds) - 1) . '?';

            $query = "UPDATE {$this->table_name} 
                      SET status = 'archived', updated_at = NOW()
                      WHERE id IN ($placeholders) AND status != 'archived'";

            $stmt = $this->conn->prepare($query);
            $stmt->execute($branchIds);

            $affectedRows = $stmt->rowCount();

            return successResponse("Bulk branch archiving completed", [
                "archived_count" => $affectedRows,
                "total_requested" => count($branchIds)
            ]);
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred during bulk branch archiving",
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