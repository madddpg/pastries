<?php
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../database/db_connect.php';

$productId = isset($_GET['product_id']) ? trim($_GET['product_id']) : '';
if ($productId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing product_id']);
    exit;
}

try {
    $db = new Database();
    $pdo = $db->opencon();
    $tbl = method_exists($db, 'getSizePriceTable') ? $db->getSizePriceTable($pdo) : 'product_size_prices';

    // Resolve latest products_pk for product_id
    $pkStmt = $pdo->prepare("SELECT products_pk, COALESCE(LOWER(data_type),'') AS data_type FROM products WHERE product_id = ? ORDER BY created_at DESC LIMIT 1");
    $pkStmt->execute([$productId]);
    $meta = $pkStmt->fetch(PDO::FETCH_ASSOC);
    if (!$meta || empty($meta['products_pk'])) {
        echo json_encode(['success' => true, 'data' => []]);
        exit;
    }
    $productsPk = (int)$meta['products_pk'];
    $dataType = (string)$meta['data_type'];

    if ($dataType === 'pastries') {
        // Pastry variants history (label-based)
        $sql = "SELECT label AS size_label, price, effective_from, effective_to
                FROM product_pastry_variants
                WHERE products_pk = ?
                ORDER BY label ASC, effective_from DESC, variant_id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$productsPk]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        echo json_encode(['success' => true, 'type' => 'pastries', 'data' => $rows]);
        exit;
    }

    // Drink sizes history
    $sql = "SELECT size AS size_label, price, effective_from, effective_to
        FROM `{$tbl}`
        WHERE products_pk = ?
        ORDER BY size ASC, effective_from DESC, product_size_id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$productsPk]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    echo json_encode(['success' => true, 'type' => 'sizes', 'data' => $rows]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to load price history']);
}
