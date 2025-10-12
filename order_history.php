<?php
session_start();
if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}
$user_id = $_SESSION['user']['user_id'];
require_once __DIR__ . '/admin/database/db_connect.php';
$db = new Database();
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 5;
$total = 0;
$orders = method_exists($db, 'fetchUserOrdersPaginated')
    ? $db->fetchUserOrdersPaginated($user_id, $page, $perPage, $total)
    : $db->fetchUserOrders($user_id);
$totalPages = method_exists($db, 'fetchUserOrdersPaginated') ? (int)ceil(($total ?: 0) / $perPage) : 1;
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
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <link rel="shortcut icon" href="img/logo.png" type="image/png">
</head>
<body>
    <!-- Site Header (consistent with homepage) -->
    <?php
        $isLoggedIn = isset($_SESSION['user']);
        $userFirstName = $isLoggedIn ? ($_SESSION['user']['user_FN'] ?? '') : '';
    ?>
    <header class="header">
        <div class="header-content">
            <div class="logo">C&C</div>
            <button class="hamburger-menu" aria-label="Menu">
                <i class="fas fa-bars"></i>
            </button>
            <nav class="nav-menu">
                <a href="index.php" class="nav-item">Home</a>
                <a href="index.php#about" class="nav-item">About</a>
                <a href="index.php#products" class="nav-item">Shop</a>
                <a href="index.php#locations" class="nav-item">Locations</a>
                <div class="profile-dropdown">
                    <button class="profile-btn" id="profileDropdownBtn" type="button">
                        <span class="profile-initials">
                            <?php if ($isLoggedIn): ?>
                                <?php echo htmlspecialchars(mb_substr($userFirstName, 0, 1)); ?>
                            <?php else: ?>
                                <i class="fas fa-user"></i>
                            <?php endif; ?>
                        </span>
                        <i class="fas fa-caret-down ms-1"></i>
                    </button>
                    <div class="profile-dropdown-menu" id="profileDropdownMenu">
                        <?php if ($isLoggedIn): ?>
                            <a href="order_history.php" class="dropdown-item">Order History</a>
                            <a href="logout.php" class="dropdown-item">Logout</a>
                        <?php else: ?>
                            <a href="login.php" class="dropdown-item">Sign In</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($isLoggedIn): ?>
                    <span class="navbar-username" style="margin-left:10px;font-weight:600;">
                        <?php echo htmlspecialchars($userFirstName); ?>
                    </span>
                <?php endif; ?>
            </nav>
        </div>
    </header>

<main class="order-history-page">
<div class="container mb-5">
    <h2 class="mb-4 section-title" style="font-size:2rem;">Order History</h2>
    <?php if ($latest_ready): ?>
        <div class="alert alert-success" style="font-weight:bold;">
            Your latest order is <span style="color:#388e3c;">READY</span> for pickup!
        </div>
    <?php endif; ?>
    <?php if (count($orders) === 0): ?>
        <div class="alert alert-info">No orders found.</div>
    <?php else: ?>
        <div class="oh-card table-responsive">
        <table class="table align-middle oh-table">
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
                            echo '<span class="oh-badge status-ready">Ready</span>';
                        } elseif ($status === 'pending') {
                            echo '<span class="oh-badge status-pending">Pending</span>';
                        } elseif ($status === 'preparing') {
                            echo '<span class="oh-badge status-preparing">Preparing</span>';
                        } elseif ($status === 'picked up') {
                            echo '<span class="oh-badge status-pickedup">Picked Up</span>';
                        } elseif ($status === 'cancelled') {
                            echo '<span class="oh-badge status-cancelled">Cancelled</span>';
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
        <?php if ($totalPages > 1): ?>
        <nav aria-label="Order history pagination" class="mt-3">
            <ul class="pagination oh-pagination">
                <?php
                $cur = $page;
                $base = 'order_history.php';
                $mk = function($p, $label = null, $disabled = false, $active = false) use ($base) {
                    $label = $label ?? (string)$p;
                    $href = $disabled ? '#' : $base . '?page=' . $p;
                    $liCls = 'page-item' . ($disabled ? ' disabled' : '') . ($active ? ' active' : '');
                    $aCls = 'page-link';
                    return "<li class='{$liCls}'><a class='{$aCls}' href='{$href}'>" . htmlspecialchars($label) . "</a></li>";
                };
                echo $mk(max(1, $cur - 1), 'Prev', $cur <= 1, false);
                // Windowed numbers (max 7)
                $window = 7; $half = (int)floor($window/2);
                $start = max(1, $cur - $half);
                $end = min($totalPages, $start + $window - 1);
                $start = max(1, $end - $window + 1);
                for ($p = $start; $p <= $end; $p++) {
                    echo $mk($p, (string)$p, false, $p === $cur);
                }
                echo $mk(min($totalPages, $cur + 1), 'Next', $cur >= $totalPages, false);
                ?>
            </ul>
        </nav>
        <?php endif; ?>
    <?php endif; ?>
            <a href="index.php" class="btn btn-secondary mt-3">Back to Home</a>
</div>
</main>

<script>
    // Minimal dropdown toggle to match site behavior
    (function(){
        const btn = document.getElementById('profileDropdownBtn');
        const menu = document.getElementById('profileDropdownMenu');
        if (!btn || !menu) return;
            btn.addEventListener('click', function(e){
                e.stopPropagation();
                menu.classList.toggle('show');
            });
        document.addEventListener('click', function(){
                menu.classList.remove('show');
        });
    })();

        // Mobile nav toggle
        (function(){
            const burger = document.querySelector('.hamburger-menu');
            const nav = document.querySelector('.nav-menu');
            if (!burger || !nav) return;
            burger.addEventListener('click', function(e){
                e.stopPropagation();
                nav.classList.toggle('mobile-open');
            });
            document.addEventListener('click', function(){
                nav.classList.remove('mobile-open');
            });
        })();
</script>
</body>
</html>

