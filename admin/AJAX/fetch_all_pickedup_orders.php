<?php
require_once __DIR__ . '/../database_connections/db_connect.php';
header('Content-Type: application/json');

$db = new Database();
$con = $db->opencon();

function fetch_pickedup_orders_pdo($con) {
    $orders = [];
    $sql = "SELECT t.transac_id, t.reference_number, t.user_id, t.total_amount, t.status, t.created_at, u.user_FN AS customer_name
            FROM transaction t
            LEFT JOIN users u ON t.user_id = u.user_id
            WHERE t.status = 'picked up'
            ORDER BY t.created_at DESC";
    $stmt = $con->prepare($sql);
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($orders as &$order) {
        $itemStmt = $con->prepare("SELECT ti.quantity, ti.size, ti.price, p.name 
            FROM transaction_items ti 
            JOIN products p ON ti.product_id = p.id 
            WHERE ti.transaction_id = ?");
        $itemStmt->execute([$order['transac_id']]);
        $order['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return $orders;
}

try {
    $orders = fetch_pickedup_orders_pdo($con);
    echo json_encode($orders);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([]);
}
