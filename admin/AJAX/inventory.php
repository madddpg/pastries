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
