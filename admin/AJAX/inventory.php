<?php
ini_set('display_errors',1);error_reporting(E_ALL);
header('Content-Type: application/json');
require_once __DIR__ . '/../database/db_connect.php';
session_start();
if (!(Database::isAdmin() || Database::isSuperAdmin() || (isset($_SESSION['admin_id']) && $_SESSION['admin_id']))) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Forbidden']);
    exit;
}
$db = new Database();
$pdo = $db->opencon();
// Defensive: ensure product_inventory table exists even if earlier ensure failed
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_inventory (
        product_id VARCHAR(64) NOT NULL PRIMARY KEY,
        quantity INT NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_pi_products FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Throwable $e) {
    // If FK creation fails (e.g., missing index), fallback to table without FK
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS product_inventory (
            product_id VARCHAR(64) NOT NULL PRIMARY KEY,
            quantity INT NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (Throwable $_) { /* still ignore; will error later if truly broken */ }
}
$action = $_REQUEST['action'] ?? 'list';
try {
    if ($action === 'list') {
        $items = $db->fetch_inventory_list();
        echo json_encode(['success'=>true,'items'=>$items]);
        exit;
    }
    if ($action === 'update' && isset($_POST['product_id'], $_POST['quantity'])) {
        $pid = trim($_POST['product_id']);
        $qty = (int)$_POST['quantity'];
        $ok = $db->set_inventory_quantity($pid, $qty);
        echo json_encode(['success'=>$ok,'product_id'=>$pid,'quantity'=>$qty]);
        exit;
    }
    echo json_encode(['success'=>false,'message'=>'Unsupported action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error','error'=>$e->getMessage()]);
}
