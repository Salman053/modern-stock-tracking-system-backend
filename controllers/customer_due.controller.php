<?php
require_once "models/customer_due.model.php";

class CustomerDueController
{
    private $model;

    public function __construct()
    {
        $this->model = new CustomerDueModel();
    }

    public function handleRequest($segments)
    {   
        $method = $_SERVER['REQUEST_METHOD'];
        $dueId = $segments[4] ?? null;

        switch ($method) {
            case 'GET':
                if ($dueId) $this->getById($dueId);
                else $this->getList();
                break;

            case 'POST':
                $this->createDue();
                break;

            case 'PUT':
            case 'PATCH':
                if ($dueId) $this->updatePayment($dueId);
                else sendResponse(400, errorResponse("Due ID required"));
                break;

            default:
                sendResponse(405, errorResponse("Method not allowed"));
        }
    }

    private function getList()
    {
        $user = require_authenticated_user();

        $customer_id = $_GET['customer_id'] ?? null;
        $branch_id = $_GET['branch_id'] ?? null;
        $status = $_GET['status'] ?? null;
        $type = $_GET['data_type'] ?? "dues";

        if ($user['data']['role'] !== 'super-admin') {
            $branch_id = $user['data']['branch_id'];
        }

        if ($type === "summary")
            $result = $this->model->getCustomerDueSummary($branch_id);
        else
            $result = $this->model->getCustomerDues($customer_id, $branch_id, $status);

        sendResponse($result["success"] ? 200 : 400, $result);
    }

    private function getById($id)
    {
        $user = require_authenticated_user();

        $result = $this->model->getCustomerDueById($id);

        if (!$result["success"]) {
            sendResponse(404, $result);
            return;
        }

        if ($user['data']['role'] !== 'super-admin' &&
            $result['data']['branch_id'] !== $user['data']['branch_id']) {
            sendResponse(403, errorResponse("Access denied"));
            return;
        }

        sendResponse(200, $result);
    }

    private function createDue()
    {
        $data = json_decode(file_get_contents("php://input"), true);
        if (!$data) {
            sendResponse(400, errorResponse("Invalid JSON"));
            return;
        }

        $user = require_authenticated_user();

        if ($user['data']['role'] === 'staff') {
            sendResponse(403, errorResponse("Forbidden"));
            return;
        }

        if (!$data['branch_id'] && $user['data']['role'] !== 'super-admin') {
            $data['branch_id'] = $user['data']['branch_id'];
        }

        $result = $this->model->createCustomerDue($data);
        sendResponse($result["success"] ? 201 : 400, $result);
    }

    private function updatePayment($id)
    {
        $data = json_decode(file_get_contents("php://input"), true);

        if (!isset($data['payment_amount'])) {
            sendResponse(400, errorResponse("Payment amount required"));
            return;
        }

        $user = require_authenticated_user();

        if ($user['data']['role'] === 'staff') {
            sendResponse(403, errorResponse("Forbidden"));
            return;
        }

        $existing = $this->model->getCustomerDueById($id);
        if (!$existing['success']) {
            sendResponse(404, $existing);
            return;
        }

        if ($user['data']['role'] === 'branch-admin' &&
            $existing['data']['branch_id'] !== $user['data']['branch_id']) {
            sendResponse(403, errorResponse("Access denied"));
            return;
        }

        $result = $this->model->updatePayment($id, $data['payment_amount']);
        sendResponse($result["success"] ? 200 : 400, $result);
    }
}
