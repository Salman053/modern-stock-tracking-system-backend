<?php

require_once "utils/validation_utils.php";
require_once "config/database.php";
require_once "sales_item.model.php";
class SalesModel
{
    private $conn;
    private $table_name = "sales";
    private $db;

    public function __construct()
    {
        $this->db = new Database();
        $this->conn = $this->db->getConnection();
    }


    public function createSale($data)
    {
        try {
            // Validation of required fields
            $required_fields = ["user_id", "branch_id", "sale_date", "total_amount", "paid_amount"];
            $validateErrors = validation_utils::validateRequired($data, $required_fields);

            if (!empty($validateErrors)) {
                return errorResponse("Validation failed", $validateErrors, "VALIDATION_ERROR");
            }

            // Validate numeric fields
            if (!is_numeric($data["total_amount"]) || $data["total_amount"] <= 0) {
                return errorResponse(
                    "Invalid total amount",
                    ["total_amount" => "Total amount must be a positive number"],
                    "INVALID_TOTAL_AMOUNT"
                );
            }

            if (!is_numeric($data["paid_amount"]) || $data["paid_amount"] < 0) {
                return errorResponse(
                    "Invalid paid amount",
                    ["paid_amount" => "Paid amount must be a non-negative number"],
                    "INVALID_PAID_AMOUNT"
                );
            }

            if (isset($data["discount"]) && (!is_numeric($data["discount"]) || $data["discount"] < 0)) {
                return errorResponse(
                    "Invalid discount",
                    ["discount" => "Discount must be a non-negative number"],
                    "INVALID_DISCOUNT"
                );
            }

            if (isset($data["profit"]) && !is_numeric($data["profit"])) {
                return errorResponse(
                    "Invalid profit",
                    ["profit" => "Profit must be a numeric value"],
                    "INVALID_PROFIT"
                );
            }

            if (!$this->userExists($data['user_id'])) {
                return errorResponse("Invalid user ID", [], "INVALID_USER");
            }

            if (!$this->branchExists($data['branch_id'])) {
                return errorResponse("Invalid branch ID", [], "INVALID_BRANCH");
            }

            if (isset($data['customer_id']) && !$this->customerExists($data['customer_id'])) {
                return errorResponse("Invalid customer ID", [], "INVALID_CUSTOMER");
            }

            // Validate sale date format
            if (!validation_utils::validateDate($data["sale_date"])) {
                return errorResponse(
                    "Invalid sale date format",
                    ["sale_date" => "Sale date must be in YYYY-MM-DD format"],
                    "INVALID_DATE_FORMAT"
                );
            }

            $data = validation_utils::sanitizeInput($data);

            // Calculate if fully paid
            $is_fully_paid = ($data['paid_amount'] >= ($data['total_amount'] - ($data['discount'] ?? 0)));

            $query = "INSERT INTO " . $this->table_name . " 
                     SET user_id = :user_id, 
                         branch_id = :branch_id,
                         customer_id = :customer_id,
                         sale_date = :sale_date,
                         total_amount = :total_amount,
                         paid_amount = :paid_amount,
                         discount = :discount,
                         profit = :profit,
                         note = :note,
                         is_fully_paid = :is_fully_paid,
                         created_at = NOW()";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":user_id", $data['user_id']);
            $stmt->bindParam(":branch_id", $data['branch_id']);
            $customer_id = $data['customer_id'] ?? null;
            $stmt->bindParam(":customer_id", $customer_id);
            $stmt->bindParam(":sale_date", $data['sale_date']);
            $stmt->bindParam(":total_amount", $data['total_amount']);
            $stmt->bindParam(":paid_amount", $data['paid_amount']);
            $discount = $data['discount'] ?? 0;
            $stmt->bindParam(":discount", $discount);
            $profit = $data['profit'] ?? 0;
            $stmt->bindParam(":profit", $profit);
            $note = $data['note'] ?? null;
            $stmt->bindParam(":note", $note);
            $stmt->bindParam(":is_fully_paid", $is_fully_paid, PDO::PARAM_BOOL);

            if ($stmt->execute()) {
                $saleData = [
                    "id" => $this->conn->lastInsertId(),
                    "sale_date" => $data['sale_date'],
                    "total_amount" => $data['total_amount'],
                    "paid_amount" => $data['paid_amount'],
                    "is_fully_paid" => $is_fully_paid,
                    "branch_id" => $data['branch_id']
                ];

                return successResponse(
                    "Sale created successfully",
                    $saleData
                );
            } else {
                return errorResponse(
                    "Failed to create sale",
                    [],
                    "INSERT_FAILED"
                );
            }

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while creating sale",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }


    public function createSaleWithItems($saleData, $itemsData)
    {
        try {
            $this->conn->beginTransaction();

            // Create sale
            $saleResult = $this->createSale($saleData);

            if (!$saleResult['success']) {
                $this->conn->rollBack();
                return $saleResult;
            }

            $saleId = $saleResult['data']['id'];

            // Add sale items
            $salesItemModel = new SalesItemModel();
            $totalProfit = 0;

            foreach ($itemsData as $item) {
                $item['sale_id'] = $saleId;
                $item['user_id'] = $saleData['user_id'];
                $item['branch_id'] = $saleData['branch_id'];

                $itemResult = $salesItemModel->addSaleItem($item);

                if (!$itemResult['success']) {
                    $this->conn->rollBack();
                    return $itemResult;
                }

                // Accumulate profit from items
                if (isset($itemResult['data']['item_profit'])) {
                    $totalProfit += $itemResult['data']['item_profit'];
                }
            }

            // Update sale with total profit
            $updateQuery = "UPDATE {$this->table_name} SET profit = :profit WHERE id = :id";
            $updateStmt = $this->conn->prepare($updateQuery);
            $updateStmt->bindParam(":profit", $totalProfit);
            $updateStmt->bindParam(":id", $saleId);
            $updateStmt->execute();

            $this->conn->commit();

            // Get complete sale details
            $completeSale = $this->getSaleById($saleId);

            return successResponse(
                "Sale with items created successfully",
                $completeSale['data']
            );

        } catch (PDOException $e) {
            $this->conn->rollBack();
            return errorResponse(
                "Database error occurred while creating sale with items",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    public function getSales($branch_id = null, $user_id = null, $start_date = null, $end_date = null, $include_cancelled = false)
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

            if (!$include_cancelled) {
                $whereClause .= " AND s.status != 'cancelled'";
            }

            if ($start_date) {
                $whereClause .= " AND s.sale_date >= :start_date";
                $params[":start_date"] = $start_date;
            }

            if ($end_date) {
                $whereClause .= " AND s.sale_date <= :end_date";
                $params[":end_date"] = $end_date;
            }

            $whereClause .= " AND u.status = 'active' AND b.status = 'active'";

            $query = "SELECT s.*, 
                             u.username as created_by, 
                             b.name as branch_name,
                             c.name as customer_name,
                             c.phone as customer_phone
                      FROM {$this->table_name} s
                      LEFT JOIN users u ON s.user_id = u.id
                      LEFT JOIN branches b ON s.branch_id = b.id
                      LEFT JOIN customers c ON s.customer_id = c.id AND c.status != 'archived'
                      {$whereClause} 
                      ORDER BY s.sale_date DESC, s.created_at DESC";

            $stmt = $this->conn->prepare($query);

            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            $stmt->execute();

            $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return successResponse(
                "Sales retrieved successfully",
                $sales
            );

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching sales",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    public function getSaleById($id, $include_items = true)
    {
        if (empty($id)) {
            return errorResponse("Sale ID is required", [], "MISSING_id");
        }

        try {
            $query = "SELECT s.*, 
                             u.username as created_by, 
                             b.name as branch_name,
                             c.name as customer_name,
                             c.phone as customer_phone,
                             c.address as customer_address
                      FROM {$this->table_name} s
                      LEFT JOIN users u ON s.user_id = u.id AND u.status = 'active'
                      LEFT JOIN branches b ON s.branch_id = b.id AND b.status = 'active'
                      LEFT JOIN customers c ON s.customer_id = c.id AND c.status != 'archived'
                      WHERE s.id = ? AND s.status != 'archived'";

            $stmt = $this->conn->prepare($query);
            $stmt->execute([$id]);

            $sale = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$sale) {
                return errorResponse(
                    "Sale not found",
                    [],
                    "SALE_NOT_FOUND"
                );
            }

            // Include sale items if requested
            if ($include_items) {
                $salesItemModel = new SalesItemModel();
                $itemsResult = $salesItemModel->getSaleItemsBySaleId($id);

                if ($itemsResult['success']) {
                    $sale['items'] = $itemsResult['data'];
                }
            }

            return successResponse("Sale retrieved successfully", $sale);

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching sale",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    public function updateSale($id, $data)
    {
        if (empty($id)) {
            return errorResponse("Sale ID is required", [], "MISSING_id");
        }

        if (empty($data)) {
            return errorResponse("No data provided for update", [], "MISSING_UPDATE_DATA");
        }

        try {
            // Check if sale exists
            $existingSale = $this->getSaleById($id, false);
            if (!$existingSale['success']) {
                return $existingSale;
            }

            // Sanitize data
            $data = validation_utils::sanitizeInput($data);

            // Build dynamic update query
            $allowedFields = [
                "customer_id",
                "sale_date",
                "total_amount",
                "paid_amount",
                "discount",
                "profit",
                "note",
                "is_fully_paid",
                "status"
            ];
            $fields = [];
            $params = [":id" => $id];

            foreach ($data as $key => $value) {
                if (in_array($key, $allowedFields)) {
                    if ($key === 'paid_amount' || $key === 'total_amount' || $key === 'discount') {
                        $fields[] = "is_fully_paid = :is_fully_paid";
                        $new_paid = $data['paid_amount'] ?? $existingSale['data']['paid_amount'];
                        $new_total = $data['total_amount'] ?? $existingSale['data']['total_amount'];
                        $new_discount = $data['discount'] ?? $existingSale['data']['discount'];
                        $is_fully_paid = ($new_paid >= ($new_total - $new_discount));
                        $params[":is_fully_paid"] = $is_fully_paid;
                    }

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
                return successResponse("Sale updated successfully");
            } else {
                return errorResponse(
                    "Failed to update sale",
                    [],
                    "UPDATE_FAILED"
                );
            }

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while updating sale",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    public function cancelSale($id)
    {
        if (empty($id)) {
            return errorResponse("Sale ID is required", [], "MISSING_id");
        }

        try {
            // Check if sale exists
            $existingSale = $this->getSaleById($id, false);
            if (!$existingSale['success']) {
                return $existingSale;
            }

            $query = "UPDATE {$this->table_name} 
                      SET status = 'cancelled', updated_at = NOW() 
                      WHERE id = :id";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":id", $id);

            if ($stmt->execute()) {
                // Restore product quantities from sale items
                $salesItemModel = new SalesItemModel();
                $restoreResult = $salesItemModel->restoreProductQuantities($id);

                if (!$restoreResult['success']) {
                    error_log("Failed to restore product quantities for sale {$id}: " .
                        json_encode($restoreResult));
                }

                return successResponse("Sale cancelled successfully");
            } else {
                return errorResponse(
                    "Failed to cancel sale",
                    [],
                    "UPDATE_FAILED"
                );
            }

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while cancelling sale",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    public function getSalesByDateRange($branch_id, $start_date, $end_date)
    {
        try {
            $query = "SELECT s.*, 
                             u.username as created_by, 
                             b.name as branch_name,
                             c.name as customer_name,
                             SUM(s.total_amount) as daily_total,
                             SUM(s.profit) as daily_profit
                      FROM {$this->table_name} s
                      LEFT JOIN users u ON s.user_id = u.id
                      LEFT JOIN branches b ON s.branch_id = b.id
                      LEFT JOIN customers c ON s.customer_id = c.id AND c.status != 'archived'
                      WHERE s.branch_id = :branch_id 
                        AND s.sale_date BETWEEN :start_date AND :end_date
                        AND s.status != 'cancelled'
                        AND u.status = 'active'
                        AND b.status = 'active'
                      GROUP BY s.sale_date, s.id
                      ORDER BY s.sale_date DESC";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":branch_id", $branch_id);
            $stmt->bindParam(":start_date", $start_date);
            $stmt->bindParam(":end_date", $end_date);
            $stmt->execute();

            $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return successResponse(
                "Sales retrieved successfully",
                $sales
            );

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching sales",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    public function getSalesSummary($branch_id = null, $start_date = null, $end_date = null)
    {
        try {
            $whereClause = "WHERE s.status != 'cancelled'";
            $params = [];

            if ($branch_id) {
                $whereClause .= " AND s.branch_id = :branch_id";
                $params[":branch_id"] = $branch_id;
            }

            if ($start_date) {
                $whereClause .= " AND s.sale_date >= :start_date";
                $params[":start_date"] = $start_date;
            }

            if ($end_date) {
                $whereClause .= " AND s.sale_date <= :end_date";
                $params[":end_date"] = $end_date;
            }

            $query = "SELECT 
                        COUNT(*) as total_sales,
                        SUM(s.total_amount) as total_revenue,
                        SUM(s.paid_amount) as total_paid,
                        SUM(s.discount) as total_discount,
                        SUM(s.profit) as total_profit,
                        AVG(s.total_amount) as average_sale,
                        MIN(s.sale_date) as first_sale_date,
                        MAX(s.sale_date) as last_sale_date
                      FROM {$this->table_name} s
                      {$whereClause}";

            $stmt = $this->conn->prepare($query);

            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            $stmt->execute();

            $summary = $stmt->fetch(PDO::FETCH_ASSOC);

            return successResponse(
                "Sales summary retrieved successfully",
                $summary
            );

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching sales summary",
                ["database" => $e->getMessage()],
                "DATABASE_EXCEPTION"
            );
        }
    }

    public function getSalesByBranchPaginated($branch_id, $page = 1, $limit = 10, $include_cancelled = false)
    {
        if (empty($branch_id)) {
            return errorResponse("Branch ID is required", [], "MISSING_BRANCH_ID");
        }

        try {
            $offset = ($page - 1) * $limit;

            // Count total records
            $countWhere = "WHERE s.branch_id = :branch_id";
            if (!$include_cancelled) {
                $countWhere .= " AND s.status != 'cancelled'";
            }

            $countQuery = "SELECT COUNT(*) as total FROM {$this->table_name} s {$countWhere}";

            $countStmt = $this->conn->prepare($countQuery);
            $countStmt->bindValue(":branch_id", $branch_id);
            $countStmt->execute();
            $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            $totalPages = ceil($totalCount / $limit);

            // Get paginated data
            $whereClause = "WHERE s.branch_id = :branch_id";
            if (!$include_cancelled) {
                $whereClause .= " AND s.status != 'cancelled'";
            }

            $query = "SELECT s.*, 
                             u.username as created_by, 
                             b.name as branch_name,
                             c.name as customer_name
                      FROM {$this->table_name} s
                      LEFT JOIN users u ON s.user_id = u.id AND u.status = 'active'
                      LEFT JOIN branches b ON s.branch_id = b.id AND b.status = 'active'
                      LEFT JOIN customers c ON s.customer_id = c.id AND c.status != 'archived'
                      {$whereClause} 
                      ORDER BY s.sale_date DESC, s.created_at DESC 
                      LIMIT :limit OFFSET :offset";

            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(":branch_id", $branch_id, PDO::PARAM_INT);
            $stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
            $stmt->bindValue(":offset", $offset, PDO::PARAM_INT);
            $stmt->execute();

            $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $paginationMeta = [
                'current_page' => (int) $page,
                'per_page' => (int) $limit,
                'total_sales' => (int) $totalCount,
                'total_pages' => (int) $totalPages,
                'has_next' => $page < $totalPages,
                'has_prev' => $page > 1
            ];

            return successResponse(
                "Sales retrieved successfully",
                $sales,
                $paginationMeta
            );

        } catch (PDOException $e) {
            return errorResponse(
                "Database error occurred while fetching sales",
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

    private function customerExists($customer_id)
    {
        try {
            $query = "SELECT id FROM customers WHERE id = ? AND status != 'archived'";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$customer_id]);
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