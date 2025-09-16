
<?php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/database/db_connect.php';
$db = new Database();
$con = $db->opencon();

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// require super-admin for destructive actions
if (!Database::isSuperAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden: super-admin required']);
    exit;
}

$action = $_POST['action'] ?? 'delete';
$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid id']);
    exit;
}

try {
    // If action == delete: check references first (transaction_items, transaction_toppings)
    if ($action === 'delete') {
        // check transaction_items
        try {
            $stmt = $con->prepare("SELECT COUNT(*) FROM transaction_items WHERE product_id = ?");
            $stmt->execute([$id]);
            $cnt = (int)$stmt->fetchColumn();
            if ($cnt > 0) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => "Cannot delete: product is referenced in transaction_items ({$cnt}). Use force_delete to remove references first."]);
                exit;
            }
        } catch (PDOException $e) {
            // ignore if table missing, continue
        }

        // safe to delete
        $del = $con->prepare("DELETE FROM products WHERE id = ?");
        $del->execute([$id]);
        if ($del->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Product deleted']);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Product not found']);
        }
        exit;
    }

    // Force delete: remove referencing rows then delete (destructive)
    if ($action === 'force_delete') {
        $con->beginTransaction();
        try {
            // remove references (transaction_items and related transaction_toppings)
            $delToppings = $con->prepare("DELETE tt FROM transaction_toppings tt JOIN transaction_items ti ON tt.transaction_item_id = ti.ts_itm_id WHERE ti.product_id = ?");
            $delToppings->execute([$id]); // if fails, it's ok to continue

            $delItems = $con->prepare("DELETE FROM transaction_items WHERE product_id = ?");
            $delItems->execute([$id]);

            $del = $con->prepare("DELETE FROM products WHERE id = ?");
            $del->execute([$id]);

            if ($del->rowCount() > 0) {
                $con->commit();
                echo json_encode(['success' => true, 'message' => 'Force-deleted product and references']);
            } else {
                $con->rollBack();
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Product not found']);
            }
        } catch (PDOException $ex) {
            $con->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $ex->getMessage()]);
        }
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unsupported action']);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}