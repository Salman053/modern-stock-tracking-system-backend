<?php
require_once "models/branch_due.model.php";

class BranchDueController
{
    private $branchDueModel;

    public function __construct()
    {
        $this->branchDueModel = new BranchDueModel();
    }

    public function handleRequest($segments)
    {
        $method = $_SERVER["REQUEST_METHOD"];
        $dueId = $segments[4] ?? null;

        switch ($method) {
            case "GET":
                $this->handleGetRequest($segments);
                break;

            case "POST":
                $this->handlePostRequest($segments);
                break;

            case "PUT":
            case "PATCH":
                $this->handlePutPatchRequest($segments);
                break;

            case "DELETE":
                $this->handleDeleteRequest($segments);
                break;

            default:
                sendResponse(405, errorResponse("Method not allowed"));
        }
    }

    /* --------------------------------------------------------------------
        HANDLE GET REQUESTS
    -------------------------------------------------------------------- */
    private function handleGetRequest($segments)
    {
        $dueId = $segments[4] ?? null;
        $action = $segments[5] ?? null;

        if ($dueId === null) {
            $this->handleGetBranchDues();
        } elseif ($action === null) {
            $this->handleGetBranchDueById($dueId);
        } else {
            // sendResponse(200,["data"=>"test"]);
            $this->handleGetAction($dueId, $action);
        }
    }

    /* --------------------------------------------------------------------
        HANDLE POST REQUESTS
    -------------------------------------------------------------------- */
    private function handlePostRequest($segments)
    {
        $action = $segments[5] ?? null;

        if ($action === null) {
            $this->handleCreateBranchDue();
        } elseif ($action === "overdue-status") {
            $this->handleUpdateOverdueStatuses();
        } else {
            sendResponse(404, errorResponse("Invalid endpoint"));
        }
    }

    /* --------------------------------------------------------------------
        HANDLE PUT/PATCH REQUESTS
    -------------------------------------------------------------------- */
    private function handlePutPatchRequest($segments)
    {
        $dueId = $segments[4] ?? null;
        $action = $segments[5] ?? null;

        if ($dueId === null) {
            sendResponse(400, errorResponse("Branch due ID required"));
            return;
        }

        if ($action === null) {
            $this->handleUpdateBranchDuePayment($dueId);
        } elseif ($action === "cancel") {
            $this->handleCancelBranchDue($dueId);
        } else {
            sendResponse(404, errorResponse("Invalid endpoint"));
        }
    }

    /* --------------------------------------------------------------------
        HANDLE DELETE REQUESTS
    -------------------------------------------------------------------- */
    private function handleDeleteRequest($segments)
    {
        $dueId = $segments[4] ?? null;

        if ($dueId === null) {
            sendResponse(400, errorResponse("Branch due ID required"));
            return;
        }

        $this->handleDeleteBranchDue($dueId);
    }

    /* --------------------------------------------------------------------
        LIST DUES + SUMMARY + OVERDUE
    -------------------------------------------------------------------- */
    private function handleGetBranchDues()
    {
        $user = require_authenticated_user();

        $branch_id = $_GET["branch_id"] ?? null;
        $supplier_id = $_GET["supplier_id"] ?? null;
        $status = $_GET["status"] ?? null;
        $data_type = $_GET["data_type"] ?? "dues";
        $overdue = $_GET["overdue"] ?? false;

        // For branch-admin and staff, restrict to their branch
        if ($user["data"]["role"] === "branch-admin" || $user["data"]["role"] === "staff") {
            $branch_id = $user["data"]["branch_id"];
        }

        // Handle different data types
        switch (true) {
            case $data_type === "summary":
                $result = $this->branchDueModel->getBranchDueSummary($branch_id, $supplier_id);
                break;

            case $overdue === "true" || $overdue === "1":
                $result = $this->branchDueModel->getOverdueDues($branch_id, $supplier_id);
                break;

            default:

                $result = $this->branchDueModel->getBranchDues($branch_id, $status, $supplier_id);
                // sendResponse($result["success"] ? 200 : 400, ["data" => "test"]);
                break;
        }

        sendResponse($result["success"] ? 200 : 400, $result);
    }

    /* --------------------------------------------------------------------
        GET BRANCH DUE BY ID
    -------------------------------------------------------------------- */
    private function handleGetBranchDueById($dueId)
    {
        $user = require_authenticated_user();

        $result = $this->branchDueModel->getBranchDuesById($dueId);

        if (!$result["success"]) {
            sendResponse(404, $result);
            return;
        }

        // Check access control
        $dueData = $result["data"];

        if ($user["data"]["role"] !== "branch-admin" || $user["data"]["role"] === "staff") {
            // if ($dueData["branch_id"] !== $user["data"]["branch_id"]) {
            sendResponse(403, errorResponse("Access denied - You can only view dues for your branch"));
            return;
            // }
        }

        sendResponse(200, $result);
    }

    /* --------------------------------------------------------------------
        GET SPECIFIC ACTIONS
    -------------------------------------------------------------------- */
    private function handleGetAction($dueId, $action)
    {
        $user = require_authenticated_user();

        switch ($action) {
            case "by-stock-movement":
                $this->handleGetDueByStockMovement($dueId);
                break;

            case "supplier-dues":
                $this->handleGetSupplierDues($dueId); // $dueId here is actually supplier_id
                break;

            default:
                sendResponse(404, errorResponse("Invalid action"));
        }
    }

    /* --------------------------------------------------------------------
        GET DUE BY STOCK MOVEMENT ID
    -------------------------------------------------------------------- */
    private function handleGetDueByStockMovement($stockMovementId)
    {
        $user = require_authenticated_user();

        $result = $this->branchDueModel->getDueByStockMovement($stockMovementId);

        if (!$result["success"]) {
            sendResponse(404, $result);
            return;
        }

        // Check access control
        $dueData = $result["data"];

        if ($user["data"]["role"] !== "branch-admin" || $user["data"]["role"] === "super-admin") {
            // if ($dueData["branch_id"] !== $user["data"]["branch_id"]) {
            sendResponse(403, errorResponse("Access denied - You can only view dues for your branch"));
            return;
            // }
        }

        sendResponse($result["success"] ? 200 : 400, $result);

    }

    /* --------------------------------------------------------------------
        GET SUPPLIER DUES
    -------------------------------------------------------------------- */
    private function handleGetSupplierDues($supplierId)
    {
        $user = require_authenticated_user();

        if ($user["data"]["role"] !== "super-admin" && $user["data"]["role"] !== "branch-admin") {
            sendResponse(403, errorResponse("Only admins can view supplier dues"));
            return;
        }

        $status = $_GET["status"] ?? null;
        $result = $this->branchDueModel->getDuesBySupplier($supplierId, $status);

        sendResponse($result["success"] ? 200 : 400, $result);
    }

    /* --------------------------------------------------------------------
        CREATE BRANCH DUE
    -------------------------------------------------------------------- */
    private function handleCreateBranchDue()
    {
        $user = require_authenticated_user();
        $data = json_decode(file_get_contents("php://input"), true);

        // Check permissions
        if ($user["data"]["role"] !== "super-admin" && $user["data"]["role"] !== "branch-admin") {
            sendResponse(403, errorResponse("Only admins can create branch dues"));
            return;
        }

        if (empty($data)) {
            sendResponse(400, errorResponse("Invalid JSON body"));
            return;
        }

        // For branch-admin, set branch_id if not provided
        if ($user["data"]["role"] === "branch-admin") {
            if (empty($data["branch_id"])) {
                $data["branch_id"] = $user["data"]["branch_id"];
            } else if ($data["branch_id"] !== $user["data"]["branch_id"]) {
                sendResponse(403, errorResponse("You can only create dues for your own branch"));
                return;
            }
        }

        // Validate required fields
        $required = ["branch_id", "stock_movement_id", "due_date", "total_amount"];
        $missing = array_diff($required, array_keys($data));

        if (!empty($missing)) {
            sendResponse(400, errorResponse("Missing required fields: " . implode(", ", $missing)));
            return;
        }

        $result = $this->branchDueModel->createBranchDue($data);
        sendResponse($result["success"] ? 201 : 400, $result);
    }

    /* --------------------------------------------------------------------
        UPDATE BRANCH DUE PAYMENT
    -------------------------------------------------------------------- */
    private function handleUpdateBranchDuePayment($dueId)
    {
        $user = require_authenticated_user();
        $data = json_decode(file_get_contents("php://input"), true);

        // Validate input
        if (!isset($data["payment_amount"]) || !is_numeric($data["payment_amount"])) {
            sendResponse(400, errorResponse("Valid payment amount is required"));
            return;
        }

        if ($data["payment_amount"] <= 0) {
            sendResponse(400, errorResponse("Payment amount must be positive"));
            return;
        }

        // Check permissions
        if ($user["data"]["role"] !== "super-admin" && $user["data"]["role"] !== "branch-admin") {
            sendResponse(403, errorResponse("Only admins can update payments"));
            return;
        }

        // First check if due exists and get data
        $existing = $this->branchDueModel->getBranchDueById($dueId);
        if (!$existing["success"]) {
            sendResponse(404, $existing);
            return;
        }

        $dueData = $existing["data"];

        // For branch-admin, check if they own this due
        if ($user["data"]["role"] === "branch-admin") {
            if ($dueData["branch_id"] !== $user["data"]["branch_id"]) {
                sendResponse(403, errorResponse("You can only update payments for dues in your branch"));
                return;
            }
        }

        // Check if due is already paid or cancelled
        if ($dueData["status"] === "paid") {
            sendResponse(400, errorResponse("Cannot update payment for already paid due"));
            return;
        }

        if ($dueData["status"] === "cancelled") {
            sendResponse(400, errorResponse("Cannot update payment for cancelled due"));
            return;
        }

        // Process payment
        $result = $this->branchDueModel->updatePayment($dueId, $data["payment_amount"]);
        sendResponse($result["success"] ? 200 : 400, $result);
    }

    /* --------------------------------------------------------------------
        DELETE BRANCH DUE
    -------------------------------------------------------------------- */
    private function handleDeleteBranchDue($dueId)
    {
        $user = require_authenticated_user();

        // Check permissions
        if ($user["data"]["role"] !== "super-admin") {
            sendResponse(403, errorResponse("Only super admin can delete dues"));
            return;
        }

        // First check if due exists
        $existing = $this->branchDueModel->getBranchDueById($dueId);
        if (!$existing["success"]) {
            sendResponse(404, $existing);
            return;
        }

        $dueData = $existing["data"];

        // Check if due has payments
        if ($dueData["paid_amount"] > 0) {
            sendResponse(400, errorResponse("Cannot delete due with existing payments. Consider cancelling instead."));
            return;
        }

        $result = $this->branchDueModel->deleteBranchDue($dueId);
        sendResponse($result["success"] ? 200 : 400, $result);
    }

    /* --------------------------------------------------------------------
        CANCEL BRANCH DUE
    -------------------------------------------------------------------- */
    private function handleCancelBranchDue($dueId)
    {
        $user = require_authenticated_user();

        // Check permissions
        if ($user["data"]["role"] !== "super-admin" && $user["data"]["role"] !== "branch-admin") {
            sendResponse(403, errorResponse("Only admins can cancel dues"));
            return;
        }

        // First check if due exists
        $existing = $this->branchDueModel->getBranchDueById($dueId);
        if (!$existing["success"]) {
            sendResponse(404, $existing);
            return;
        }

        $dueData = $existing["data"];

        // For branch-admin, check if they own this due
        if ($user["data"]["role"] === "branch-admin") {
            if ($dueData["branch_id"] !== $user["data"]["branch_id"]) {
                sendResponse(403, errorResponse("You can only cancel dues in your branch"));
                return;
            }
        }

        $result = $this->branchDueModel->cancelBranchDue($dueId);
        sendResponse($result["success"] ? 200 : 400, $result);
    }

    /* --------------------------------------------------------------------
        UPDATE OVERDUE STATUSES (CRON JOB ENDPOINT)
    -------------------------------------------------------------------- */
    private function handleUpdateOverdueStatuses()
    {
        $user = require_authenticated_user();

        // Check permissions - only super admin can trigger this manually
        if ($user["data"]["role"] !== "super-admin") {
            sendResponse(403, errorResponse("Only super admin can update overdue statuses"));
            return;
        }

        $result = $this->branchDueModel->updateOverdueStatuses();
        sendResponse($result["success"] ? 200 : 400, $result);
    }
}