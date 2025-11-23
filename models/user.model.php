<?php

require_once "utils/validation_utils.php";
require_once "config/database.php";

class UserModel
{
    private $conn;
    private $table_name = "users";
    private $db;

    public function __construct()
    {
        $this->db = new Database();
        $this->conn = $this->db->getConnection();
    }

    /**
     * Standardized response format for success
     */
    private function successResponse($message = "", $data = [], $meta = [])
    {
        $response = [
            "success" => true,
            "message" => $message,
            "timestamp" => date('c')
        ];

        if (!empty($data)) {
            $response["data"] = $data;
        }

        if (!empty($meta)) {
            $response["meta"] = $meta;
        }

        return $response;
    }

    /**
     * Standardized response format for errors
     */
    private function errorResponse($message = "", $errors = [], $code = null)
    {
        $response = [
            "success" => false,
            "message" => $message,
            "timestamp" => date('c')
        ];

        if (!empty($errors)) {
            $response["errors"] = $errors;
        }

        if ($code !== null) {
            $response["code"] = $code;
        }

        return $response;
    }
    public function branchAdminExists($branch_id)
    {
        try {
            $query = "SELECT COUNT(*) as count 
                  FROM " . $this->table_name . " 
                  WHERE branch_id = :branch_id 
                    AND role = 'branch-admin' 
                    AND status = 'active'";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":branch_id", $branch_id);
            $stmt->execute();

            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            // If count > 0, a branch-admin already exists
            return isset($result['count']) && $result['count'] > 0;
        } catch (PDOException $e) {
            // Handle exception if needed
            return false;
        }
    }


    /**
     * Create user account in the system
     */
    public function createUser($data)
    {
        try {
            // Validation of required fields
            $required_fields = ["username", "password", "role", "branch_id"];
            $validateErrors = validation_utils::validateRequired($data, $required_fields);

            if (!empty($validateErrors)) {
                return $this->errorResponse("Validation failed", $validateErrors, "VALIDATION_ERROR");
            }

            // Validate user email
            if (!validation_utils::validateEmail($data["email"])) {
                return $this->errorResponse("Invalid email format", ["email" => "Please provide a valid email address"], "INVALID_EMAIL");
            }

            // Validate username length
            if (!validation_utils::validateLength($data["username"], 3, 50)) {
                return $this->errorResponse("Invalid username length", ["username" => "Username must be between 3 and 50 characters"], "INVALID_USERNAME_LENGTH");
            }

            // Check if username or email already exists
            if ($this->usernameExists($data['username'])) {
                return $this->errorResponse("Username already exists", ["username" => "This username is already taken"], "DUPLICATE_USERNAME");
            }

            if ($this->emailExists($data['email'])) {
                return $this->errorResponse("Email already exists", ["email" => "This email is already registered"], "DUPLICATE_EMAIL");
            }
            if ($this->branchAdminExists($data["branch_id"])) {
                return $this->errorResponse("Branch-admin already exists", ["role" => "There is already a branch admin"], "DUPLICATE_BRANCH_ADMIN");
            }

            // Sanitize data
            $data = validation_utils::sanitizeInput($data);

            // Hash password
            $hashed_password = password_hash($data['password'], PASSWORD_DEFAULT);

            // Insert query
            $query = "INSERT INTO " . $this->table_name . " 
                     SET username = :username, password = :password, email = :email, 
                         role = :role, branch_id = :branch_id, status = :status";

            $stmt = $this->conn->prepare($query);

            $status = $data["status"] ?? "active";

            // Bind parameters
            $stmt->bindParam(":username", $data['username']);
            $stmt->bindParam(":password", $hashed_password);
            $stmt->bindParam(":email", $data['email']);
            $stmt->bindParam(":role", $data['role']);
            $stmt->bindParam(":branch_id", $data['branch_id']);
            $stmt->bindParam(":status", $status);

            if ($stmt->execute()) {
                $userData = [
                    "user_id" => $this->conn->lastInsertId(),
                    "username" => $data['username'],
                    "email" => $data['email'],
                    "role" => $data['role'],
                    "branch_id" => $data['branch_id'],
                    "status" => $status
                ];

                return $this->successResponse(
                    "User {$data['username']} created successfully",
                    $userData
                );
            }

            return $this->errorResponse("Unable to create user", [], "DATABASE_ERROR");
        } catch (PDOException $e) {
            return $this->errorResponse(
                "Database error occurred",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    /**
     * User login with session management
     */
    public function login($username, $password)
    {
        // CORS headers
        header("Access-Control-Allow-Origin: http://localhost:3000");
        header("Access-Control-Allow-Credentials: true");
        header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
        header("Access-Control-Allow-Methods: POST, GET, OPTIONS");

        // Handle preflight OPTIONS request
        if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
            http_response_code(200);
            exit();
        }

        // Session configuration
        ini_set('session.cookie_samesite', 'None');
        ini_set('session.cookie_secure', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_path', '/');

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => 'localhost',
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Input validation
        if (empty($username)) {
            return $this->errorResponse("Username is required", [], "MISSING_USERNAME");
        }

        if (empty($password)) {
            return $this->errorResponse("Password is required", [], "MISSING_PASSWORD");
        }

        try {
            $query = "SELECT * FROM {$this->table_name} WHERE username = ? AND status = 'active'";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$username]);

            if ($stmt->rowCount() === 0) {
                return $this->errorResponse("Invalid username or password", [], "INVALID_CREDENTIALS");
            }

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!password_verify($password, $user["password"])) {
                return $this->errorResponse("Invalid username or password", [], "INVALID_CREDENTIALS");
            }

            // Update last login
            $this->updateLastLogin($user["id"]);

            // Regenerate session ID for security
            session_regenerate_id(true);

            // Set session data
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['branch_id'] = $user['branch_id'] ?? null;
            $_SESSION['is_logged_in'] = true;

            // Prepare user data for response (remove sensitive information)
            unset($user["password"]);
            $userData = [
                "id" => $user['id'],
                "username" => $user['username'],
                "email" => $user['email'],
                "role" => $user['role'],
                "branch_id" => $user['branch_id'],
                "last_login" => $user['last_login']
            ];

            return $this->successResponse(
                "Login successful",
                $userData,
                ["session_id" => session_id()]
            );
        } catch (PDOException $e) {
            return $this->errorResponse(
                "Authentication error",
                ["database" => $e->getMessage()],
                "AUTHENTICATION_ERROR"
            );
        }
    }

    /**
     * Get user by ID
     */
    public function getUser($user_id, $include_password = false)
    {
        try {
            // Get user with branch information using JOIN
            $query = "SELECT 
    u.*, 
    b.name as branch_name,
    b.country as branch_country,
    b.city as branch_city,
    b.address as branch_address,
    b.status as branch_status,
    b.is_main_branch
FROM " . $this->table_name . " u
LEFT JOIN branches b ON u.branch_id = b.id
WHERE u.id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$user_id]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (empty($user)) {
                return $this->errorResponse("User not found", [], "USER_NOT_FOUND");
            }
            if (!$include_password) {
                unset($user["password"]);
            }

            return $this->successResponse("User retrieved successfully", $user);
        } catch (PDOException $e) {
            return $this->errorResponse(
                "Database error",
                ["database" => $e->getMessage()],
                "DATABASE_ERROR"
            );
        }
    }

    /**
     * Check if username exists
     */
    public function usernameExists($username)
    {
        $query = "SELECT id FROM " . $this->table_name . " WHERE username = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$username]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Check if email exists
     */
    public function emailExists($email)
    {
        $query = "SELECT id FROM " . $this->table_name . " WHERE email = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$email]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Update last login timestamp
     */
    private function updateLastLogin($user_id)
    {
        $query = "UPDATE " . $this->table_name . " 
                  SET last_login = NOW() 
                  WHERE id = :id";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id", $user_id);
        $stmt->execute();
    }

    /**
     * Update user information
     */
    public function updateUser($user_id, $data)
    {
        if (empty($data) || empty($user_id)) {
            return $this->errorResponse("No data provided or invalid user ID", [], "INVALID_INPUT");
        }

        try {
            $data = validation_utils::sanitizeInput($data);

            // Dynamic query construction
            $fields = [];
            $params = [];

            foreach ($data as $key => $value) {
                if ($key === "password") {
                    $fields[] = "password = :password";
                    $params[":password"] = password_hash($value, PASSWORD_DEFAULT);
                } else {
                    $fields[] = "$key = :$key";
                    $params[":$key"] = $value;
                }
            }

            $params[":id"] = $user_id;
            $setClause = implode(", ", $fields);

            $query = "UPDATE {$this->table_name} SET $setClause WHERE id = :id";
            $stmt = $this->conn->prepare($query);

            if ($stmt->execute($params)) {
                return $this->successResponse("User updated successfully");
            } else {
                return $this->errorResponse("Update failed", [], "UPDATE_FAILED");
            }
        } catch (PDOException $e) {
            return $this->errorResponse(
                "Database error",
                ["database" => $e->getMessage()],
                "DATABASE_ERROR"
            );
        }
    }

    public function updatePassword($user_id, $password)
    {
        if (empty($user_id)) {
            return $this->errorResponse("No data provided or invalid user ID", [], "INVALID_INPUT");
        }

        // Validate that only password is provided

        try {
            $password = validation_utils::sanitizeInput($password);

            // Validate password strength
            if (strlen($password) < 8) {
                return $this->errorResponse("Password must be at least 8 characters long", [], "VALIDATION_ERROR");
            }

            // Hash the password
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            $query = "UPDATE {$this->table_name} SET password = :password WHERE id = :id";
            $stmt = $this->conn->prepare($query);

            $params = [
                ':password' => $hashedPassword,
                ':id' => $user_id
            ];

            if ($stmt->execute($params)) {
                return $this->successResponse("Password updated successfully");
            } else {
                return $this->errorResponse("Password update failed", [], "UPDATE_FAILED");
            }
        } catch (PDOException $e) {
            return $this->errorResponse(
                "Database error",
                ["database" => $e->getMessage()],
                "DATABASE_ERROR"
            );
        }
    }
    /**
     * Soft delete user (archive)
     */
    public function deleteUser($user_id)
    {
        if (empty($user_id)) {
            return $this->errorResponse("Invalid user ID", [], "INVALID_USER_ID");
        }

        try {
            $query = "UPDATE {$this->table_name} SET status = 'archived' WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":id", $user_id);

            if ($stmt->execute()) {
                return $this->successResponse("User deactivated successfully");
            } else {
                return $this->errorResponse("Failed to deactivate user", [], "DEACTIVATION_FAILED");
            }
        } catch (PDOException $e) {
            return $this->errorResponse(
                "Database error",
                ["database" => $e->getMessage()],
                "DATABASE_ERROR"
            );
        }
    }

    /**
     * Get user by ID with specific fields
     */
    public function getUserById($user_id)
    {
        if (empty($user_id)) {
            return $this->errorResponse("User ID is required", [], "MISSING_USER_ID");
        }

        try {
            $query = "SELECT id, username, email, role, branch_id, status, created_at, last_login 
                      FROM {$this->table_name} 
                      WHERE id = ? AND status != 'archived'";

            $stmt = $this->conn->prepare($query);
            $stmt->execute([$user_id]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                return $this->successResponse("User retrieved successfully", $user);
            } else {
                return $this->errorResponse("User not found or archived", [], "USER_NOT_FOUND");
            }
        } catch (PDOException $e) {
            return $this->errorResponse(
                "Database error",
                ["database" => $e->getMessage()],
                "DATABASE_ERROR"
            );
        }
    }

    /**
     * Get users by branch
     */
    public function getUsersByBranch($branch_id, $include_archived = false)
    {
        if (empty($branch_id)) {
            return $this->errorResponse("Branch ID is required", [], "MISSING_BRANCH_ID");
        }

        try {
            if ($include_archived) {
                $query = "SELECT id, username, email, role, branch_id, status, created_at, last_login 
                          FROM {$this->table_name} 
                          WHERE branch_id = ?  
                          ORDER BY created_at DESC";
            } else {
                $query = "SELECT id, username, email, role, branch_id, status, created_at, last_login 
                          FROM {$this->table_name} 
                          WHERE branch_id = ? AND status != 'archived' 
                          ORDER BY created_at DESC";
            }

            $stmt = $this->conn->prepare($query);
            $stmt->execute([$branch_id]);

            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return $this->successResponse(
                "Users retrieved successfully",
                $users,
                ["count" => count($users)]
            );
        } catch (PDOException $e) {
            return $this->errorResponse(
                "Database error",
                ["database" => $e->getMessage()],
                "DATABASE_ERROR"
            );
        }
    }

    /**
     * Get paginated users by branch
     */
    public function getUsersByBranchPaginated($branch_id, $page = 1, $limit = 10, $include_archived = false)
    {
        if (empty($branch_id)) {
            return $this->errorResponse("Branch ID is required", [], "MISSING_BRANCH_ID");
        }

        try {
            $offset = ($page - 1) * $limit;

            // Count total records
            if ($include_archived) {
                $countQuery = "SELECT COUNT(*) as total FROM {$this->table_name} WHERE branch_id = ?";
            } else {
                $countQuery = "SELECT COUNT(*) as total FROM {$this->table_name} WHERE branch_id = ? AND status != 'archived'";
            }

            $countStmt = $this->conn->prepare($countQuery);
            $countStmt->execute([$branch_id]);
            $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            $totalPages = ceil($totalCount / $limit);

            // Get paginated data
            if ($include_archived) {
                $query = "SELECT id, username, email, role, branch_id, status, created_at, last_login 
                          FROM {$this->table_name} 
                          WHERE branch_id = ? 
                          ORDER BY created_at DESC 
                          LIMIT ? OFFSET ?";
            } else {
                $query = "SELECT id, username, email, role, branch_id, status, created_at, last_login 
                          FROM {$this->table_name} 
                          WHERE branch_id = ? AND status != 'archived' 
                          ORDER BY created_at DESC 
                          LIMIT ? OFFSET ?";
            }

            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(1, $branch_id, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->bindValue(3, $offset, PDO::PARAM_INT);
            $stmt->execute();

            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $paginationMeta = [
                'current_page' => (int)$page,
                'per_page' => (int)$limit,
                'total_users' => (int)$totalCount,
                'total_pages' => (int)$totalPages,
                'has_next' => $page < $totalPages,
                'has_prev' => $page > 1
            ];

            return $this->successResponse(
                "Users retrieved successfully",
                $users,
                $paginationMeta
            );
        } catch (PDOException $e) {
            return $this->errorResponse(
                "Database error",
                ["database" => $e->getMessage()],
                "DATABASE_ERROR"
            );
        }
    }

    /**
     * Get all users with optional filtering
     */
    public function getAllUsers($branch_id = null, $include_archived = false)
    {
        try {
            $whereClause = "WHERE 1=1";
            $params = [];

            if ($branch_id) {
                $whereClause .= " AND u.branch_id = ?";
                $params[] = $branch_id;
            }

            if (!$include_archived) {
                $whereClause .= " AND u.status != 'archived'";
            }

            $query = "SELECT u.id, u.username, u.email, u.role, u.branch_id, u.status, 
                         u.created_at, u.last_login,
                         b.name as branch_name, b.address as branch_address, 
                         b.status as branch_status
                  FROM {$this->table_name} u
                  LEFT JOIN branches b ON u.branch_id = b.id
                  {$whereClause} 
                  ORDER BY u.created_at DESC";

            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);

            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return successResponse(
                "Users retrieved successfully",
                $users,
                ["count" => count($users)]
            );
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching users",
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
