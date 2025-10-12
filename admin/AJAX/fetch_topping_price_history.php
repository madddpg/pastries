<?php
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=UTF-8');
session_start();

// Require admin session
if (empty($_SESSION['admin_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

require_once __DIR__ . '/../database/db_connect.php';

$toppingId = isset($_GET['topping_id']) ? (int)$_GET['topping_id'] : 0;
if ($toppingId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing topping_id']);
    exit;
}

try {
    $db = new Database();
    $pdo = $db->opencon();
    $sql = "SELECT price, effective_from, effective_to
            FROM toppings_price_history
            WHERE topping_id = ?
            ORDER BY effective_from DESC, topping_price_id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$toppingId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    echo json_encode(['success' => true, 'data' => $rows]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to load topping price history']);
}
