
<?php
header('Content-Type: application/json');
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
        if ($id <= 0) throw new Exception('Invalid id');
        $stmt = $con->prepare("DELETE FROM toppings WHERE id = ?");
        $ok = $stmt->execute([$id]);
        echo json_encode(['success' => (bool)$ok]);
        exit;
    }

    throw new Exception('Unsupported action');
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}