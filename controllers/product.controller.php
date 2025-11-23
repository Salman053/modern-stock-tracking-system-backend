<?php
require_once "models/product.model.php";
require_once "utils/auth_utils.php";

class ProductController
{
    private $productModel;

    public function __construct()
    {
        $this->productModel = new ProductModel();
    }

    public function handleRequest($segments)
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $productId = $segments[4] ?? null;

        switch ($method) {
            case 'GET':
                if ($productId === null) {
                    $this->handleGetProducts();
                } else {
                    $this->handleGetProductById($productId);
                }
                break;
            case 'POST':
                $this->handleAddProduct();
                break;
            case 'PUT':
            case 'PATCH':
                if ($productId) {
                    $this->handleUpdateProduct($productId);
                } else {
                    $response = errorResponse("Product ID required for update");
                    sendResponse(400, $response);
                }
                break;
            case 'DELETE':
                if ($productId) {
                    $this->handleDeleteProduct($productId);
                } else {
                    $response = errorResponse("Product ID required for deletion");
                    sendResponse(400, $response);
                }
                break;
            default:
                $response = errorResponse("Method not allowed");
                sendResponse(405, $response);
                return;
        }
    }

    private function handleGetProducts()
    {
        $user = require_authenticated_user();
        if (!$user['data']['role']) {
            $response = errorResponse("Unauthorized or session expired");
            sendResponse(401, $response);
            return;
        }

        // Get query parameters for filtering
        $user_id = $_GET['user_id'] ?? null;
        $branch_id = $_GET['branch_id'] ?? null;
        $status = $_GET['status'] ?? null;

        try {
            $result = $this->productModel->getProducts($user_id, $branch_id,$status);

            if ($result["success"]) {
                sendResponse(200, $result);
            } else {
                $response = errorResponse("Failed to fetch products: " . $result["message"]);
                sendResponse(500, $response);
            }
            return;
        } catch (Exception $e) {
            $response = errorResponse("Database error: " . $e->getMessage());
            sendResponse(500, $response);
            return;
        }
    }

    private function handleGetProductById($productId)
    {
        $user = require_authenticated_user();
        if (!$user['data']['role']) {
            $response = errorResponse("Unauthorized or session expired");
            sendResponse(401, $response);
            return;
        }

        try {
            $result = $this->productModel->getProductById($productId);

            if ($result["success"]) {
                sendResponse(200, $result);
            } else {
                $response = errorResponse("Product not found");
                sendResponse(404, $response);
            }
            return;
        } catch (Exception $e) {
            $response = errorResponse("Database error: " . $e->getMessage());
            sendResponse(500, $response);
            return;
        }
    }

    private function handleAddProduct()
    {
        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data)) {
            $response = errorResponse("Invalid or empty JSON body");
            sendResponse(400, $response);
            return;
        }

        $user = require_authenticated_user(true);

        // Check if user has permission to add products
        if ($user['data']["role"] !== "super-admin" && $user['data']["role"] !== "branch-admin") {
            $response = errorResponse("Forbidden: Only admins can add products");
            sendResponse(403, $response);
            return;
        }
        if(!password_verify($data["admin_password"],$user["data"]["password"])){
             $response = errorResponse("Forbidden: Only branch can add products");
            sendResponse(403, $response);
            return;
        }

        try {
            $result = $this->productModel->addProduct($data);

            if ($result["success"]) {
                $response = successResponse("Product added successfully");
                sendResponse(201, $response);
            } else {
                sendResponse(400, $result);
            }
            return;
        } catch (Exception $e) {
            $response = errorResponse("Database error: " . $e->getMessage());
            sendResponse(500, $response);
            return;
        }
    }

    private function handleUpdateProduct($productId)
    {
        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data)) {
            $response = errorResponse("Invalid or empty JSON body");
            sendResponse(400, $response);
            return;
        }

        $user = require_authenticated_user();

        // Check if user has permission to update products
        if ($user['data']["role"] !== "super-admin" && $user['data']["role"] !== "branch-admin") {
            $response = errorResponse("Forbidden: Only admins can update products");
            sendResponse(403, $response);
            return;
        }

        try {
            $result = $this->productModel->updateProduct($productId, $data);

            if ($result["success"]) {
                $response = successResponse("Product updated successfully");
                sendResponse(200, $response);
            } else {
                $response = errorResponse($result["message"] ?? "Update failed");
                sendResponse(400, $response);
            }
            return;
        } catch (Exception $e) {
            $response = errorResponse("Database error: " . $e->getMessage());
            sendResponse(500, $response);
            return;
        }
    }

    private function handleDeleteProduct($productId)
    {
        $user = require_authenticated_user();

        if ($user['data']["role"] !== "super-admin" && $user['data']["role"] !== "branch-admin") {
            $response = errorResponse("Forbidden: Only admins can delete products");
            sendResponse(403, $response);
            return;
        }

        try {
            $result = $this->productModel->deleteProduct($productId);

            if ($result["success"]) {
                $response = successResponse("Product deleted successfully");
                sendResponse(200, $response);
            } else {
                $response = errorResponse("Product not found or deletion failed");
                sendResponse(404, $response);
            }
            return;
        } catch (Exception $e) {
            $response = errorResponse("Database error: " . $e->getMessage());
            sendResponse(500, $response);
            return;
        }
    }
}
