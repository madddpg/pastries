<?php
header('Content-Type: application/json');
session_start();

require_once __DIR__ . '/../database/db_connect.php';
$db = new Database();
$con = $db->opencon();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_REQUEST['action'] ?? 'list';

// Public helper for JSON
function send_json($data, $code = 200) {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/*
 * Allow public (non-admin) access ONLY to:
 *   GET ?action=active
 * Everything else still requires admin session.
 */
if (!($method === 'GET' && $action === 'active')) {
    if (empty($_SESSION['admin_id'])) {
        send_json(['success' => false, 'message' => 'Forbidden'], 403);
    }
}

try {
    // Public: active toppings
    if ($method === 'GET' && $action === 'active') {
        $toppings = $db->fetch_active_toppings();
        send_json(['success' => true, 'toppings' => $toppings]);
    }

    // Admin: full list
    if ($method === 'GET' && $action === 'list') {
        $toppings = $db->fetch_toppings_pdo();
        send_json([
            'success' => true,
            'toppings' => $toppings,
            'is_super' => Database::isSuperAdmin()
        ]);
    }

    // Admin: add
    if ($method === 'POST' && $action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $price = isset($_POST['price']) ? floatval($_POST['price']) : 0.00;
        $status = (isset($_POST['status']) && ($_POST['status'] === 'inactive' || $_POST['status'] === '0'))
            ? 'inactive' : 'active';
        if ($name === '') send_json(['success' => false, 'message' => 'Name required'], 400);

        $topping_id = $db->add_topping($name, $price, $status);
        $stmt = $con->prepare("SELECT topping_id, name, price, status FROM toppings WHERE topping_id = ?");
        $stmt->execute([$topping_id]);
        $newTopping = $stmt->fetch(PDO::FETCH_ASSOC);
        send_json(['success' => true, 'topping' => $newTopping]);
    }

    // Admin: update
    if ($method === 'POST' && $action === 'update') {
        $topping_id = intval($_POST['topping_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $price = isset($_POST['price']) ? floatval($_POST['price']) : 0.00;
        if ($topping_id <= 0 || $name === '') send_json(['success' => false, 'message' => 'Invalid data'], 400);
        $ok = $db->update_topping($topping_id, $name, $price);
        send_json(['success' => (bool)$ok]);
    }

    // Admin: toggle status
    if ($method === 'POST' && $action === 'toggle_status') {
        $topping_id = intval($_POST['topping_id'] ?? 0);
        $status = ($_POST['status'] ?? '') === 'active' ? 'active' : 'inactive';
        if ($topping_id <= 0) send_json(['success' => false, 'message' => 'Invalid id'], 400);
        $ok = $db->update_topping_status($topping_id, $status);
        send_json(['success' => (bool)$ok]);
    }

    // Admin: delete
    if ($method === 'POST' && $action === 'delete') {
        $topping_id = intval($_POST['topping_id'] ?? 0);
        if ($topping_id <= 0) send_json(['success' => false, 'message' => 'Invalid id'], 400);

        $checkExists = $con->prepare(
            "SELECT COUNT(*) FROM information_schema.tables 
             WHERE table_schema = DATABASE() AND table_name = 'transaction_toppings'"
        );
        $checkExists->execute();
        $hasTransactionToppings = (int)$checkExists->fetchColumn() > 0;

        if ($hasTransactionToppings) {
            $checkStmt = $con->prepare("SELECT COUNT(*) FROM transaction_toppings WHERE topping_id = ?");
            $checkStmt->execute([$topping_id]);
            $count = (int)$checkStmt->fetchColumn();
            if ($count > 0 && !Database::isSuperAdmin()) {
                send_json([
                    'success' => false,
                    'message' => "Cannot delete: topping referenced in transaction_toppings ({$count}). Mark inactive instead."
                ], 409);
            }
        }

        if (Database::isSuperAdmin() && $hasTransactionToppings) {
            $con->beginTransaction();
            try {
                $con->prepare("DELETE FROM transaction_toppings WHERE topping_id = ?")->execute([$topping_id]);
                $del = $con->prepare("DELETE FROM toppings WHERE topping_id = ?");
                $del->execute([$topping_id]);
                if ($del->rowCount() > 0) {
                    $con->commit();
                    send_json(['success' => true, 'message' => 'Deleted (references removed)']);
                } else {
                    $con->rollBack();
                    send_json(['success' => false, 'message' => 'Not found'], 404);
                }
            } catch (PDOException $e) {
                $con->rollBack();
                send_json(['success' => false, 'message' => $e->getMessage()], 500);
            }
        } else {
            $stmt = $con->prepare("DELETE FROM toppings WHERE topping_id = ?");
            $stmt->execute([$topping_id]);
            if ($stmt->rowCount() > 0) {
                send_json(['success' => true, 'message' => 'Deleted']);
            } else {
                send_json(['success' => false, 'message' => 'Not found or already deleted'], 404);
            }
        }
    }

    send_json(['success' => false, 'message' => 'Unsupported action'], 400);

} catch (Exception $e) {
    send_json(['success' => false, 'message' => $e->getMessage()], 500);
}
