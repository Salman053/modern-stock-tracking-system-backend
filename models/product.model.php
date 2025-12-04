<?php

require_once "utils/validation_utils.php";
require_once "config/database.php";

class ProductModel
{
    private $conn;
    private $table_name = "products";

    public function __construct()
    {
        $db = new Database();
        $this->conn = $db->getConnection();
    }


    public function addProduct($data)
    {
        if (!$data) {
            return errorResponse("Product data is missing", [], "MISSING_DATA");
        }

        // Required fields
        $required = ["name", "type", "user_id", "branch_id", "purchase_price_per_meter", "sales_price_per_meter"];
        $errors = validation_utils::validateRequired($data, $required);

        if (!empty($errors)) {
            return errorResponse("Validation failed", $errors, "VALIDATION_ERROR");
        }

        $data = validation_utils::sanitizeInput($data);

        // Insert query
        $query = "INSERT INTO {$this->table_name} 
                (name, type, description, company, quantity, user_id, branch_id, 
                purchase_price_per_meter, sales_price_per_meter)
                VALUES 
                (:name, :type, :description, :company, :quantity, :user_id, :branch_id,
                :purchase_price_per_meter, :sales_price_per_meter)";

        try {
            $stmt = $this->conn->prepare($query);

            $stmt->bindValue(":name", $data["name"]);
            $stmt->bindValue(":type", $data["type"]);
            $stmt->bindValue(":description", $data["description"] ?? null);
            $stmt->bindValue(":company", $data["company"] ?? "");
            $stmt->bindValue(":quantity", $data["quantity"] ?? 0);
            $stmt->bindValue(":user_id", $data["user_id"]);
            $stmt->bindValue(":branch_id", $data["branch_id"]);
            $stmt->bindValue(":purchase_price_per_meter", $data["purchase_price_per_meter"]);
            $stmt->bindValue(":sales_price_per_meter", $data["sales_price_per_meter"]);

            $stmt->execute();

            $product_id = $this->conn->lastInsertId();

            // Fetch complete product record including auto-generated profit_margin
            $fetchQuery = "SELECT * FROM {$this->table_name} WHERE id = :id LIMIT 1";
            $fetchStmt = $this->conn->prepare($fetchQuery);
            $fetchStmt->bindValue(":id", $product_id);
            $fetchStmt->execute();

            $product = $fetchStmt->fetch(PDO::FETCH_ASSOC);

            return successResponse(
                "Product added successfully",
                $product
            );
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }
    public function getProducts($user_id = null, $branch_id = null, $status = null)
    {
        $query = "SELECT * FROM {$this->table_name} WHERE 1=1";
        $params = [];

        if ($user_id) {
            $query .= " AND user_id = :user_id";
            $params[":user_id"] = $user_id;
        }

        if ($branch_id) {
            $query .= " AND branch_id = :branch_id";
            $params[":branch_id"] = $branch_id;
        }

        if ($status) {
            $query .= " AND status = :status";
            $params[":status"] = $status;
        }

        // Order by status (active first) and then by created date (newest first)
        $query .= " ORDER BY 
        CASE 
            WHEN status = 'active' THEN 1
            WHEN status = 'inactive' THEN 2
            WHEN status = 'out_of_stock' THEN 3
            WHEN status = 'archived' THEN 4
            ELSE 5
        END ASC,
        created_at DESC";

        try {
            $stmt = $this->conn->prepare($query);

            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            $stmt->execute();

            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return successResponse(
                "Products retrieved successfully",
                $products,
                ["count" => count($products)]
            );
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }
    /* --------------------------------------------------------------------
        GET SINGLE PRODUCT
       -------------------------------------------------------------------- */
    public function getProductById($id)
    {
        if (empty($id)) {
            return errorResponse("Product ID is required", [], "MISSING_PRODUCT_ID");
        }

        $query = "SELECT * FROM {$this->table_name} WHERE id = :id  LIMIT 1";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(":id", $id);
            $stmt->execute();

            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                return errorResponse("Product not found", [], "PRODUCT_NOT_FOUND");
            }

            return successResponse("Product retrieved successfully", $product);
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }
    public function updateProduct($id, $data)
    {
        if (empty($id)) {
            return errorResponse("Product ID is required", [], "MISSING_PRODUCT_ID");
        }

        if (!$data) {
            return errorResponse("No data provided for update", [], "MISSING_UPDATE_DATA");
        }

        // Validate product exists
        $existingProduct = $this->getProductById($id);
        if (!$existingProduct['success']) {
            return $existingProduct;
        }

        // Build dynamic SQL
        $fields = [];
        $params = [":id" => $id];

        foreach ($data as $key => $value) {
            // Validate allowed fields to prevent SQL injection
            $allowedFields = [
                "name",
                "type",
                "description",
                "company",
                "quantity",
                "purchase_price_per_meter",
                "sales_price_per_meter",
                "status"
            ];

            if (in_array($key, $allowedFields)) {
                $fields[] = "$key = :$key";
                $params[":$key"] = $value;
            }
        }

        if (empty($fields)) {
            return errorResponse("No valid fields to update", [], "NO_VALID_FIELDS");
        }

        $setQuery = implode(", ", $fields);
        $query = "UPDATE {$this->table_name} SET $setQuery, updated_at = NOW() WHERE id = :id";

        try {
            $stmt = $this->conn->prepare($query);

            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            $stmt->execute();
            $fetchQuery = "SELECT * FROM {$this->table_name} WHERE id = :id LIMIT 1";
            $fetchStmt = $this->conn->prepare($fetchQuery);
            $fetchStmt->bindValue(":id", $id);
            $fetchStmt->execute();
            $product = $fetchStmt->fetch(PDO::FETCH_ASSOC);

            return successResponse("Product updated successfully", $product);
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    /* --------------------------------------------------------------------
        DELETE PRODUCT (SOFT DELETE)
       -------------------------------------------------------------------- */
    public function deleteProduct($id)
    {
        if (empty($id)) {
            return errorResponse("Product ID is required", [], "MISSING_PRODUCT_ID");
        }

        // Validate product exists
        $existingProduct = $this->getProductById($id);
        if (!$existingProduct['success']) {
            return $existingProduct;
        }

        $query = "UPDATE {$this->table_name} SET status = 'archived', updated_at = NOW() WHERE id = :id";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(":id", $id);
            $stmt->execute();

            return successResponse("Product archived successfully");
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    /* --------------------------------------------------------------------
        GET PRODUCTS BY BRANCH WITH PAGINATION
       -------------------------------------------------------------------- */
    public function getProductsByBranchPaginated($branch_id, $page = 1, $limit = 10, $include_archived = false)
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
                $query = "SELECT * FROM {$this->table_name} 
                          WHERE branch_id = :branch_id 
                          ORDER BY created_at DESC 
                          LIMIT :limit OFFSET :offset";
            } else {
                $query = "SELECT * FROM {$this->table_name} 
                          WHERE branch_id = :branch_id AND status != 'archived' 
                          ORDER BY created_at DESC 
                          LIMIT :limit OFFSET :offset";
            }

            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(":branch_id", $branch_id, PDO::PARAM_INT);
            $stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
            $stmt->bindValue(":offset", $offset, PDO::PARAM_INT);
            $stmt->execute();

            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $paginationMeta = [
                'current_page' => (int) $page,
                'per_page' => (int) $limit,
                'total_products' => (int) $totalCount,
                'total_pages' => (int) $totalPages,
                'has_next' => $page < $totalPages,
                'has_prev' => $page > 1
            ];

            return successResponse(
                "Products retrieved successfully",
                $products,
                $paginationMeta
            );
        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }
}
