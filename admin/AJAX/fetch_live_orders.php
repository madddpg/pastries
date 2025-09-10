
<?php
// ...existing code (require, headers)...
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

    $sql = "SELECT
              t.transac_id,
              t.reference_number,
              t.user_id,
              t.total_amount,
              t.status,
              t.created_at,
              t.payment_method,
              u.user_FN AS customer_name,
              p.pickup_time,
              p.special_instructions
            FROM transaction t
            LEFT JOIN users u ON t.user_id = u.user_id
            LEFT JOIN pickup_detail p ON t.transac_id = p.transaction_id
            $where
            ORDER BY t.created_at DESC";
    $stmt = $con->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($orders) {
        $itemStmt = $con->prepare(
          "SELECT ti.transaction_id, ti.quantity, ti.size, ti.price, p.name
           FROM transaction_items ti
           JOIN products p ON ti.product_id = p.id
           WHERE ti.transaction_id = ?"
        );
        foreach ($orders as &$o) {
            $itemStmt->execute([(int)$o['transac_id']]);
            $o['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    require __DIR__ . '/markup.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo '<div class="error-state">Failed to load orders.</div>';
}