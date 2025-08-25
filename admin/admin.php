<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!ini_get('date.timezone')) {
    date_default_timezone_set('Asia/Manila');
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/database/db_connect.php';
$db = new Database();
$con = $db->opencon();

// Get status filter from GET parameter for live orders
$live_status = isset($_GET['status']) ? $_GET['status'] : '';
$allowed_statuses = ['pending', 'preparing', 'ready'];

function fetch_pickedup_orders_pdo($con)
{
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

function fetch_live_orders_pdo($con, $status = '')
{
    $allowed_statuses = ['pending', 'preparing', 'ready'];
    if ($status !== '' && in_array($status, $allowed_statuses)) {
        $where = "WHERE t.status = ?";
        $params = [$status];
    } else {
        $where = "WHERE t.status IN ('pending','preparing','ready')";
        $params = [];
    }
    $sql = "SELECT t.transac_id, t.user_id, t.total_amount, t.status, t.created_at, u.user_FN AS customer_name, p.pickup_time, p.special_instructions
            FROM transaction t
            LEFT JOIN users u ON t.user_id = u.user_id
            LEFT JOIN pickup_detail p ON t.transac_id = p.transaction_id
            $where
            ORDER BY t.created_at DESC";
    $stmt = $con->prepare($sql);
    $stmt->execute($params);
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

function fetch_products_with_sales_pdo($con)
{
    $sql = "SELECT p.id, p.name, p.category_id, p.price, p.status, p.created_at,
                   COALESCE(SUM(ti.quantity), 0) AS sales
            FROM products p
            LEFT JOIN transaction_items ti ON p.id = ti.product_id
            GROUP BY p.id";
    $stmt = $con->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetch_locations_pdo($con)
{
    $stmt = $con->prepare("SELECT * FROM locations ORDER BY id DESC");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Coffee Shop Admin Dashboard</title>
    <link rel="stylesheet" href="./css/main.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
</head>

<body>
    <div class="dashboard">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="logo">
                <span>Cups&Cuddles</span>
            </div>

            <nav class="nav-menu">
                <a href="#" class="nav-item active" data-section="dashboard-overview">
                    <span class="nav-icon"><i class="bi bi-grid-fill"></i></span>
                    <span>Dashboard</span>
                </a>
                <a href="#" class="nav-item" data-section="live-orders">
                    <span class="nav-icon"><i class="bi bi-lightning-charge-fill"></i></span>
                    <span>Live Orders</span>
                </a>
                <a href="#" class="nav-item" data-section="order-history">
                    <span class="nav-icon"><i class="bi bi-clock-history"></i></span>
                    <span>Order History</span>
                </a>
                <a href="#" class="nav-item" data-section="products">
                    <span class="nav-icon"><i class="bi bi-box-seam"></i></span>
                    <span>Products</span>
                </a>
                <a href="#" class="nav-item" data-section="toppings">
                    <span class="nav-icon"><i class="bi bi-plus-square"></i></span>
                    <span>Toppings</span>
                </a>
                <a href="#" class="nav-item" data-section="active-location">
                    <span class="nav-icon"><i class="bi bi-geo-alt-fill"></i></span>
                    <span>Active Location</span>
                </a>
                 <a href="#" class="nav-item" data-section="promos">
                     <span class="nav-icon"><i class="bi bi-tags-fill"></i></span>
                     <span>Promotions</span>
                 </a>
                <?php if (Database::isSuperAdmin()): ?>
                    <a href="#" class="nav-item" data-section="add-admin">
                        <span class="nav-icon"><i class="bi bi-person-plus-fill"></i></span>
                        <span>Add Admin</span>
                    </a>
                <?php else: ?>
                    <a href="#" class="nav-item disabled" style="pointer-events:none;opacity:0.5;">
                        <span class="nav-icon"><i class="bi bi-person-plus-fill"></i></span>
                        <span>Add Admin</span>
                    </a>
                <?php endif; ?>
            </nav>

            <!-- Replace Busy Mode with Logout button -->
            <div class="sidebar-logout" style="padding: 15px 20px; border-top: 1px solid #eaedf0; margin-top: auto;">
                <form action="admin_logout.php" method="post" style="display:block;">
                    <button type="submit" class="btn-secondary" style="width:100%;">Logout</button>
                </form>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <header class="header"></header>
            <!-- Page Content -->
            <div class="page-content">
                <!-- Dashboard Overview Section -->
                <div id="dashboard-overview-section" class="content-section active">
                    <div class="dashboard-overview">
                        <!-- Top Stats Cards -->
                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="bi bi-cup-hot"></i>
                                </div>
                                <h3 id="stat-total-orders">0</h3>
                                <p>Total Orders Today</p>
                            </div>
                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="bi bi-hourglass-split"></i>
                                </div>
                                <h3 id="stat-pending-orders">0</h3>
                                <p>Pending Orders</p>
                            </div>
                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </div>
                                <h3 id="stat-preparing-orders">0</h3>
                                <p>Preparing Orders</p>
                            </div>
                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="bi bi-check-circle"></i>
                                </div>
                                <h3 id="stat-ready-orders">0</h3>
                                <p>Ready Orders</p>
                            </div>
                        </div>



                        <!-- Bottom Grid -->
                        <div class="bottom-grid" style="display:flex;gap:32px;align-items:flex-start;background:transparent;">
                            <div class="dashboard-card" style="background:#eafbe6;border-radius:18px;box-shadow:0 2px 8px rgba(0,0,0,0.04);padding:28px 32px 18px 32px;min-width:340px;flex:1;">
                                <h3 style="font-size:1.35rem;font-weight:600;margin-bottom:18px;color:#2d4a3a;letter-spacing:0.5px;font-family:'Inter',sans-serif;">Recent Customers</h3>
                                <ul class="transactions-list" style="padding:0;margin:0;list-style:none;">
                                    <?php
                                    include __DIR__ . '/AJAX/fetch_recent_customers.php';
                                    foreach ($recentCustomers as $cust): ?>
                                        <li style="display:flex;align-items:center;justify-content:space-between;padding:12px 0;border-bottom:1px solid #d6f5c9;">
                                            <div>
                                                <span style="font-weight:700;color:#2d4a3a;font-family:'Inter',sans-serif;display:block;">
                                                    <?= htmlspecialchars($cust['user_FN']) ?><?= $cust['user_LN'] ? ' ' . htmlspecialchars($cust['user_LN']) : '' ?>
                                                </span>
                                                <span style="font-size:13px;color:#7ca37c;font-family:'Inter',sans-serif;display:block;">
                                                    <?= timeAgo($cust['last_transaction']) ?>
                                                </span>
                                            </div>
                                            <span style="font-size:1.1rem;font-weight:700;color:#22a06b;font-family:'Inter',sans-serif;">₱<?= number_format($cust['total_amount'], 2) ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>

                            <!-- Revenue Overview -->
                            <div class="dashboard-card" style="background:#eafbe6;border-radius:18px;box-shadow:0 2px 8px rgba(0,0,0,0.04);padding:28px 32px 18px 32px;min-width:340px;flex:1;">
                                <h3 style="font-size:1.35rem;font-weight:600;margin-bottom:18px;color:#2d4a3a;letter-spacing:0.5px;font-family:'Inter',sans-serif;">Revenue Overview</h3>
                                <div id="revenue-overview" style="padding:8px 0;display:flex;flex-direction:column;gap:18px;">
                                    <div style="display:flex;align-items:center;justify-content:space-between;font-family:'Inter',sans-serif;">
                                        <span>Today</span>
                                        <span id="revenue-today" style="font-weight:700;color:#22a06b;">₱0.00</span>
                                    </div>
                                    <div style="background:#d6f5c9;height:7px;border-radius:6px;width:100%;margin-bottom:2px;">
                                        <div id="bar-today" style="background:#22a06b;height:100%;border-radius:6px;width:0%;transition:width 0.4s;"></div>
                                    </div>
                                    <div style="display:flex;align-items:center;justify-content:space-between;font-family:'Inter',sans-serif;">
                                        <span>This Week</span>
                                        <span id="revenue-week" style="font-weight:700;color:#22a06b;">₱0.00</span>
                                    </div>
                                    <div style="background:#d6f5c9;height:7px;border-radius:6px;width:100%;margin-bottom:2px;">
                                        <div id="bar-week" style="background:#22a06b;height:100%;border-radius:6px;width:0%;transition:width 0.4s;"></div>
                                    </div>
                                    <div style="display:flex;align-items:center;justify-content:space-between;font-family:'Inter',sans-serif;">
                                        <span>This Month</span>
                                        <span id="revenue-month" style="font-weight:700;color:#22a06b;">₱0.00</span>
                                    </div>
                                    <div style="background:#d6f5c9;height:7px;border-radius:6px;width:100%;margin-bottom:2px;">
                                        <div id="bar-month" style="background:#22a06b;height:100%;border-radius:6px;width:0%;transition:width 0.4s;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Order History Section -->
                <div id="order-history-section" class="content-section">
                    <h1>Order History</h1>
                    <!-- Tab Navigation -->
                    <div class="tabs">
                        <a href="#" class="tab active">Picked Up Orders</a>
                    </div>
                    <!-- Orders Table (Dynamic from transactions) -->
                    <div class="table-container">
                        <table class="orders-table" border="1" cellpadding="8" cellspacing="0" style="width:100%; border-collapse:collapse; font-family: Arial, sans-serif; font-size: 14px; box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05); border: 1px solid #e5e7eb;">
                            <thead style="background-color: #f9fafb; text-align: left;">
                                <tr style="border-bottom: 2px solid #e5e7eb;">
                                    <th style="padding: 12px 16px; color: #111827;">Reference Number</th>
                                    <th style="padding: 12px 16px; color: #111827; text-align:center;">Item</th>
                                    <th style="padding: 12px 16px; color: #111827; text-align:center;">Quantity</th>
                                    <th style="padding: 12px 16px; color: #111827;">Customer</th>
                                    <th style="padding: 12px 16px; color: #111827;">Total</th>
                                    <th style="padding: 12px 16px; color: #111827;">Status</th>
                                </tr>
                            </thead>
                            <tbody id="pickedup-orders-tbody">
                                <?php
                                $orders = fetch_pickedup_orders_pdo($con);
                                $showMore = count($orders) > 5;
                                if (empty($orders)) {
                                    echo '<tr><td colspan="6" style="text-align:center;">No picked up orders found.</td></tr>';
                                } else {
                                    $displayOrders = array_slice($orders, 0, 5);
                                    foreach ($displayOrders as $order):
                                        $rowspan = count($order['items']) ?: 1;
                                        $first = true;
                                        foreach ($order['items'] as $item): ?>
                                            <tr>
                                                <?php if ($first): ?>
                                                    <td rowspan="<?= $rowspan ?>"><?= htmlspecialchars($order['reference_number']) ?></td>
                                                <?php endif; ?>
                                                <td><?= htmlspecialchars($item['name']) ?></td>
                                                <td style="text-align:center;"><?= htmlspecialchars($item['quantity']) ?></td>
                                                <?php if ($first): ?>
                                                    <td rowspan="<?= $rowspan ?>"><?= htmlspecialchars($order['customer_name'] ?? 'Unknown') ?></td>
                                                    <td rowspan="<?= $rowspan ?>">₱<?= htmlspecialchars($order['total_amount'], 2) ?></td>
                                                    <td rowspan="<?= $rowspan ?>"><?= htmlspecialchars(ucwords($order['status'])) ?></td>
                                                <?php endif; ?>
                                            </tr>
                                <?php $first = false;
                                        endforeach;
                                    endforeach;
                                } ?>
                            </tbody>
                        </table>
                        <?php if ($showMore): ?>
                            <div style="text-align:center;margin-top:12px;">
                                <button id="showMoreOrdersBtn" class="btn-primary" style="padding:8px 24px;">Show More</button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Live Orders Section -->
                <div id="live-orders-section" class="content-section">

                    <h1>Live Orders</h1>

                    <div class="tabs" id="live-orders-tabs">
                        <a href="?status=" class="tab<?= $live_status === '' ? ' active' : '' ?>" data-status="">All Orders</a>
                        <a href="?status=preparing" class="tab<?= $live_status === 'preparing' ? ' active' : '' ?>" data-status="preparing">Preparing</a>
                        <a href="?status=ready" class="tab<?= $live_status === 'ready' ? ' active' : '' ?>" data-status="ready">Ready</a>
                        <a href="?status=pending" class="tab<?= $live_status === 'pending' ? ' active' : '' ?>" data-status="pending">Pending</a>
                    </div>

                    <div class="live-orders-grid">
                        <?php
                        $liveOrders = $db->fetch_live_orders_pdo($live_status);
                        foreach ($liveOrders as $order): ?>
                            <div class="order-card <?= htmlspecialchars($order['status']) ?>">
                                <div class="order-header">
                                    <span class="order-id">
                                        <strong>Reference Number:</strong> <?= htmlspecialchars($order['transac_id']) ?>
                                    </span>
                                    <span class="order-time"><?= htmlspecialchars($order['created_at']) ?></span>
                                </div>
                                <div class="customer-info">
                                    <div>
                                        <h4><?= htmlspecialchars($order['customer_name'] ?? 'Unknown') ?></h4>
                                        <p>₱<?= htmlspecialchars($order['total_amount']) ?></p>
                                    </div>
                                    <div class="pickup-info" style="margin: 10px 0;">
                                        <?php if (!empty($order['pickup_time'])): ?>
                                            <p><strong>Pickup Time:</strong> <?= date("g:i A", strtotime($order['pickup_time'])) ?></p>
                                        <?php endif; ?>
                                        <?php if (!empty($order['special_instructions'])): ?>
                                            <p><strong>Note:</strong> <?= htmlspecialchars($order['special_instructions']) ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="order-items">
                                    <ul class="mb-0">
                                        <?php foreach ($order['items'] as $item): ?>
                                            <li>
                                                <?= htmlspecialchars($item['name']) ?>
                                                (<?= htmlspecialchars($item['size']) ?>) &times; <?= (int)$item['quantity'] ?>
                                                - ₱<?= number_format($item['price'], 2) ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                                <div class="order-actions">
                                    <?php if ($order['status'] == 'pending'): ?>
                                        <form method="post" action="update_order_status.php" style="display:inline;">
                                            <input type="hidden" name="id" value="<?= $order['transac_id'] ?>">
                                            <input type="hidden" name="status" value="preparing">
                                            <button type="submit" class="btn-accept">Accept</button>
                                        </form>
                                        <form method="post" action="update_order_status.php" style="display:inline;">
                                            <input type="hidden" name="id" value="<?= $order['transac_id'] ?>">
                                            <input type="hidden" name="status" value="cancelled">
                                            <button type="submit" class="btn-reject">Reject</button>
                                        </form>
                                    <?php elseif ($order['status'] == 'preparing'): ?>
                                        <form method="post" action="update_order_status.php" style="display:inline;">
                                            <input type="hidden" name="id" value="<?= $order['transac_id'] ?>">
                                            <input type="hidden" name="status" value="ready">
                                            <button type="submit" class="btn-ready">Mark as Ready</button>
                                        </form>
                                    <?php elseif ($order['status'] == 'ready'): ?>
                                        <form method="post" action="update_order_status.php" style="display:inline;">
                                            <input type="hidden" name="id" value="<?= $order['transac_id'] ?>">
                                            <input type="hidden" name="status" value="picked up">
                                            <button type="submit" class="btn-complete" style="background:#4caf50; color:#fff; padding:8px 12px; border-radius:4px;">Mark as Picked Up</button>
                                        </form>
                                    <?php elseif ($order['status'] == 'picked up'): ?>
                                        <span class="btn-complete" style="background:#4caf50; color:#fff; padding:8px 12px; border-radius:4px;">Picked Up</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($liveOrders)): ?>
                            <div style="padding:30px;text-align:center;color:#888;">No live orders.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Products Section -->
                <div id="products-section" class="content-section">
                    <h1>Products Management</h1>

                    <!-- Add Product Button (outside modal) -->
                    <button id="showAddProductModalBtn" class="btn-primary" style="margin: 20px;">+ Add Product</button>
                    <div class="tabs">
                        <a href="#" class="tab active">All Products</a>
                    </div>

                    <div class="table-container">
                        <table class="products-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Category</th>
                                    <th>Price</th>
                                    <th>Stock</th>
                                    <th>Status</th>
                                    <th>Sales</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $products = $db->fetch_products_with_sales_pdo();
                                foreach ($products as $product): ?>
                                    <tr data-product-id="<?= htmlspecialchars($product['id']) ?>"
                                        data-product-name="<?= htmlspecialchars($product['name']) ?>"
                                        data-product-category="<?= htmlspecialchars($product['category_id']) ?>"
                                        data-product-price="<?= htmlspecialchars($product['price']) ?>"
                                        data-product-status="<?= htmlspecialchars($product['status']) ?>">
                                        <td><?= htmlspecialchars($product['id']) ?></td>
                                        <td><?= htmlspecialchars($product['category_id']) ?></td>
                                        <td>₱<?= number_format($product['price'], 2) ?></td>
                                        <td class="<?= $product['status'] === 'active' ? 'stock-good' : 'stock-out' ?>">
                                            <?= $product['status'] === 'active' ? 'In Stock' : 'Out of Stock' ?>
                                        </td>
                                        <td>
                                            <span class="status-badge <?= $product['status'] === 'active' ? 'active' : 'inactive' ?>">
                                                <?= ucfirst($product['status']) ?>
                                            </span>
                                        </td>
                                        <td><?= (int)$product['sales'] ?></td>
                                        <td>
                                            <div class="action-menu" style="position: relative;">
                                                <button class="action-btn" type="button"
                                                    style="background-color: #f3f4f6; border: none; border-radius: 50%; width: 36px; height: 36px; font-size: 20px; cursor: pointer;">
                                                    ⋮
                                                </button>

                                                <div class="dropdown-menu"
                                                    style="display: none; position: absolute; z-index: 10; left: -160px; top: 0; background: white; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); padding: 10px 16px; display: flex; flex-direction: row; gap: 12px;  width: 300px;">

                                                    <button type="button" class="menu-item edit-product-btn"
                                                        style="flex: 1; padding: 10px 16px; background: none; border: none; font-size: 14px; color: #374151; cursor: pointer; white-space: nowrap;">
                                                        Edit
                                                    </button>

                                                    <button type="button" class="menu-item toggle-status-btn"
                                                        style="flex: 1; padding: 10px 16px; background: none; border: none; font-size: 14px; color: #2563eb; cursor: pointer; white-space: nowrap;">
                                                        Set <?= $product['status'] === 'active' ? 'Inactive' : 'Active' ?>
                                                    </button>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Add Product Modal -->
                    <div id="addProductModal" class="modal" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:9999;align-items:center;justify-content:center;background:rgba(0,0,0,0.15);">
                        <div class="modal-content" style="background:#ffffff;padding:32px 28px;border-radius:20px;max-width:440px;width:100%;position:relative;box-shadow:0 8px 24px rgba(0,0,0,0.1);">
                            <button id="closeAddProductModal" type="button" style="position:absolute;top:18px;right:18px;font-size:1.5rem;background:none;border:none;color:#555;cursor:pointer;">&times;</button>
                            <h2 style="margin-bottom:24px;font-size:1.5rem;font-weight:600;color:#222;">Add New Product</h2>
                            <form id="addProductForm" enctype="multipart/form-data" method="post" action="add_products.php">
                                <div class="form-group" style="margin-bottom:16px;">
                                    <label style="display:block;margin-bottom:6px;font-weight:500;color:#333;">Product ID</label>
                                    <input type="text" name="id" required class="form-control" placeholder="Product ID" style="width:100%;padding:10px 12px;border:1px solid #ccc;border-radius:10px;font-size:0.95rem;">
                                </div>
                                <div class="form-group" style="margin-bottom:16px;">
                                    <label style="display:block;margin-bottom:6px;font-weight:500;color:#333;">Name</label>
                                    <input type="text" name="name" required class="form-control" placeholder="Product Name" style="width:100%;padding:10px 12px;border:1px solid #ccc;border-radius:10px;font-size:0.95rem;">
                                </div>
                                <div class="form-group" style="margin-bottom:16px;">
                                    <label style="display:block;margin-bottom:6px;font-weight:500;color:#333;">Description</label>
                                    <textarea name="description" required class="form-control" placeholder="Description" style="width:100%;padding:10px 12px;border:1px solid #ccc;border-radius:10px;font-size:0.95rem;resize:vertical;"></textarea>
                                </div>
                                <div class="form-group" style="margin-bottom:16px;">
                                    <label style="display:block;margin-bottom:6px;font-weight:500;color:#333;">Price</label>
                                    <input type="number" name="price" step="0.01" required class="form-control" placeholder="Price" style="width:100%;padding:10px 12px;border:1px solid #ccc;border-radius:10px;font-size:0.95rem;">
                                </div>
                                <div class="form-group" style="margin-bottom:16px;">
                                    <label style="display:block;margin-bottom:6px;font-weight:500;color:#333;">Type of drinks</label>
                                    <select name="data_type" id="productDataTypeSelect" class="form-control" required style="width:100%;padding:10px 12px;border:1px solid #ccc;border-radius:10px;font-size:0.95rem;">
                                        <option value="" disabled selected>Select type</option>
                                        <option value="hot">Hot</option>
                                        <option value="cold">Cold</option>
                                        <option value="pastries">Pastries</option>
                                    </select>
                                </div>
                                <div class="form-group" style="margin-bottom:16px;">
                                    <label style="display:block;margin-bottom:6px;font-weight:500;color:#333;">Category</label>
                                    <div style="display:flex;gap:8px;align-items:center;">
                                        <select name="category" id="productCategorySelect" class="form-control" required style="flex:1;padding:10px 12px;border:1px solid #ccc;border-radius:10px;font-size:0.95rem;"></select>
                                        <input type="text" id="newCategoryInput" class="form-control" placeholder="Add category" style="width:140px;display:none;">
                                        <button type="button" id="addCategoryBtn" class="btn-primary" style="padding:8px 12px;">Add</button>
                                    </div>
                                    <div id="categoryError" style="color:#dc2626;font-size:0.95em;margin-top:4px;"></div>
                                </div>
                                <div class="form-group" style="margin-bottom:16px;">
                                    <label style="display:block;margin-bottom:6px;font-weight:500;color:#333;">Image</label>
                                    <input type="file" name="image" accept="image/*" required class="form-control" style="width:100%;padding:8px 0;border:none;">
                                </div>
                                <div class="form-group" style="margin-bottom:20px;">
                                    <label style="display:block;margin-bottom:6px;font-weight:500;color:#333;">Status</label>
                                    <select name="status" class="form-control" style="width:100%;padding:10px 12px;border:1px solid #ccc;border-radius:10px;font-size:0.95rem;">
                                        <option value="active" selected>Active</option>
                                        <option value="inactive">Inactive</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn-primary" style="width:100%;padding:12px 0;border:none;border-radius:10px;background:#059669;color:white;font-weight:600;font-size:1rem;cursor:pointer;">Add Product</button>
                            </form>
                            <div id="addProductResult" style="margin-top:14px;color:#059669;font-weight:600;font-size:0.95rem;"></div>
                        </div>
                    </div>

                    <!-- Edit Product Modal -->
                    <div id="editProductModal" class="modal" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:9999;align-items:center;justify-content:center;background:rgba(0,0,0,0.15);">
                        <div class="modal-content" style="background:#fff;padding:28px 24px;border-radius:20px;max-width:420px;width:100%;position:relative;box-shadow:0 4px 18px rgba(0,0,0,0.1);">
                            <button id="closeEditProductModal" type="button" style="position:absolute;top:16px;right:16px;font-size:1.5rem;background:none;border:none;cursor:pointer;color:#555;">&times;</button>
                            <h2 style="margin-bottom:20px;font-size:1.25rem;font-weight:600;color:#333;">Edit Product</h2>
                            <form id="editProductForm" method="post">
                                <input type="hidden" name="product_id" id="editProductId">
                                <div class="form-group" style="margin-bottom:14px;">
                                    <label style="display:block;margin-bottom:6px;font-size:0.95rem;color:#555;">Name</label>
                                    <input type="text" name="new_name" id="editProductName" required class="form-control" placeholder="Product Name" style="width:100%;padding:10px 12px;border:1px solid #ccc;border-radius:10px;font-size:0.95rem;">
                                </div>
                                <div class="form-group" style="margin-bottom:14px;">
                                    <label style="display:block;margin-bottom:6px;font-size:0.95rem;color:#555;">Price</label>
                                    <input type="number" name="new_price" id="editProductPrice" step="0.01" required class="form-control" placeholder="Price" style="width:100%;padding:10px 12px;border:1px solid #ccc;border-radius:10px;font-size:0.95rem;">
                                </div>
                                <div class="form-group" style="margin-bottom:14px;">
                                    s <label style="display:block;margin-bottom:6px;font-size:0.95rem;color:#555;">Category</label>
                                    <input type="text" name="new_category" id="editProductCategory" required class="form-control" placeholder="Category" style="width:100%;padding:10px 12px;border:1px solid #ccc;border-radius:10px;font-size:0.95rem;">
                                </div>
                                <button type="submit" class="btn-primary" style="width:100%;background:#059669;color:#fff;padding:12px 0;border:none;border-radius:10px;font-size:0.95rem;cursor:pointer;font-weight:600;">Save Changes</button>
                            </form>
                            <div id="editProductResult" style="margin-top:14px;color:#059669;font-weight:600;font-size:0.95rem;"></div>
                        </div>
                    </div>
                </div>


                <!-- Toppings Section (admin) -->
                <div id="toppings-section" class="content-section">
                    <h1 style="margin-bottom:12px;">Toppings Management</h1>

                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;">
                        <div class="tabs">
                            <a href="#" class="tab active">All Toppings</a>
                        </div>
                        <button id="showAddToppingModalBtn" class="btn-primary" style="padding:10px 14px;border-radius:8px;">+ Add Topping</button>
                    </div>

                    <div class="card" style="background:#f6fff5;border-radius:12px;padding:18px;box-shadow:0 6px 18px rgba(16,185,129,0.05);">
                        <div class="table-container" style="margin-top:4px;">
                            <table class="products-table" id="toppingsTable" style="width:100%;">
                                <thead>
                                    <tr>
                                        <th style="width:60px;">ID</th>
                                        <th>Name</th>
                                        <th style="width:140px;text-align:right;">Price</th>
                                        <th style="width:120px;text-align:center;">Status</th>
                                        <th style="width:160px;text-align:center;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- rows rendered by admin/js/main.js -->
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Add/Edit Topping Modal (unchanged) -->
                    <div id="addToppingModal" class="modal" style="display:none;position:fixed;inset:0;align-items:center;justify-content:center;background:rgba(0,0,0,0.15);z-index:9999;">
                        <div class="modal-content" style="background:#fff;padding:24px;border-radius:12px;max-width:420px;width:100%;">
                            <button id="closeAddToppingModal" type="button" style="position:absolute;right:18px;top:12px;background:none;border:none;font-size:20px;">&times;</button>
                            <h3 id="addToppingTitle">Add Topping</h3>
                            <form id="toppingForm">
                                <input type="hidden" id="toppingId" name="id" value="">
                                <div class="form-group" style="margin-bottom:8px;">
                                    <label style="display:block;margin-bottom:6px;font-weight:600;">Name</label>
                                    <input type="text" id="toppingName" name="name" required style="width:100%;padding:10px;border-radius:8px;border:1px solid #e6f2ea;">
                                </div>
                                <div class="form-group" style="margin-bottom:12px;">
                                    <label style="display:block;margin-bottom:6px;font-weight:600;">Price</label>
                                    <input type="number" step="0.01" id="toppingPrice" name="price" required style="width:100%;padding:10px;border-radius:8px;border:1px solid #e6f2ea;text-align:right;">
                                </div>
                                <div style="display:flex;gap:8px;justify-content:flex-end;">
                                    <button type="button" id="cancelToppingBtn" class="btn-secondary">Cancel</button>
                                    <button type="submit" id="saveToppingBtn" class="btn-primary">Save</button>
                                </div>
                            </form>
                            <div id="toppingFormResult" style="margin-top:8px;color:#dc2626;"></div>
                        </div>
                    </div>
                </div>
                <!-- End Toppings Section -->


                <div id="promos-section" class="content-section">
                    <h1>Promos / Banner Images</h1>

                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;">
                        <div class="tabs"><a href="#" class="tab active">All Promos</a></div>
                        <form action="upload_promo.php" method="post" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;">
                            <input type="text" name="title" placeholder="Title (optional)" style="padding:8px;border-radius:6px;border:1px solid #e6f2ea;">
                            <input type="file" name="promoImage" accept="image/*" required>
                            <button type="submit" class="btn-primary">Upload</button>
                        </form>
                    </div>

                    <div class="card" style="padding:18px;">
                        <div style="display:flex;flex-wrap:wrap;gap:12px;">
                            <?php
                            $promos = $db->fetch_locations_pdo($con); // placeholder - avoid; fetch from promos table instead
                            // Better: query directly:
                            $stmt = $con->prepare("SELECT * FROM promos ORDER BY created_at DESC");
                            $stmt->execute();
                            $promos = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            if (empty($promos)) {
                                echo '<div>No promos yet.</div>';
                            } else {
                                foreach ($promos as $pr) {
                                    $img = htmlspecialchars($pr['image']);
                                    $title = htmlspecialchars($pr['title']);
                                    $active = $pr['active'] ? 'Active' : 'Inactive';
                                    echo "<div style='width:200px;border:1px solid #eefaf0;padding:8px;border-radius:8px;background:#fff;'>
                    <img src=\"{$img}\" style='width:100%;height:120px;object-fit:cover;border-radius:6px;margin-bottom:8px;'>
                    <div style='font-size:0.9rem;font-weight:600;margin-bottom:6px;'>{$title}</div>
                    <div style='display:flex;gap:6px;'>
                      <form method='post' action='delete_promo.php' style='margin:0;'>
                        <input type='hidden' name='id' value='{$pr['id']}'>
                        <input type='hidden' name='action' value='delete'>
                        <button class='btn-secondary' type='submit' style='padding:6px 8px;'>Delete</button>
                      </form>
                      <form method='post' action='delete_promo.php' style='margin:0;'>
                        <input type='hidden' name='id' value='{$pr['id']}'>
                        <input type='hidden' name='action' value='toggle'>
                        <input type='hidden' name='active' value='" . ($pr['active'] ? '0' : '1') . "'>
                        <button class='btn-primary' type='submit' style='padding:6px 8px;'>" . ($pr['active'] ? 'Set Inactive' : 'Set Active') . "</button>
                      </form>
                    </div>
                  </div>";
                                }
                            }
                            ?>
                        </div>
                    </div>
                </div>

                <!-- Active Location Section -->
                <div id="active-location-section" class="content-section">
                    <h1>Active Locations</h1>
                    <div class="page-header">
                        <button id="showAddLocationModalBtn" class="btn-primary" style="margin: 20px;">+ Add Location</button>
                    </div>
                    <div class="tabs">
                        <a href="#" class="tab active">All Locations</a>
                    </div>
                    <div class="table-container">
                        <table class="products-table">
                            <thead>
                                <tr>
                                    <th>Location Name</th>
                                    <th>Status</th>
                                    <th>Image</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="locationsTableBody">
                                <?php
                                $locations = $db->fetch_locations_pdo();
                                if (empty($locations)) {
                                    echo '<tr><td colspan="4" style="text-align:center;">No locations found.</td></tr>';
                                } else {
                                    foreach ($locations as $loc): ?>
                                        <tr data-location-id="<?= $loc['id'] ?>"
                                            data-location-name="<?= htmlspecialchars($loc['name']) ?>"
                                            data-location-status="<?= htmlspecialchars($loc['status']) ?>">
                                            <td><?= htmlspecialchars($loc['name']) ?></td>
                                            <td>
                                                <span class="status-badge <?= $loc['status'] === 'open' ? 'active' : 'inactive' ?>">
                                                    <?= ucfirst($loc['status']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if (!empty($loc['image'])): ?>
                                                    <img src="<?= htmlspecialchars($loc['image']) ?>" alt="Location Image" style="width:60px;height:40px;object-fit:cover;border-radius:6px;">
                                                <?php else: ?>
                                                    <span style="color:#aaa;">No image</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="action-menu" style="position: relative; display: inline-block;">
                                                    <button class="action-btn" type="button"
                                                        style="background-color: #f3f4f6; border: none; border-radius: 50%; width: 36px; height: 36px; font-size: 20px; cursor: pointer; transition: background 0.3s;">
                                                        ⋮
                                                    </button>
                                                    <div class="dropdown-menu"
                                                        style="display: none; position: absolute; z-index: 10; top: 0; right: 100%; background: white; border: 1px solid #e5e7eb; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); overflow: hidden; padding: 8px; display: flex; flex-direction: row; gap: 8px; width: 300px;">

                                                        <button type="button" class="menu-item edit-location-btn"
                                                            style="padding: 10px 16px; background: none; border: none; font-size: 14px; color: #374151; cursor: pointer;">
                                                            Edit
                                                        </button>

                                                        <button type="button" class="menu-item delete-location-btn"
                                                            style="padding: 10px 16px; background: none; border: none; font-size: 14px; color: #ef4444; cursor: pointer;">
                                                            Delete
                                                        </button>

                                                        <button type="button" class="menu-item toggle-location-status-btn"
                                                            style="padding: 10px 16px; background: none; border: none; font-size: 14px; color: #2563eb; cursor: pointer;">
                                                            Set <?= $loc['status'] === 'open' ? 'Closed' : 'Open' ?>
                                                        </button>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                <?php endforeach;
                                } ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Add Location Modal -->
                    <div id="addLocationModal" class="modal" style="display:none;">
                        <div class="modal-content" style="background:#f9fafb;padding:36px 32px;border-radius:20px;max-width:460px;width:100%;position:relative;box-shadow:0 10px 25px rgba(0,0,0,0.1);">
                            <button id="closeAddLocationModal" type="button" style="position:absolute;top:16px;right:16px;font-size:1.5rem;background:none;border:none;cursor:pointer;color:#6b7280;">&times;</button>
                            <h2 style="margin-bottom:24px;font-size:1.4rem;color:#111827;">Add New Location</h2>
                            <form id="addLocationForm" method="post" action="locations.php" enctype="multipart/form-data">
                                <div class="form-group" style="margin-bottom:16px;">
                                    <label style="display:block;margin-bottom:6px;font-weight:500;color:#374151;">Location Name</label>
                                    <input type="text" name="name" required class="form-control" placeholder="Location Name" style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;">
                                </div>
                                <div class="form-group" style="margin-bottom:16px;">
                                    <label style="display:block;margin-bottom:6px;font-weight:500;color:#374151;">Image</label>
                                    <input type="file" name="image" accept="image/*" class="form-control" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:8px;">
                                </div>
                                <div class="form-group" style="margin-bottom:20px;">
                                    <label style="display:block;margin-bottom:6px;font-weight:500;color:#374151;">Status</label>
                                    <select name="status" class="form-control" style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;">
                                        <option value="open" selected>Open</option>
                                        <option value="closed">Closed</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn-primary" style="width:100%;padding:12px;background-color:#059669;color:#fff;border:none;border-radius:10px;font-weight:600;cursor:pointer;">Add Location</button>
                            </form>
                            <div id="addLocationResult" style="margin-top:14px;color:#10b981;font-weight:600;"></div>
                        </div>
                    </div>

                    <!-- Edit Location Modal -->
                    <div id="editLocationModal" class="modal" style="display:none;">
                        <div class="modal-content" style="background:#f9fafb;padding:36px 32px;border-radius:20px;max-width:460px;width:100%;position:relative;box-shadow:0 10px 25px rgba(0,0,0,0.1);">
                            <button id="closeEditLocationModal" type="button" style="position:absolute;top:16px;right:16px;font-size:1.5rem;background:none;border:none;cursor:pointer;color:#6b7280;">&times;</button>
                            <h2 style="margin-bottom:24px;font-size:1.4rem;color:#111827;">Edit Location</h2>
                            <form id="editLocationForm" method="post" enctype="multipart/form-data">
                                <input type="hidden" name="id" id="editLocationId">
                                <div class="form-group" style="margin-bottom:16px;">
                                    <label style="display:block;margin-bottom:6px;font-weight:500;color:#374151;">Location Name</label>
                                    <input type="text" name="name" id="editLocationName" required class="form-control" placeholder="Location Name" style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;">
                                </div>
                                <div class="form-group" style="margin-bottom:16px;">
                                    <label style="display:block;margin-bottom:6px;font-weight:500;color:#374151;">Image (leave blank to keep current)</label>
                                    <input type="file" name="image" accept="image/*" class="form-control" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:8px;">
                                </div>
                                <div class="form-group" style="margin-bottom:20px;">
                                    <label style="display:block;margin-bottom:6px;font-weight:500;color:#374151;">Status</label>
                                    <select name="status" id="editLocationStatus" class="form-control" style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;">
                                        <option value="open">Open</option>
                                        <option value="closed">Closed</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn-primary" style="width:100%;padding:12px;background-color:#059669;color:#fff;border:none;border-radius:10px;font-weight:600;cursor:pointer;">Save Changes</button>
                            </form>
                            <div id="editLocationResult" style="margin-top:14px;color:#10b981;font-weight:600;"></div>
                        </div>
                    </div>
                </div>

                <!-- Add Admin Section -->
                <div id="add-admin-section" class="content-section">
                    <h1 style="text-align:center;margin-bottom:24px;">Add Admin</h1>
                    <div class="table-container" style="max-width:420px;margin:auto;">
                        <form id="addAdminForm" method="post" action="add_admin.php"
                            style="background:#fff;padding:32px 28px;border-radius:18px;box-shadow:0 4px 12px rgba(0,0,0,0.06);">
                            <div class="form-group" style="margin-bottom:16px;">
                                <label for="adminUsername" style="display:block;font-weight:600;margin-bottom:6px;">Username</label>
                                <input type="text" name="username" id="adminUsername" required
                                    class="form-control" placeholder="Enter username"
                                    style="width:100%;padding:10px 14px;border:1px solid #ccc;border-radius:8px;">
                            </div>
                            <div class="form-group" style="margin-bottom:16px;">
                                <label for="adminEmail" style="display:block;font-weight:600;margin-bottom:6px;">Email</label>
                                <input type="email" name="admin_email" id="adminEmail" required
                                    class="form-control" placeholder="Enter email"
                                    style="width:100%;padding:10px 14px;border:1px solid #ccc;border-radius:8px;">
                            </div>
                            <div class="form-group" style="margin-bottom:16px;">
                                <label for="adminPassword" style="display:block;font-weight:600;margin-bottom:6px;">Password</label>
                                <input type="password" name="password" id="adminPassword" required
                                    class="form-control" placeholder="Enter password"
                                    style="width:100%;padding:10px 14px;border:1px solid #ccc;border-radius:8px;">
                            </div>
                            <button type="submit" class="btn-primary"
                                style="width:100%;background-color:#059669;color:#fff;padding:12px;border:none;border-radius:10px;font-weight:600;cursor:pointer;">
                                Add Admin
                            </button>
                            <div id="addAdminResult" style="margin-top:12px;color:#059669;font-weight:600;"></div>
                        </form>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <script>
        // Navigation functionality
        document.addEventListener("DOMContentLoaded", function() {
            const navItems = document.querySelectorAll('.nav-item');
            const contentSections = document.querySelectorAll('.content-section');

            navItems.forEach(item => {
                item.addEventListener('click', function(e) {
                    e.preventDefault();
                    const targetSectionId = this.getAttribute('data-section');

                    // Remove 'active' from all nav items and content sections
                    navItems.forEach(nav => nav.classList.remove('active'));
                    contentSections.forEach(section => section.classList.remove('active'));

                    // Add 'active' to clicked nav item and corresponding section
                    this.classList.add('active');
                    const targetSection = document.getElementById(targetSectionId + '-section');
                    if (targetSection) {
                        targetSection.classList.add('active');
                    }
                });
            });

            // Dropdown menu functionality
            document.addEventListener('click', function(e) {
                // Close all dropdowns when clicking outside
                if (!e.target.closest('.action-menu')) {
                    document.querySelectorAll('.dropdown-menu').forEach(function(menu) {
                        menu.style.display = "none";
                    });
                }
            });

            // Toggle dropdown menus
            document.querySelectorAll('.action-btn').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();

                    // Close all other dropdowns first
                    document.querySelectorAll('.dropdown-menu').forEach(function(menu) {
                        if (menu !== btn.nextElementSibling) {
                            menu.style.display = "none";
                        }
                    });

                    // Toggle current dropdown
                    const dropdown = btn.nextElementSibling;
                    if (dropdown && dropdown.classList.contains('dropdown-menu')) {
                        dropdown.style.display = dropdown.style.display === "flex" ? "none" : "flex";
                    }
                });
            });

            // Product management functionality
            // Toggle product status via AJAX
            document.querySelectorAll('.toggle-status-btn').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var row = btn.closest('tr');
                    var productId = row.getAttribute('data-product-id');
                    var currentStatus = row.getAttribute('data-product-status');
                    var newStatus = currentStatus === 'active' ? 'inactive' : 'active';

                    fetch('update_product_status.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: 'id=' + encodeURIComponent(productId) + '&status=' + encodeURIComponent(newStatus)
                    }).then(() => {
                        location.reload();
                    });
                });
            });

            // Edit product modal logic
            const editModal = document.getElementById('editProductModal');
            const editForm = document.getElementById('editProductForm');
            const closeEditModalBtn = document.getElementById('closeEditProductModal');

            if (editModal && editForm && closeEditModalBtn) {
                document.querySelectorAll('.edit-product-btn').forEach(function(btn) {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        var row = btn.closest('tr');
                        document.getElementById('editProductId').value = row.getAttribute('data-product-id');
                        document.getElementById('editProductName').value = row.getAttribute('data-product-name');
                        document.getElementById('editProductPrice').value = row.getAttribute('data-product-price');
                        document.getElementById('editProductCategory').value = row.getAttribute('data-product-category');
                        editModal.style.display = 'flex';
                    });
                });

                closeEditModalBtn.onclick = function() {
                    editModal.style.display = 'none';
                };

                editModal.addEventListener('click', function(e) {
                    if (e.target === editModal) {
                        editModal.style.display = 'none';
                    }
                });

                editForm.onsubmit = function(e) {
                    e.preventDefault();
                    const formData = new FormData(editForm);
                    fetch('update_product.php', {
                        method: 'POST',
                        body: new URLSearchParams(formData)
                    }).then(() => {
                        editModal.style.display = 'none';
                        location.reload();
                    });
                };
            }

            // Add Product Modal logic
            var addProductModal = document.getElementById('addProductModal');
            var showAddProductModalBtn = document.getElementById('showAddProductModalBtn');
            var closeAddProductModal = document.getElementById('closeAddProductModal');
            var addProductForm = document.getElementById('addProductForm');
            var addProductResult = document.getElementById('addProductResult');

            // Category dropdown logic
            var categorySelect = document.getElementById('productCategorySelect');
            var addCategoryBtn = document.getElementById('addCategoryBtn');
            var newCategoryInput = document.getElementById('newCategoryInput');
            var categoryError = document.getElementById('categoryError');

            function loadCategories() {
                fetch('categories.php')
                    .then(res => res.json())
                    .then(categories => {
                        categorySelect.innerHTML = ""; // Clear previous options
                        categories.forEach(category => {
                            const opt = document.createElement('option');
                            opt.value = category.name; // Use name as value
                            opt.textContent = category.name;
                            categorySelect.appendChild(opt);
                        });
                        // Add option for new category
                        const addNewOpt = document.createElement('option');
                        addNewOpt.value = '__add_new__';
                        addNewOpt.textContent = '+ Add new category';
                        categorySelect.appendChild(addNewOpt);
                    });
            }
            loadCategories();

            categorySelect.addEventListener('change', function() {
                if (this.value === '__add_new__') {
                    newCategoryInput.style.display = 'block';
                    newCategoryInput.focus();
                } else {
                    newCategoryInput.style.display = 'none';
                    categoryError.textContent = '';
                }
            });

            addCategoryBtn.addEventListener('click', function() {
                if (newCategoryInput.style.display !== 'block') {
                    categorySelect.value = '__add_new__';
                    newCategoryInput.style.display = 'block';
                    newCategoryInput.focus();
                    return;
                }
                var newCat = newCategoryInput.value.trim();
                if (!newCat) {
                    categoryError.textContent = 'Please enter a category name.';
                    return;
                }
                fetch('categories.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: 'new_category=' + encodeURIComponent(newCat)
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            loadCategories();
                            setTimeout(() => {
                                categorySelect.value = newCat;
                                newCategoryInput.value = '';
                                newCategoryInput.style.display = 'none';
                                categoryError.textContent = '';
                            }, 300);
                        } else {
                            categoryError.textContent = data.error || 'Failed to add category.';
                        }
                    });
            });

            // Ensure Add Product button shows the modal
            if (showAddProductModalBtn && addProductModal) {
                showAddProductModalBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    addProductModal.style.display = 'flex';
                });
            }

            if (closeAddProductModal && addProductModal) {
                closeAddProductModal.addEventListener('click', function(e) {
                    e.preventDefault();
                    addProductModal.style.display = 'none';
                    if (addProductResult) addProductResult.textContent = '';
                    if (addProductForm) addProductForm.reset();
                });
            }

            if (addProductModal) {
                addProductModal.addEventListener('click', function(e) {
                    if (e.target === addProductModal) {
                        addProductModal.style.display = 'none';
                        if (addProductResult) addProductResult.textContent = '';
                        if (addProductForm) addProductForm.reset();

                    }
                });
            }

            if (addProductForm) {
                addProductForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    if (addProductResult) addProductResult.textContent = '';
                    // Fix: ensure category is set to new value if new category was just added
                    if (categorySelect.value === '__add_new__' && newCategoryInput.value.trim()) {
                        categorySelect.value = newCategoryInput.value.trim();
                    }
                    const formData = new FormData(addProductForm);
                    fetch('add_products.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(res => res.text())
                        .then(text => {
                            if (addProductResult) addProductResult.textContent = text;
                            if (text.toLowerCase().includes('success')) {
                                setTimeout(() => {
                                    addProductModal.style.display = 'none';
                                    addProductForm.reset();
                                    location.reload();
                                }, 1200);
                            }
                        })
                        .catch(() => {
                            if (addProductResult) addProductResult.textContent = 'Failed to add product.';
                        });
                });
            }

            // Location management functionality
            // Add Location Modal
            var addLocationModal = document.getElementById('addLocationModal');
            var showAddLocationModalBtn = document.getElementById('showAddLocationModalBtn');
            var closeAddLocationModal = document.getElementById('closeAddLocationModal');
            var addLocationForm = document.getElementById('addLocationForm');
            var addLocationResult = document.getElementById('addLocationResult');

            if (showAddLocationModalBtn && addLocationModal) {
                showAddLocationModalBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    addLocationModal.style.display = 'flex';
                });
            }

            if (closeAddLocationModal && addLocationModal) {
                closeAddLocationModal.addEventListener('click', function(e) {
                    e.preventDefault();
                    addLocationModal.style.display = 'none';
                    if (addLocationResult) addLocationResult.textContent = '';
                    if (addLocationForm) addLocationForm.reset();
                });
            }

            if (addLocationModal) {
                addLocationModal.addEventListener('click', function(e) {
                    if (e.target === addLocationModal) {
                        addLocationModal.style.display = 'none';
                        if (addLocationResult) addLocationResult.textContent = '';
                        if (addLocationForm) addLocationForm.reset();
                    }
                });
            }

            if (addLocationForm) {
                addLocationForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    if (addLocationResult) addLocationResult.textContent = '';
                    const formData = new FormData(addLocationForm);
                    fetch('locations.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(res => res.text())
                        .then(text => {
                            if (addLocationResult) addLocationResult.textContent = text;
                            if (text.toLowerCase().includes('success')) {
                                setTimeout(() => {
                                    addLocationModal.style.display = 'none';
                                    addLocationForm.reset();
                                    location.reload();
                                }, 1200);
                            }
                        })
                        .catch(() => {
                            if (addLocationResult) addLocationResult.textContent = 'Failed to add location.';
                        });
                });
            }

            // Edit Location Modal
            var editLocationModal = document.getElementById('editLocationModal');
            var editLocationForm = document.getElementById('editLocationForm');
            var closeEditLocationModal = document.getElementById('closeEditLocationModal');
            var editLocationResult = document.getElementById('editLocationResult');

            document.querySelectorAll('.edit-location-btn').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var row = btn.closest('tr');
                    document.getElementById('editLocationId').value = row.getAttribute('data-location-id');
                    document.getElementById('editLocationName').value = row.getAttribute('data-location-name');
                    document.getElementById('editLocationStatus').value = row.getAttribute('data-location-status');
                    editLocationModal.style.display = 'flex';
                });
            });

            if (closeEditLocationModal && editLocationModal) {
                closeEditLocationModal.addEventListener('click', function(e) {
                    e.preventDefault();
                    editLocationModal.style.display = 'none';
                    if (editLocationResult) editLocationResult.textContent = '';
                    if (editLocationForm) editLocationForm.reset();
                });
            }

            if (editLocationModal) {
                editLocationModal.addEventListener('click', function(e) {
                    if (e.target === editLocationModal) {
                        editLocationModal.style.display = 'none';
                        if (editLocationResult) editLocationResult.textContent = '';
                        if (editLocationForm) editLocationForm.reset();
                    }
                });
            }

            if (editLocationForm) {
                editLocationForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    if (editLocationResult) editLocationResult.textContent = '';
                    const formData = new FormData(editLocationForm);
                    formData.append('action', 'edit');
                    fetch('locations.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(res => res.text())
                        .then(text => {
                            if (editLocationResult) editLocationResult.textContent = text;
                            if (text.toLowerCase().includes('success')) {
                                setTimeout(() => {
                                    editLocationModal.style.display = 'none';
                                    editLocationForm.reset();
                                    location.reload();
                                }, 1200);
                            }
                        })
                        .catch(() => {
                            if (editLocationResult) editLocationResult.textContent = 'Failed to update location.';
                        });
                });
            }

            // Delete Location
            document.querySelectorAll('.delete-location-btn').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (!confirm('Are you sure you want to delete this location?')) return;
                    var row = btn.closest('tr');
                    var id = row.getAttribute('data-location-id');
                    fetch('locations.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded'
                            },
                            body: 'action=delete&id=' + encodeURIComponent(id)
                        })
                        .then(res => res.text())
                        .then(() => location.reload());
                });
            });

            // Toggle Location Status
            document.querySelectorAll('.toggle-location-status-btn').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var row = btn.closest('tr');
                    var id = row.getAttribute('data-location-id');
                    var currentStatus = row.getAttribute('data-location-status');
                    var newStatus = currentStatus === 'open' ? 'closed' : 'open';
                    fetch('locations.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded'
                            },
                            body: 'action=toggle_status&id=' + encodeURIComponent(id) + '&status=' + encodeURIComponent(newStatus)
                        })
                        .then(res => res.text())
                        .then(() => location.reload());
                });
            });

            // Add Admin functionality
            var addAdminForm = document.getElementById('addAdminForm');
            var addAdminResult = document.getElementById('addAdminResult');

            if (addAdminForm) {
                addAdminForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    if (addAdminResult) addAdminResult.textContent = '';
                    const formData = new FormData(addAdminForm);
                    fetch('add_admin.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(res => res.text())
                        .then(text => {
                            if (addAdminResult) addAdminResult.textContent = text;
                            if (text.toLowerCase().includes('success')) {
                                addAdminForm.reset();
                            }
                        })
                        .catch(() => {
                            if (addAdminResult) addAdminResult.textContent = 'Failed to add admin.';
                        });
                });
            }

            // Live orders tab functionality
            const liveOrdersTabs = document.querySelectorAll('#live-orders-tabs .tab');
            liveOrdersTabs.forEach(tab => {
                tab.addEventListener('click', function(e) {
                    e.preventDefault();
                    const status = this.getAttribute('data-status');
                    window.location.href = '?status=' + status;
                });
            });

            // Dashboard stats AJAX update
            function updateDashboardStats() {
                fetch('AJAX/dashboard_stats.php')
                    .then(res => res.json())
                    .then(stats => {
                        document.getElementById('stat-total-orders').textContent = stats.totalOrdersToday;
                        document.getElementById('stat-pending-orders').textContent = stats.pendingOrders;
                        document.getElementById('stat-preparing-orders').textContent = stats.preparingOrders;
                        document.getElementById('stat-ready-orders').textContent = stats.readyOrders;
                    });
            }
            updateDashboardStats();
            setInterval(updateDashboardStats, 15000); // update every 15s

            // Show More Orders functionality
            const showMoreBtn = document.getElementById('showMoreOrdersBtn');
            if (showMoreBtn) {
                showMoreBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    showMoreBtn.disabled = true;
                    fetch('AJAX/fetch_all_pickedup_orders.php')
                        .then(res => res.json())
                        .then(data => {
                            const tbody = document.getElementById('pickedup-orders-tbody');
                            if (!tbody) return;
                            tbody.innerHTML = '';
                            if (!data || data.length === 0) {
                                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;">No picked up orders found.</td></tr>';
                            } else {
                                data.forEach(order => {
                                    if (!order.items || order.items.length === 0) return;
                                    let first = true;
                                    order.items.forEach((item, idx) => {
                                        const tr = document.createElement('tr');
                                        if (first) {
                                            const tdRef = document.createElement('td');
                                            tdRef.rowSpan = order.items.length;
                                            tdRef.textContent = order.reference_number;
                                            tr.appendChild(tdRef);
                                        }
                                        const tdItem = document.createElement('td');
                                        tdItem.textContent = item.name;
                                        tr.appendChild(tdItem);
                                        const tdQty = document.createElement('td');
                                        tdQty.textContent = item.quantity;
                                        tdQty.style.textAlign = 'center';
                                        tr.appendChild(tdQty);
                                        if (first) {
                                            const tdCust = document.createElement('td');
                                            tdCust.rowSpan = order.items.length;
                                            tdCust.textContent = order.customer_name || 'Unknown';
                                            tr.appendChild(tdCust);
                                            const tdTotal = document.createElement('td');
                                            tdTotal.rowSpan = order.items.length;
                                            tdTotal.textContent = '₱' + order.total_amount;
                                            tr.appendChild(tdTotal);
                                            const tdStatus = document.createElement('td');
                                            tdStatus.rowSpan = order.items.length;
                                            tdStatus.textContent = order.status.charAt(0).toUpperCase() + order.status.slice(1);
                                            tr.appendChild(tdStatus);
                                        }
                                        tbody.appendChild(tr);
                                        first = false;
                                    });
                                });
                            }
                            showMoreBtn.style.display = 'none';
                        })
                        .catch(() => {
                            showMoreBtn.disabled = false;
                            alert('Failed to load all orders.');
                        });
                });
            }

            // Revenue Overview AJAX update
            function updateRevenueOverview() {
                fetch('AJAX/fetch_revenue_overview.php')
                    .then(res => res.json())
                    .then(data => {
                        if (data.success && data.data) {
                            const today = data.data.today || 0;
                            const week = data.data.week || 0;
                            const month = data.data.month || 0;
                            document.getElementById('revenue-today').textContent = `₱${today.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}`;
                            document.getElementById('revenue-week').textContent = `₱${week.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}`;
                            document.getElementById('revenue-month').textContent = `₱${month.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}`;
                            // Bar widths (relative to month)
                            let max = Math.max(today, week, month, 1);
                            document.getElementById('bar-today').style.width = (today / max * 100) + '%';
                            document.getElementById('bar-week').style.width = (week / max * 100) + '%';
                            document.getElementById('bar-month').style.width = (month / max * 100) + '%';
                        }
                    });
            }
            updateRevenueOverview();
            setInterval(updateRevenueOverview, 15000);
        });
    </script>
    <script src="js/main.js"></script>
</body>

</html>