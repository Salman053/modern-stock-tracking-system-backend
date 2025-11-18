<?php

require_once "utils/validation_utils.php";
require_once "utils/response_utils.php";
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
            $allowedStatuses = ["active", "inactive"];
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

            // Sanitize data
            $data = validation_utils::sanitizeInput($data);

            $query = "INSERT INTO " . $this->table_name . " 
                     SET name = :name, status = :status, country = :country, 
                         address = :address, city = :city, created_at = NOW()";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":name", $data['name']);
            $stmt->bindParam(":status", $data["status"]);
            $stmt->bindParam(":country", $data['country']);
            $stmt->bindParam(":address", $data['address']);
            $stmt->bindParam(":city", $data['city']);

            if ($stmt->execute()) {
                $branchData = [
                    "branch_id" => $this->conn->lastInsertId(),
                    "name" => $data['name'],
                    "status" => $data['status'],
                    "country" => $data['country'],
                    "city" => $data['city']
                ];

                return successResponse(
                    "Branch {$data['name']} added successfully", 
                    $branchData
                );
            } else {
                return errorResponse(
                    "Failed to add branch", 
                    [], 
                    "INSERT_FAILED"
                );
            }

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while adding branch", 
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

    /* --------------------------------------------------------------------
        GET ALL BRANCHES WITH FILTERS
       -------------------------------------------------------------------- */
    public function getAllBranches($status = null, $include_archived = false)
    {
        try {
            $whereClause = "";
            $params = [];

            if ($status) {
                $whereClause = "WHERE status = ?";
                $params[] = $status;
            } elseif (!$include_archived) {
                $whereClause = "WHERE status != 'archived'";
            }

            $query = "SELECT * FROM {$this->table_name} 
                      {$whereClause} 
                      ORDER BY name ASC";

            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);

            $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return successResponse(
                "Branches retrieved successfully", 
                $branches,
                ["count" => count($branches)]
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
            $query = "SELECT id FROM " . $this->table_name . " WHERE name = ? AND status != 'archived'";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$name]);
            return $stmt->rowCount() > 0;

        } catch (PDOException $e) {
            return false;
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
                      LEFT JOIN users u ON b.id = u.branch_id AND u.status = 'active'
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

    public function __destruct()
    {
        $this->conn = null;
    }
}