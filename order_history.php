<?php
session_start();
if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}
$user_id = $_SESSION['user']['user_id'];
// Header/user context (match index.php header usage)
$isLoggedIn = isset($_SESSION['user']) && !empty($_SESSION['user']);
$userFirstName = isset($_SESSION['user']['user_FN']) ? $_SESSION['user']['user_FN'] : '';
$userLastName  = isset($_SESSION['user']['user_LN']) ? $_SESSION['user']['user_LN'] : '';
require_once __DIR__ . '/admin/database/db_connect.php';
$db = new Database();
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 10;
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
    <link rel="stylesheet" href="css/style.css?v=20251012">
    <link rel="shortcut icon" href="img/logo.png" type="image/png">
</head>
<body>
<!-- Site Header (reused design from index.php) -->
<header class="header">
    <div class="header-content">
        <div class="logo"></div>
        <button class="hamburger-menu" aria-label="Open menu">
            <i class="fas fa-bars"></i>
        </button>
        <nav class="nav-menu" style="background:#a7ddcb;border-radius:40px;padding:8px 20px;margin:10px 20px;">
            <a href="index.php#home" class="nav-item">Home</a>
            <a href="index.php#about" class="nav-item">About</a>
            <a href="index.php#products" class="nav-item">Shop</a>
            <a href="index.php#locations" class="nav-item">Locations</a>

            <div class="profile-dropdown">
                <button class="profile-btn" id="profileDropdownBtnOH" type="button" aria-haspopup="true" aria-expanded="false">
                    <span class="profile-initials">
                        <?php if ($isLoggedIn): ?>
                            <?php echo htmlspecialchars(mb_substr($userFirstName ?: 'U', 0, 1)); ?>
                        <?php else: ?>
                            <i class="fas fa-user"></i>
                        <?php endif; ?>
                    </span>
                    <i class="fas fa-caret-down ms-1"></i>
                </button>
                <div class="profile-dropdown-menu" id="profileDropdownMenuOH" role="menu">
                    <a href="order_history.php" class="dropdown-item" role="menuitem">Order History</a>
                    <a href="logout.php" class="dropdown-item" role="menuitem">Logout</a>
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

<main class="order-history-page" style="background:#40584e;min-height:100vh;padding-top:0;">
    <!-- Hero banner -->
    <section class="order-history-hero-header position-relative overflow-hidden">
        <div class="order-history-hero-overlay"></div>
        <div class="container-fluid h-100">
            <div class="row h-100 align-items-center justify-content-center text-center text-white">
                <div class="col-12">
                    <h1 class="order-history-hero-title">Order History</h1>
                    <p class="order-history-hero-subtitle">Your recent orders and receipts</p>
                </div>
            </div>
        </div>

        <!-- Floating coffee beans -->
        <div class="order-history-floating-bean order-history-bean-1"></div>
        <div class="order-history-floating-bean order-history-bean-2"></div>
        <div class="order-history-floating-bean order-history-bean-3"></div>
    </section>
    
    <div class="oh-container">
        <?php if ($latest_ready): ?>
            <div class="alert alert-success" style="font-weight:bold;">
                Your latest order is <span style="color:#388e3c;">READY</span> for pickup!
            </div>
        <?php endif; ?>
        <?php if (count($orders) === 0): ?>
            <div class="alert alert-info">No orders found.</div>
            <div class="text-center mt-4">
                <a href="index.php" class="btn btn-primary" style="background:#40584e;border:none;border-radius:10px;padding:10px 20px;">
                    Back to Home
                </a>
            </div>
        <?php else: ?>
                <div class="oh-list">
                <?php foreach ($orders as $order): ?>
                    <?php
                        $status = strtolower($order['status']);
                        $badgeHtml = '';
                        if ($status === 'ready') {
                            $badgeHtml = '<span class="oh-badge status-ready">Ready</span>';
                        } elseif ($status === 'pending') {
                            $badgeHtml = '<span class="oh-badge status-pending">Pending</span>';
                        } elseif ($status === 'preparing') {
                            $badgeHtml = '<span class="oh-badge status-preparing">Preparing</span>';
                        } elseif ($status === 'picked up') {
                            $badgeHtml = '<span class="oh-badge status-pickedup">Picked Up</span>';
                        } elseif ($status === 'cancelled') {
                            $badgeHtml = '<span class="oh-badge status-cancelled">Cancelled</span>';
                        } else {
                            $badgeHtml = '<span class="oh-badge">'.htmlspecialchars(ucfirst($order['status'])).'</span>';
                        }
                        $orderDetail = $db->fetchOrderDetail($user_id, $order['transac_id']);
                        $itemsText = '';
                        if (!empty($orderDetail['items'])) {
                            $parts = [];
                            foreach ($orderDetail['items'] as $it) {
                                $name = htmlspecialchars($it['name']);
                                $size = htmlspecialchars($it['size']);
                                $qty = (int)$it['quantity'];
                                $price = '₱'.number_format((float)$it['price'], 2);
                                $parts[] = "• {$name} ({$size}) × {$qty} – {$price}";
                            }
                            $itemsText = implode('<br>', $parts);
                        }
                        $canCancel = ($status === 'pending');
                    ?>
                    <a class="oh-order" href="order_detail.php?id=<?php echo urlencode(htmlspecialchars($order['reference_number'])); ?>">
                        <div class="oh-order-top">
                            <div class="oh-ref"><?php echo htmlspecialchars($order['reference_number']); ?></div>
                            <div class="oh-date"><?php echo htmlspecialchars($order['created_at']); ?></div>
                            <div class="oh-status"><?php echo $badgeHtml; ?></div>
                        </div>
                        <div class="oh-items"><?php echo $itemsText ?: '-'; ?></div>
                        <div class="oh-order-bottom">
                            <span class="oh-total-label">Total</span>
                            <span class="oh-total-amount">₱<?php echo number_format((float)$order['total_amount'], 2); ?></span>
                            <?php if ($canCancel): ?>
                              <button type="button" class="oh-cancel-btn" data-transac-id="<?php echo (int)$order['transac_id']; ?>" onclick="event.preventDefault(); event.stopPropagation(); return false;">Cancel</button>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
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
            <div class="text-center mt-4">
                <a href="index.php" class="btn btn-primary" style="background:#40584e;border:none;border-radius:10px;padding:10px 20px;">
                    Back to Home
                </a>
            </div>
        <?php endif; ?>
    </div> <!-- end oh-container -->
</main>


</body>
<script>
(function(){
    function onCancelClick(e){
        const btn = e.target.closest('.oh-cancel-btn');
        if(!btn) return;
        e.preventDefault(); e.stopPropagation();
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
                // Reload to reflect updated status
                window.location.reload();
            } else {
                alert(data && data.message ? data.message : 'Failed to cancel');
                btn.disabled = false; btn.textContent = 'Cancel';
            }
        }).catch(()=>{
            alert('Network error.');
            btn.disabled = false; btn.textContent = 'Cancel';
        });
    }
    document.addEventListener('click', onCancelClick, true);
})();
</script>
<script>
// Minimal header dropdown toggle for Order History page
(function(){
    const btn = document.getElementById('profileDropdownBtnOH');
    const menu = document.getElementById('profileDropdownMenuOH');
    if (!btn || !menu) return;
    function closeMenu(){ menu.classList.remove('show'); btn.setAttribute('aria-expanded','false'); }
    btn.addEventListener('click', function(e){
        e.stopPropagation();
        const open = menu.classList.toggle('show');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('click', function(e){
        if (!menu.contains(e.target) && !btn.contains(e.target)) closeMenu();
    });
})();
</script>
</html>

