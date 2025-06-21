<?php
require_once __DIR__ . '/../admin/database_connections/db_connect.php';
header('Content-Type: application/json');

$category = isset($_GET['category']) ? strtolower(trim($_GET['category'])) : '';
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 3;

if (!$category || !in_array($category, ['hot', 'cold'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid or missing category.']);
    exit;
}

$db = new Database();
$topProducts = $db->fetch_top_products_by_data_type($category, $limit);
if (empty($topProducts)) {
    $topProducts = $db->fetch_recent_products_by_data_type($category, $limit);
}

if (!empty($topProducts)) {
    echo json_encode(['success' => true, 'products' => $topProducts]);
} else {
    echo json_encode(['success' => false, 'products' => []]);
}
