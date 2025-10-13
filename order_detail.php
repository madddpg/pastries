<?php
session_start();
if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}
$user_id = $_SESSION['user']['user_id'];
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo "Invalid transaction ID.";
    exit;
}
$transac_id = intval($_GET['id']);
require_once __DIR__ . '/admin/database/db_connect.php';
$db = new Database();
$order = $db->fetchOrderDetail($user_id, $transac_id);
if (!$order) {
    echo "Order not found or you do not have permission to view this order.";
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Order Details - Cups & Cuddles</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
<div class="container my-5">
    <h2 class="mb-4">Order Details</h2>
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="card-title">Reference Number: <?php echo htmlspecialchars($order['transac_id']); ?></h5>
            <p class="card-text"><strong>Date:</strong> <?php echo htmlspecialchars($order['created_at']); ?></p>
            <p class="card-text"><strong>Status:</strong>
                <?php
                $status = strtolower($order['status']);
                if ($status === 'ready') {
                    echo '<span class="badge bg-success">Ready</span>';
                } elseif ($status === 'pending') {
                    echo '<span class="badge bg-warning text-dark">Pending</span>';
                } elseif ($status === 'preparing') {
                    echo '<span class="badge bg-info text-dark">Preparing</span>';
                } elseif ($status === 'picked up') {
                    echo '<span class="badge bg-secondary">Picked Up</span>';
                } elseif ($status === 'cancelled') {
                    echo '<span class="badge bg-danger">Cancelled</span>';
                } else {
                    echo htmlspecialchars(ucfirst($order['status']));
                }
                ?>
            </p>
            <p class="card-text"><strong>Total Amount:</strong> ₱<?php echo number_format($order['total_amount'], 2); ?></p>
            <?php if (strtolower($order['status']) === 'pending'): ?>
                <button type="button" id="btnCancelOrder" data-transac-id="<?php echo (int)$order['transac_id']; ?>" class="btn btn-outline-danger">Cancel Order</button>
            <?php endif; ?>
        </div>
    </div>
    <h5>Items</h5>
    <ul class="list-group mb-4">
        <?php foreach ($order['items'] as $item): ?>
            <li class="list-group-item">
                <?php echo htmlspecialchars($item['name']); ?>
                (<?php echo htmlspecialchars($item['size']); ?>) &times; <?php echo (int)$item['quantity']; ?>
                - ₱<?php echo number_format($item['price'], 2); ?>
            </li>
        <?php endforeach; ?>
    </ul>
        <a href="order_history.php" class="btn btn-secondary">Back to Order History</a>
</div>
<script>
(function(){
    const btn = document.getElementById('btnCancelOrder');
    if(!btn) return;
    btn.addEventListener('click', function(e){
        e.preventDefault();
        if(btn.disabled) return;
        if(!confirm('Cancel this order?')) return;
        const id = btn.getAttribute('data-transac-id');
        if(!id) return;
        btn.disabled = true; btn.textContent = 'Cancelling…';
        fetch('AJAX/cancel_order.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin',
            body: 'id=' + encodeURIComponent(id)
        }).then(r=>r.json()).then(data=>{
            if(data && data.success){
                window.location.href = 'order_history.php';
            } else {
                alert(data && data.message ? data.message : 'Failed to cancel');
                btn.disabled = false; btn.textContent = 'Cancel Order';
            }
        }).catch(()=>{
            alert('Network error.');
            btn.disabled = false; btn.textContent = 'Cancel Order';
        });
    });
})();
</script>
</body>
</html>
