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
                if ($dueId === null) {
                    $this->handleGetBranchDues();
                } else {
                    $this->handleGetBranchDueById($dueId);
                }
                break;

            case "POST":
                $this->handleCreateBranchDue();
                break;

            case "PUT":
            case "PATCH":
                if ($dueId) {
                    $this->handleUpdateBranchDuePayment($dueId);
                } else {
                    sendResponse(400, errorResponse("Branch due ID required"));
                }
                break;

            default:
                sendResponse(405, errorResponse("Method not allowed"));
        }
    }

    /* --------------------------------------------------------------------
        LIST DUES + SUMMARY
    -------------------------------------------------------------------- */
    private function handleGetBranchDues()
    {
        $user = require_authenticated_user();

        $from_branch = $_GET["from_branch_id"] ?? null;
        $to_branch = $_GET["to_branch_id"] ?? null;
        $status = $_GET["status"] ?? null;
        $data_type = $_GET["data_type"] ?? "dues";

        if ($user["data"]["role"] === "branch-admin" || $user["data"]["role"] === "staff") {
            $to_branch = $user["data"]["branch_id"];
        }

        if ($data_type === "summary") {
            $result = $this->branchDueModel->getBranchDueSummary($to_branch);
        } else {
            $result = $this->branchDueModel->getBranchDues($from_branch, $to_branch, $status);
        }

        sendResponse($result["success"] ? 200 : 400, $result);
    }

    /* --------------------------------------------------------------------
        GET BY ID
    -------------------------------------------------------------------- */
    private function handleGetBranchDueById($dueId)
    {
        $user = require_authenticated_user();

        $result = $this->branchDueModel->getBranchDueById($dueId);

        if (!$result["success"]) {
            sendResponse(404, $result);
            return;
        }

        $dueBranch = $result["data"]["to_branch_id"];

        if ($user["data"]["role"] === "branch-admin" || $user["data"]["role"] === "staff") {
            if ($dueBranch !== $user["data"]["branch_id"]) {
                sendResponse(403, errorResponse("Access denied"));
                return;
            }
        }

        sendResponse(200, $result);
    }

    /* --------------------------------------------------------------------
        CREATE
    -------------------------------------------------------------------- */
    private function handleCreateBranchDue()
    {
        $user = require_authenticated_user();
        $data = json_decode(file_get_contents("php://input"), true);

        if ($user["data"]["role"] !== "super-admin" && $user["data"]["role"] !== "branch-admin") {
            sendResponse(403, errorResponse("Only admins can create branch dues"));
            return;
        }

        if (empty($data)) {
            sendResponse(400, errorResponse("Invalid JSON body"));
            return;
        }

        if (empty($data["from_branch_id"])) {
            $data["from_branch_id"] = $user["data"]["branch_id"];
        }

        $result = $this->branchDueModel->createBranchDue($data);
        sendResponse($result["success"] ? 201 : 400, $result);
    }

    /* --------------------------------------------------------------------
        UPDATE PAYMENT
    -------------------------------------------------------------------- */
    private function handleUpdateBranchDuePayment($dueId)
    {
        $user = require_authenticated_user();
        $data = json_decode(file_get_contents("php://input"), true);

        if (!isset($data["payment_amount"])) {
            sendResponse(400, errorResponse("Payment amount is required"));
            return;
        }

        if ($user["data"]["role"] !== "super-admin" && $user["data"]["role"] !== "branch-admin") {
            sendResponse(403, errorResponse("Only admins can update payments"));
            return;
        }

        // First check access
        $existing = $this->branchDueModel->getBranchDueById($dueId);
        if (!$existing["success"]) {
            sendResponse(404, $existing);
            return;
        }

        if ($user["data"]["role"] === "branch-admin") {
            if ($existing["data"]["to_branch_id"] !== $user["data"]["branch_id"]) {
                sendResponse(403, errorResponse("Access denied"));
                return;
            }
        }

        $result = $this->branchDueModel->updatePayment($dueId, $data["payment_amount"]);
        sendResponse($result["success"] ? 200 : 400, $result);
    }
}
