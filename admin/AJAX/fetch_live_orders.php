
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

    // Render minimal cards; adjust markup/classes to your existing grid
    if (!$orders) {
        echo '<div class="empty-state">No orders found.</div>';
        exit;
    }

    foreach ($orders as $o) {
        $id = (int)$o['transac_id'];
        $cust = htmlspecialchars($o['customer_name'] ?? 'Guest', ENT_QUOTES, 'UTF-8');
        $st = htmlspecialchars($o['status'], ENT_QUOTES, 'UTF-8');
        $amt = number_format((float)$o['total_amount'], 2);
        $pickup = htmlspecialchars($o['pickup_time'] ?? '', ENT_QUOTES, 'UTF-8');
        $note = htmlspecialchars($o['special_instructions'] ?? '', ENT_QUOTES, 'UTF-8');

        echo '<div class="order-card" data-id="'.$id.'" data-transac-id="'.$id.'">';
        echo '  <div class="order-header">';
        echo '    <div class="order-id">#'.$id.'</div>';
        echo '    <div class="order-status">'.ucwords($st).'</div>';
        echo '  </div>';
        echo '  <div class="order-body">';
        echo '    <div class="order-customer">'.$cust.'</div>';
        echo '    <div class="order-amount">₱'.$amt.'</div>';
        if ($pickup !== '') echo '    <div class="order-pickup">Pickup: '.$pickup.'</div>';
        if ($note !== '')   echo '    <div class="order-note">'. $note .'</div>';
        echo '  </div>';
        echo '  <div class="order-actions">';
        if ($st === 'pending') {
            echo '    <button type="button" class="btn-accept" data-id="'.$id.'">Accept</button>';
            echo '    <button type="button" class="btn-reject" data-id="'.$id.'">Reject</button>';
        } elseif ($st === 'preparing') {
            echo '    <button type="button" class="btn-ready" data-id="'.$id.'">Mark as Ready</button>';
        } elseif ($st === 'ready') {
            echo '    <button type="button" class="btn-complete" data-id="'.$id.'">Mark as Picked Up</button>';
        }
        echo '  </div>';
        echo '</div>';
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo '<div class="error-state">Failed to load orders.</div>';
}
?>