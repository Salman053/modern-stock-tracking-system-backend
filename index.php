<?php
require_once __DIR__ . '/models/user.model.php';

function sendResponse($statusCode, $data)
{
    // Clear any existing output
    if (ob_get_length()) ob_clean();

    http_response_code($statusCode);
    header('Content-Type: application/json');
    if ($data !== null) {
        echo json_encode($data);
    }

    exit();
}

function sendHttpResponse($statusCode, $responseData)
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($responseData);
    exit();
}

function successResponse($message = "", $data = [], $meta = [])
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

function errorResponse($message = "", $errors = [], $code = null)
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


function require_authenticated_user(): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['user_id'])) {
        sendResponse(401, ["message" => "Unauthenticated. Please log in."]);
    }

    $userModel = new UserModel();
    $user = $userModel->getUser($_SESSION['user_id']);

    if (!$user) {
        // User ID in session doesn't exist in DB
        sendResponse(401, ["message" => "Unauthenticated. User found." . $user["role"] . ""]);
    }

    return $user;
}
// Enable CORS and set headers
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// --- URL Parsing and Routing Setup ---

// 1. Get the path, stripped of the query string and leading/trailing slashes.
$request_path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

$segments = array_filter(explode('/', $request_path));
$segment_count = count($segments);

$api_base = $segments[1] ?? null;
$api_version = $segments[2] ?? null;

$resource = $segments[3] ?? null;

$action_or_id = $segments[4] ?? null;



try {
    // --- Route Handling ---
    switch ($resource) {
        case 'users':
            require_once __DIR__ . '/controllers/user.controller.php';
            $controller = new UserController();
            // Pass the action/ID segment to the controller for internal routing
            $controller->handleRequest($action_or_id);
            break;
        case 'branches':
            require_once __DIR__ . '/controllers/branch.controller.php';
            $controller = new BranchController();
            // Pass the full segments array as the controller expects it
            $controller->handleRequest($segments);
            break;
        case 'products':
            require_once __DIR__ . '/controllers/product.controller.php';
            $controller = new ProductController();
            $controller->handleRequest($segments);
            break;
        case 'suppliers':
            require_once __DIR__ . '/controllers/supplier.controller.php';
            $controller = new SupplierController();
            $controller->handleRequest($segments);
            break;
        case 'customers':
            require_once __DIR__ . '/controllers/customer.controller.php';
            $controller = new CustomerController();
            $controller->handleRequest($segments);
            break;
        default:
            throw new Exception("Endpoint not found. " . $resource, 404);
    }
} catch (Exception $e) {
    $statusCode = $e->getCode() ?: 500;

    if ($statusCode < 100 || $statusCode >= 600) {
        $statusCode = 500;
    }

    sendResponse($statusCode, ["message" => $e->getMessage()]);
}
