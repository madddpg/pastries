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
// products table already exists; inventory managed via products.inventory_qty column
$action = $_REQUEST['action'] ?? 'list';
try {
    if ($action === 'list') {
        $items = $db->fetch_inventory_list();
        // annotate low-stock (<=5) & out-of-stock
        foreach ($items as &$it) {
            $q = isset($it['quantity']) ? (int)$it['quantity'] : null;
            if ($q !== null) {
                $it['low_stock'] = ($q <= 5);
                $it['out_of_stock'] = ($q <= 0);
            } else {
                $it['low_stock'] = false; // unlimited
                $it['out_of_stock'] = false;
            }
        }
        echo json_encode(['success'=>true,'items'=>$items]);
        exit;
    }
    if ($action === 'update' && isset($_POST['product_id'], $_POST['quantity'])) {
        $pid = trim($_POST['product_id']);
        $qty = (int)$_POST['quantity'];
        $ok = $db->set_inventory_quantity($pid, $qty);
        if (!$ok) {
            echo json_encode(['success'=>false,'product_id'=>$pid,'quantity'=>$qty,'message'=>'Update failed (product may not exist or schema missing updated_at).']);
        } else {
            echo json_encode(['success'=>true,'product_id'=>$pid,'quantity'=>$qty]);
        }
        exit;
    }
    if ($action === 'restock' && isset($_POST['product_id'], $_POST['add'])) {
        $pid = trim($_POST['product_id']);
        $add = max(0, (int)$_POST['add']);
        if ($add === 0) { echo json_encode(['success'=>false,'message'=>'Nothing to add']); exit; }
        // fetch current
        $pdo = (new Database())->opencon();
        $st = $pdo->prepare("SELECT quantity, inventory_qty FROM products WHERE product_id = ? LIMIT 1");
        $st->execute([$pid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['success'=>false,'message'=>'Product not found']); exit; }
        $current = null;
        $col = 'quantity';
        if (array_key_exists('quantity', $row) && $row['quantity'] !== null) {
            $current = (int)$row['quantity'];
        } elseif (array_key_exists('inventory_qty', $row)) {
            $current = (int)$row['inventory_qty'];
            $col = 'inventory_qty';
        }
        if ($current === null) { echo json_encode(['success'=>false,'message'=>'Unlimited stock product']); exit; }
        $newQty = $current + $add;
        // detect updated_at column
        $hasUpdated = false;
        try {
            $cst = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='products'");
            $cCols = array_map(static function($r){return strtolower($r['COLUMN_NAME']);}, $cst->fetchAll(PDO::FETCH_ASSOC));
            $hasUpdated = in_array('updated_at', $cCols, true);
        } catch (Throwable $_) {}
        $up = $pdo->prepare("UPDATE products SET $col = ?, status = CASE WHEN ? > 0 THEN 1 ELSE status END" . ($hasUpdated ? ", updated_at = NOW()" : "") . " WHERE product_id = ? LIMIT 1");
        $ok = $up->execute([$newQty, $newQty, $pid]);
        echo json_encode(['success'=>$ok,'product_id'=>$pid,'quantity'=>$newQty,'added'=>$add]);
        exit;
    }
    echo json_encode(['success'=>false,'message'=>'Unsupported action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error','error'=>$e->getMessage()]);
}
