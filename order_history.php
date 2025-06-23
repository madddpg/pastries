<?php
session_start();
if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}
$user_id = $_SESSION['user']['user_id'];
require_once __DIR__ . '/admin/database/db_connect.php';
$db = new Database();
$orders = $db->fetchUserOrders($user_id);
$latest_ready = false;
if (count($orders) > 0) {
    $latest_order = $orders[0];
    if ($latest_order['status'] === 'ready') {
        $latest_ready = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Order History - Cups & Cuddles</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
<div class="container my-5">
    <h2 class="mb-4">Order History</h2>
    <?php if ($latest_ready): ?>
        <div class="alert alert-success" style="font-weight:bold;">
            Your latest order is <span style="color:#388e3c;">READY</span> for pickup!
        </div>
    <?php endif; ?>
    <?php if (count($orders) === 0): ?>
        <div class="alert alert-info">No orders found.</div>
    <?php else: ?>
        <div class="table-responsive">
        <table class="table table-bordered align-middle">
            <thead class="table-light">
                <tr>
                    <th>Reference Number</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Items</th>
                    <th>Total Price</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($orders as $order): ?>
                <tr class="order-row" style="cursor:pointer;"
                    onclick="window.location.href='order_detail.php?id=<?php echo urlencode(htmlspecialchars($order['reference_number'])); ?>'">
                    <td><?php echo htmlspecialchars($order['reference_number']); ?></td>
                    <td><?php echo htmlspecialchars($order['created_at']); ?></td>
                    <td>
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
                    </td>
                    <td>
                        <ul class="mb-0">
                        <?php
                        $orderDetail = $db->fetchOrderDetail($user_id, $order['transac_id']);
                        foreach ($orderDetail['items'] as $item):
                        ?>
                            <li>
                                <?php echo htmlspecialchars($item['name']); ?>
                                (<?php echo htmlspecialchars($item['size']); ?>) &times; <?php echo (int)$item['quantity']; ?>
                                - ₱<?php echo number_format($item['price'], 2); ?>
                            </li>
                        <?php endforeach; ?>
                        </ul>
                    </td>
                    <td>₱<?php echo number_format($order['total_amount'], 2); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
    <a href="index.php" class="btn btn-secondary mt-3">Back to Home</a>
</div>
</body>
</html>

