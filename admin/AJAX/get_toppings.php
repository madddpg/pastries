<?php
header('Content-Type: application/json');
session_start();

// Basic server-side admin gate
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

function send_json($data, $code = 200) {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

try {
    // List toppings
    if ($method === 'GET' && $action === 'list') {
        $toppings = $db->fetch_toppings_pdo();
        send_json([
            'success' => true,
            'toppings' => $toppings,
            'is_super' => Database::isSuperAdmin()
        ]);
    }

    // Active toppings (public site)
    if ($method === 'GET' && $action === 'active') {
        $toppings = $db->fetch_active_toppings();
        send_json(['success' => true, 'toppings' => $toppings]);
    }

    // Create topping
    if ($method === 'POST' && $action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $price = isset($_POST['price']) ? floatval($_POST['price']) : 0.00;
        $status = (isset($_POST['status']) && ($_POST['status'] === 'inactive' || $_POST['status'] === '0'))
            ? 'inactive' : 'active';

        if ($name === '') send_json(['success' => false, 'message' => 'Name required'], 400);

        $id = $db->add_topping($name, $price, $status);
        send_json(['success' => true, 'id' => $id]);
    }

    // Update topping
    if ($method === 'POST' && $action === 'update') {
        $id = intval($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $price = isset($_POST['price']) ? floatval($_POST['price']) : 0.00;

        if ($id <= 0 || $name === '') send_json(['success' => false, 'message' => 'Invalid data'], 400);

        $ok = $db->update_topping($id, $name, $price);
        send_json(['success' => (bool)$ok]);
    }

    // Toggle status
    if ($method === 'POST' && $action === 'toggle_status') {
        $id = intval($_POST['id'] ?? 0);
        $status = ($_POST['status'] ?? '') === 'active' ? 'active' : 'inactive';

        if ($id <= 0) send_json(['success' => false, 'message' => 'Invalid id'], 400);

        $ok = $db->update_topping_status($id, $status);
        send_json(['success' => (bool)$ok]);
    }

    // Delete topping
    if ($method === 'POST' && $action === 'delete') {
        if (!isset($_POST['id']) || intval($_POST['id']) <= 0) {
            send_json(['success' => false, 'message' => 'Invalid id'], 400);
        }
        $id = intval($_POST['id']);

        try {
            // Check for references
            $checkExists = $con->prepare(
                "SELECT COUNT(*) FROM information_schema.tables 
                 WHERE table_schema = DATABASE() AND table_name = 'transaction_toppings'"
            );
            $checkExists->execute();
            $hasTransactionToppings = (int)$checkExists->fetchColumn() > 0;

            if ($hasTransactionToppings) {
                $checkStmt = $con->prepare("SELECT COUNT(*) FROM transaction_toppings WHERE topping_id = ?");
                $checkStmt->execute([$id]);
                $count = (int)$checkStmt->fetchColumn();

                if ($count > 0 && !Database::isSuperAdmin()) {
                    send_json([
                        'success' => false,
                        'message' => "Cannot delete: topping is referenced in transaction_toppings ({$count} record(s)). Mark it inactive instead."
                    ], 409);
                }
            }

            // Super-admin may delete refs too
            if (Database::isSuperAdmin() && $hasTransactionToppings) {
                $con->beginTransaction();
                try {
                    $delRefs = $con->prepare("DELETE FROM transaction_toppings WHERE topping_id = ?");
                    $delRefs->execute([$id]);
                } catch (PDOException $ignored) {}

                $del = $con->prepare("DELETE FROM toppings WHERE id = ?");
                $del->execute([$id]);

                if ($del->rowCount() > 0) {
                    $con->commit();
                    send_json(['success' => true, 'message' => 'Deleted (references removed)']);
                } else {
                    $con->rollBack();
                    send_json(['success' => false, 'message' => 'Topping not found'], 404);
                }
            } else {
                $stmt = $con->prepare("DELETE FROM toppings WHERE id = ?");
                $ok = $stmt->execute([$id]);

                if ($ok && $stmt->rowCount() > 0) {
                    send_json(['success' => true, 'message' => 'Deleted']);
                } else {
                    send_json(['success' => false, 'message' => 'Topping not found or already deleted'], 404);
                }
            }
        } catch (PDOException $pdoEx) {
            if ($pdoEx->getCode() === '23000') {
                send_json(['success' => false, 'message' => 'Cannot delete topping: constraint violation. Mark it inactive instead.'], 409);
            } else {
                send_json(['success' => false, 'message' => 'Database error: ' . $pdoEx->getMessage()], 500);
            }
        }
    }

    // If nothing matched
    send_json(['success' => false, 'message' => 'Unsupported action'], 400);

} catch (Exception $e) {
    send_json(['success' => false, 'message' => $e->getMessage()], 500);
}
