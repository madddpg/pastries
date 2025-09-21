<?php
// Simple AJAX endpoint for managing pastry variants per product
// Actions:
// - GET action=list&product_id=... => returns {success:true, variants:[{variant_id?,label,price}]}
// - POST action=save with product_id and variants (JSON array) => returns {success:true}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../database/db_connect.php';
$db = new Database();
$pdo = $db->opencon();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = strtolower($_REQUEST['action'] ?? ($_GET['action'] ?? 'list'));

function json_out($arr) { echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

if ($method === 'GET' && $action === 'list') {
    $product_id = trim($_GET['product_id'] ?? '');
    if ($product_id === '') json_out(['success'=>false,'message'=>'Missing product_id']);
    try {
        $variants = $db->fetch_pastry_variants($product_id);
        json_out(['success'=>true,'variants'=>$variants]);
    } catch (Throwable $e) {
        json_out(['success'=>false,'message'=>$e->getMessage()]);
    }
}

if ($method === 'POST' && $action === 'save') {
    $product_id = trim($_POST['product_id'] ?? '');
    $variantsRaw = $_POST['variants'] ?? '[]';
    if ($product_id === '') json_out(['success'=>false,'message'=>'Missing product_id']);
    $variants = [];
    if (is_string($variantsRaw)) {
        $dec = json_decode($variantsRaw, true);
        if (is_array($dec)) $variants = $dec;
    } elseif (is_array($variantsRaw)) {
        $variants = $variantsRaw;
    }

    // sanitize
    $clean = [];
    foreach ((array)$variants as $v) {
        $label = isset($v['label']) ? trim((string)$v['label']) : '';
        $price = isset($v['price']) ? (float)$v['price'] : null;
        if ($label === '' || $price === null || $price < 0) continue;
        $clean[] = ['label'=>$label,'price'=>round($price,2)];
    }

    try {
        $ok = $db->save_pastry_variants($product_id, $clean);
        json_out(['success'=>$ok]);
    } catch (Throwable $e) {
        json_out(['success'=>false,'message'=>$e->getMessage()]);
    }
}

json_out(['success'=>false,'message'=>'Invalid request']);
