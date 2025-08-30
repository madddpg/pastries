<?php
header('Content-Type: application/json');
session_start();

// Basic server-side admin gate - adjust to your auth scheme if needed.
// If you use a different session key for admin, replace this check.
if (empty($_SESSION['admin_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

require_once __DIR__ . '/../database/db_connect.php';

$db = new Database();
$con = $db->opencon();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_REQUEST['action'] ?? 'list';

try {
    // List all toppings for admin table
    if ($method === 'GET' && $action === 'list') {
        $toppings = $db->fetch_toppings_pdo();
        echo json_encode(['success' => true, 'toppings' => $toppings]);
        exit;
    }

    // Return only active toppings for public site (product modal)
    if ($method === 'GET' && $action === 'active') {
        $toppings = $db->fetch_active_toppings();
        echo json_encode(['success' => true, 'toppings' => $toppings]);
        exit;
    }

    // Create topping
    if ($method === 'POST' && $action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $price = isset($_POST['price']) ? floatval($_POST['price']) : 0.00;
        $status = (isset($_POST['status']) && ($_POST['status'] === 'inactive' || $_POST['status'] === '0')) ? 'inactive' : 'active';
        if ($name === '') throw new Exception('Name required');
        $id = $db->add_topping($name, $price, $status);
        echo json_encode(['success' => true, 'id' => $id]);
        exit;
    }

    // Update topping
    if ($method === 'POST' && $action === 'update') {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $name = trim($_POST['name'] ?? '');
        $price = isset($_POST['price']) ? floatval($_POST['price']) : 0.00;
        if ($id <= 0 || $name === '') throw new Exception('Invalid data');
        $ok = $db->update_topping($id, $name, $price);
        echo json_encode(['success' => (bool)$ok]);
        exit;
    }

    // Toggle status
    if ($method === 'POST' && $action === 'toggle_status') {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $status = (isset($_POST['status']) && $_POST['status'] === 'active') ? 'active' : 'inactive';
        if ($id <= 0) throw new Exception('Invalid id');
        $ok = $db->update_topping_status($id, $status);
        echo json_encode(['success' => (bool)$ok]);
        exit;
    }

    // Delete topping
    if ($method === 'POST' && $action === 'delete') {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid id']);
            exit;
        }

        try {
            $stmt = $con->prepare("DELETE FROM toppings WHERE id = ?");
            $ok = $stmt->execute([$id]);

            if ($ok && $stmt->rowCount() > 0) {
                echo json_encode(['success' => true, 'message' => 'Deleted']);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Topping not found or already deleted']);
            }
        } catch (PDOException $pdoEx) {
            // foreign key / constraint violation often SQLSTATE 23000
            if ($pdoEx->getCode() === '23000') {
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'message' => 'Cannot delete topping: it is referenced by other records. Mark it inactive instead.'
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $pdoEx->getMessage()]);
            }
        }
        exit;
    }

    // Unsupported action
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unsupported action']);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}