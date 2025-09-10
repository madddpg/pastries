<?php
require_once __DIR__ . '/../database/db_connect.php';

header('Content-Type: text/html; charset=UTF-8');

$status = isset($_GET['status']) ? trim($_GET['status']) : '';
$allowed = ['pending', 'preparing', 'ready'];
$params = [];
$where = "WHERE t.status IN ('pending','preparing','ready')";
if ($status !== '' && in_array($status, $allowed, true)) {
    $where = "WHERE t.status = ?";
    $params[] = $status;
}

try {
    $db = new Database();
    $con = $db->opencon();

    $sql = "SELECT t.transac_id, t.user_id, t.total_amount, t.status, t.created_at,
                   u.user_FN AS customer_name, p.pickup_time, p.special_instructions
            FROM transaction t
            LEFT JOIN users u ON t.user_id = u.user_id
            LEFT JOIN pickup_detail p ON t.transac_id = p.transaction_id
            $where
            ORDER BY t.created_at DESC";
    $stmt = $con->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch items per order if your design shows them
    if ($orders) {
        $itemStmt = $con->prepare("SELECT ti.transaction_id, ti.quantity, ti.size, ti.price, p.name
                                   FROM transaction_items ti
                                   JOIN products p ON ti.product_id = p.id
                                   WHERE ti.transaction_id = ?");
        foreach ($orders as &$o) {
            $itemStmt->execute([(int)$o['transac_id']]);
            $o['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    // Render using the exact same design as admin.php
    require __DIR__ . '/../partials/live_orders_list.php';

} catch (Throwable $e) {
    http_response_code(500);
    echo '<div class="error-state">Failed to load orders.</div>';
}