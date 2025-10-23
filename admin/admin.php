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


$flash = null;
if (!empty($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

// Ensure Database helper is available
require_once __DIR__ . '/database/db_connect.php';

// Server-side access control (redirect non-admins)
if (!(Database::isAdmin() || Database::isSuperAdmin() || (isset($_SESSION['admin_id']) && $_SESSION['admin_id']))) {
    header('Location: ../index.php');
    exit;
}

// Open DB connection for helper functions and later template usage
$db = new Database();
$con = $db->opencon();

// Helper: picked up orders
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
            JOIN products p ON ti.product_id = p.product_id 
            WHERE ti.transaction_id = ?");
        $itemStmt->execute([$order['transac_id']]);
        $order['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return $orders;
}

// Helper: live orders
function fetch_live_orders_pdo($con, $status = '', $location = '', $q = '')
{
    $allowed_statuses = ['pending', 'preparing', 'ready'];
    if ($status === 'cancelled_user') {
        $where = "WHERE t.status = 'cancelled' AND (t.admin_id IS NULL OR t.admin_id = 0)";
        $params = [];
    } elseif ($status !== '' && in_array($status, $allowed_statuses)) {
        $where = "WHERE t.status = ?";
        $params = [$status];
    } else {
        // Default: include active statuses plus user-cancelled for visibility
        $where = "WHERE (t.status IN ('pending','preparing','ready') OR (t.status = 'cancelled' AND (t.admin_id IS NULL OR t.admin_id = 0)))";
        $params = [];
    }
    if ($location !== '') {
        $where .= " AND p.pickup_location LIKE ?";
        $params[] = $location . '%';
    }
    if ($q !== '') {
        $where .= " AND (CONCAT_WS(' ', u.user_FN, u.user_LN) LIKE ? OR u.user_FN LIKE ? OR u.user_LN LIKE ?)";
        $like = '%' . $q . '%';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    // Only today's orders for live view
    $where .= " AND DATE(t.created_at) = CURDATE()";
    // Dynamically select the available receipt column (avoid referencing a non-existent column)
    $receiptCol = 'NULL AS gcash_receipt_path';
    try {
        $q = $con->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaction'");
        $cols = array_map(function($r){ return strtolower((string)$r['COLUMN_NAME']); }, $q->fetchAll(PDO::FETCH_ASSOC) ?: []);
        if (in_array('gcash_receipt_path', $cols, true)) {
            $receiptCol = 't.gcash_receipt_path AS gcash_receipt_path';
        } elseif (in_array('gcash_reciept_path', $cols, true)) {
            $receiptCol = 't.gcash_reciept_path AS gcash_receipt_path';
        }
    } catch (Throwable $_) { /* ignore */ }

    $sql = "SELECT t.transac_id, t.user_id, t.total_amount, t.status, t.created_at, t.admin_id,
        u.user_FN AS customer_name, u.user_FN AS user_FN, u.user_LN AS user_LN,
        p.pickup_location, p.pickup_time, p.special_instructions,
           a.admin_id AS approved_by_admin_id, a.username AS approved_by,
           COALESCE(t.payment_method, 'gcash') AS payment_method,
           {$receiptCol}
        FROM transaction t
        LEFT JOIN users u ON t.user_id = u.user_id
        LEFT JOIN pickup_detail p ON t.transac_id = p.transaction_id
        LEFT JOIN admin_users a ON t.admin_id = a.admin_id
        $where
        ORDER BY t.created_at DESC";
    $stmt = $con->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($orders as &$order) {
        $itemStmt = $con->prepare("SELECT ti.quantity, ti.size, ti.price, ti.sugar_level, p.name 
            FROM transaction_items ti 
            JOIN products p ON ti.product_id = p.product_id 
            WHERE ti.transaction_id = ?");
        $itemStmt->execute([$order['transac_id']]);
        $order['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return $orders;
}

// Helper: products with sales summary + latest effective prices
function fetch_products_with_sales_pdo($con)
{
    $tbl = 'product_size_prices';
    try {
        $q = $con->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('product_sizes_prices','product_size_prices') LIMIT 1");
        $name = $q->fetchColumn();
        if ($name) { $tbl = $name; }
    } catch (Throwable $e) { /* ignore */ }

    $sql = "SELECT 
                p.product_id,
                p.name,
                p.category_id,
                p.data_type,
                COALESCE(
                    (SELECT spp.price FROM `{$tbl}` spp WHERE spp.products_pk = p.products_pk AND spp.size='grande' AND spp.effective_to IS NULL LIMIT 1),
                    (SELECT spp.price FROM `{$tbl}` spp WHERE spp.products_pk = p.products_pk AND spp.size='supreme' AND spp.effective_to IS NULL LIMIT 1),
                    0
                ) AS price,
                p.status,
                p.created_at,
                COALESCE(SUM(ti.quantity), 0) AS sales
            FROM products p
            LEFT JOIN transaction_items ti ON p.product_id = ti.product_id
            WHERE p.name != '__placeholder__'
            GROUP BY p.product_id";
    $stmt = $con->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetch_locations_pdo($con)
{
    $stmt = $con->prepare("SELECT * FROM locations ORDER BY location_id DESC");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Set live order filters from query or default
$live_status = isset($_GET['status']) ? $_GET['status'] : '';
$live_location = isset($_GET['location']) ? $_GET['location'] : '';
$live_q = isset($_GET['q']) ? trim($_GET['q']) : '';

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
    <link rel="shortcut icon" href="../img/logo.png" type="image/png">
</head>



<body>
    <div class="dashboard">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="logo">
                <span>Cups&Cuddles</span>
            </div>

            <!-- Receipt Viewer Modal -->
            <div id="receiptModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:5000;align-items:center;justify-content:center;">
                <div style="background:#111827;padding:10px;border-radius:10px;max-width:92vw;max-height:92vh;display:flex;flex-direction:column;gap:10px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;color:#e5e7eb;">
                        <strong>GCash Receipt</strong>
                        <button id="receiptCloseBtn" style="background:#ef4444;color:#fff;border:none;border-radius:6px;padding:6px 10px;cursor:pointer;">Close</button>
                    </div>
                    <div style="overflow:auto;max-width:90vw;max-height:80vh;background:#000;border-radius:8px;display:flex;align-items:center;justify-content:center;">
                        <img id="receiptImg" src="" alt="Receipt" style="max-width:100%;max-height:100%;object-fit:contain;background:#000;" />
                    </div>
                </div>
                <div data-close="backdrop" style="position:absolute;inset:0;"></div>
            </div>

            <!-- Live Orders Action Confirmation Modal -->
            <div id="confirmActionModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:6000;align-items:center;justify-content:center;">
                <div style="background:#ffffff;padding:22px 22px 16px;border-radius:14px;max-width:420px;width:92vw;box-shadow:0 10px 25px rgba(0,0,0,0.15);position:relative;">
                    <button type="button" id="confirmActionClose" aria-label="Close" style="position:absolute;top:10px;right:12px;background:none;border:none;font-size:22px;line-height:1;color:#6b7280;cursor:pointer">&times;</button>
                    <h3 id="confirmActionTitle" style="margin:0 0 10px;font-size:1.1rem;color:#111827;font-weight:700;">Confirm action</h3>
                    <p id="confirmActionMessage" style="margin:0 0 18px;color:#374151;">Are you sure?</p>
                    <div style="display:flex;gap:8px;justify-content:flex-end;">
                        <button type="button" id="confirmActionCancel" class="btn-secondary" style="padding:10px 14px;border-radius:10px;">Cancel</button>
                        <button type="button" id="confirmActionOk" class="btn-primary" style="padding:10px 14px;border-radius:10px;background:#059669;color:#fff;border:none;font-weight:600;">Confirm</button>
                    </div>
                </div>
                <div data-close="backdrop" style="position:absolute;inset:0;"></div>
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
                    <span>Add-ons</span>
                </a>
                <a href="#" class="nav-item" data-section="active-location">
                    <span class="nav-icon"><i class="bi bi-geo-alt-fill"></i></span>
                    <span>Active Location</span>
                </a>
                <a href="#" class="nav-item" data-section="promos">
                    <span class="nav-icon"><i class="bi bi-tags-fill"></i></span>
                    <span>Promotions</span>
                </a><a href="#" class="nav-item" data-section="block-users">
                    <span class="nav-icon"><i class="bi bi-tags-fill"></i></span>
                    <span>Customers</span>
                </a>
                <a href="#" class="nav-item" data-section="reports">
                    <span class="nav-icon"><i class="bi bi-graph-up"></i></span>
                    <span>Reports</span>
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
            <header class="header" style="display:flex;align-items:center;justify-content:flex-end;padding:12px 16px;gap:12px;">
                <?php
                // Pull admin session info; db_connect::loginAdmin sets these
                $adminUsername = isset($_SESSION['admin_username']) ? $_SESSION['admin_username'] : '';
                $adminRole = isset($_SESSION['admin_role']) ? $_SESSION['admin_role'] : '';
                // Fallback: if missing, fetch by admin_id
                if ((!$adminUsername || !$adminRole) && !empty($_SESSION['admin_id'])) {
                    try {
                        $stmtHdr = $con->prepare("SELECT username, role FROM admin_users WHERE admin_id = ? LIMIT 1");
                        $stmtHdr->execute([$_SESSION['admin_id']]);
                        if ($rowHdr = $stmtHdr->fetch(PDO::FETCH_ASSOC)) {
                            $adminUsername = $rowHdr['username'] ?: $adminUsername;
                            $adminRole = $rowHdr['role'] ?: $adminRole;
                            // hydrate session to avoid requery later
                            $_SESSION['admin_username'] = $adminUsername;
                            $_SESSION['admin_role'] = $adminRole;
                        }
                    } catch (Throwable $_) { /* ignore */ }
                }
                if ($adminUsername) {
                    $initial = strtoupper(substr($adminUsername, 0, 1));
                    $roleLabel = $adminRole ? strtoupper($adminRole) : '';
                    echo '<div class="admin-header-user" style="display:flex;align-items:center;gap:10px;">'
                       . '<div class="avatar" style="width:36px;height:36px;border-radius:50%;background:#059669;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;">' . htmlspecialchars($initial) . '</div>'
                       . '<div class="meta" style="display:flex;flex-direction:column;line-height:1.2;">'
                       . '<span style="font-weight:700;color:#111827;">' . htmlspecialchars($adminUsername) . '</span>'
                       . '<span style="font-size:12px;color:#6b7280;">' . htmlspecialchars($roleLabel) . '</span>'
                       . '</div>'
                       . '</div>';
                }
                ?>
            </header>
            <!-- Page Content -->
            <div class="page-content">
                <!-- Locations Management -->
                <div id="active-location-section" class="content-section locations-mgmt-section">
                    <h1 style="margin-bottom:12px;">Locations Management</h1>

                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;">
                        <div class="tabs">
                            <a href="#" class="tab active">All Locations</a>
                        </div>
                        <button id="showAddLocationModalBtn" class="btn-primary" style="padding:10px 14px;border-radius:8px;">+ Add Location</button>
                    </div>

                    <div class="card section-card">
                        <div class="table-container" style="margin-top:4px;">
                        <table class="products-table" id="locationsTable" style="width:100%;">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Image</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php
                            try {
                                $locStmt = $con->prepare("SELECT location_id, name, status, image, admin_id FROM locations ORDER BY location_id DESC");
                                $locStmt->execute();
                                $locRows = $locStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                                if (empty($locRows)) {
                                    echo '<tr><td colspan="5" style="text-align:center;padding:18px;">No locations yet.</td></tr>';
                                } else {
                                    foreach ($locRows as $lr) {
                                        $lid = (int)$lr['location_id'];
                                        $lname = htmlspecialchars($lr['name']);
                                        $lstatus = strtolower($lr['status']) === 'open' ? 'open' : 'closed';
                                        $badgeClass = $lstatus === 'open' ? 'active' : 'inactive';
                                        $nextLabel = $lstatus === 'open' ? 'Set Closed' : 'Set Open';
                                        $imgTag = '';
                                        if (!empty($lr['image'])) {
                                            $rel = htmlspecialchars($lr['image']);
                                            $imgTag = "<img src='../{$rel}' alt='{$lname}' class='location-img' onerror=\"this.style.display='none'\">";
                                        }
                                        echo "<tr data-location-id='{$lid}' data-location-status='{$lstatus}'>".
                                             "<td>{$lid}</td>".
                                             "<td>{$lname}</td>".
                                             "<td>".($imgTag ?: '<span class=\'no-img\'>No Image</span>')."</td>".
                                             "<td><span class='status-badge {$badgeClass}'>".ucfirst($lstatus)."</span></td>".
                                             "<td><button class='btn-secondary toggle-location-status-btn locations-toggle-btn'>{$nextLabel}</button></td>".
                                             "</tr>";
                                    }
                                }
                            } catch (Throwable $e) {
                                echo '<tr><td colspan="5" style="text-align:center;padding:18px;color:#dc2626;">Error loading locations</td></tr>';
                            }
                            ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                    <style>
                    .location-img {
                        width:70px;height:50px;object-fit:cover;border-radius:8px;border:2px solid #e5e7eb;box-shadow:0 1px 4px rgba(0,0,0,0.04);background:#f3f4f6;
                    }
                    .no-img { font-size:12px;color:#64748b; }
                    /* keep default .btn-primary / .btn-secondary from main.css for actions */
                    </style>
                
                    <!-- Add Location Modal (moved here for proper section grouping) -->
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

                    <!-- Edit Location Modal (moved here for proper section grouping) -->
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

                <div id="order-history-section" class="content-section">
                    <h1>Order History</h1>
                    <div class="table-container">
                        <div style="display:flex;justify-content:flex-end;margin-bottom:8px;gap:8px;">
                            <select id="pickedup-sort" style="padding:6px 10px;border:1px solid #059669;border-radius:6px;">
                                <option value="created_desc" selected>Newest order</option>
                                <option value="created_asc">Oldest order</option>
                            </select>
                        </div>
                        <table id="pickedup-orders-table" class="orders-table" style="width:100%;border-collapse:collapse;">
                            <thead>
                                <tr>
                                    <th style="text-align:left;padding:8px;">Reference</th>
                                    <th style="text-align:left;padding:8px;">Customer</th>
                                    <th style="text-align:left;padding:8px;">Items</th>
                                    <th style="text-align:left;padding:8px;">Total (₱)</th>
                                    <th style="text-align:left;padding:8px;">Status</th>
                                    <th style="text-align:left;padding:8px;">Created</th>
                                </tr>
                            </thead>
                            <tbody id="pickedup-orders-tbody">
                                <tr>
                                    <td colspan="6" style="text-align:center;padding:12px;">Loading…</td>
                                </tr>
                            </tbody>
                        </table>

                        <div id="pickedup-pagination" style="display:flex;flex-wrap:wrap;gap:6px;justify-content:center;margin-top:12px;"></div>
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
                        <a href="?status=cancelled_user" class="tab<?= $live_status === 'cancelled_user' ? ' active' : '' ?>" data-status="cancelled_user">Cancelled by user</a>
                    </div>

                    <div class="live-orders-filters" style="display:flex;gap:10px;align-items:center;margin:10px 0 18px;flex-wrap:wrap;">
                        <label for="live-location-filter" style="font-weight:600;">Location:</label>
                        <select id="live-location-filter" style="padding:6px 10px;border:1px solid #059669;border-radius:6px;min-width:220px;">
                            <option value="">All locations</option>
                            <?php
                            try {
                                $locStmt = $con->prepare("SELECT name FROM locations WHERE status='open' ORDER BY name ASC");
                                $locStmt->execute();
                                $locs = $locStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
                                foreach ($locs as $lname) {
                                    $sel = ($live_location !== '' && $live_location === $lname) ? ' selected' : '';
                                    echo '<option value="' . htmlspecialchars($lname, ENT_QUOTES, 'UTF-8') . '"' . $sel . '>' . htmlspecialchars($lname) . '</option>';
                                }
                            } catch (Throwable $e) { /* ignore */ }
                            ?>
                        </select>
                        <label for="live-name-search" style="font-weight:600;margin-left:12px;">Search:</label>
                        <input type="text" id="live-name-search" placeholder="Search by name" value="<?= htmlspecialchars($live_q, ENT_QUOTES, 'UTF-8') ?>" style="padding:6px 10px;border:1px solid #059669;border-radius:6px;min-width:240px;" />
                    </div>

                    <div class="live-orders-grid">
                        <?php
                        // Use the same data + template as AJAX to keep design identical
                        $orders = fetch_live_orders_pdo($con, $live_status, $live_location, $live_q);
                        require __DIR__ . '/AJAX/markup.php';
                        ?>
                    </div>
                    <div id="live-orders-pagination" class="pagination" aria-label="Live orders pagination" style="margin-top:12px;display:flex;gap:6px;flex-wrap:wrap;justify-content:center;"></div>
                </div>

                <!-- Products Section -->
                <div id="products-section" class="content-section">
                    <h1>Products Management</h1>

                    <!-- Add Product Button (outside modal) -->
                    <button id="showAddProductModalBtn" class="btn-primary" style="margin: 20px;">+ Add Product</button>
                    <div class="tabs" id="products-filter-tabs">
                        <a href="#" class="tab active" data-filter="all"><i class="bi bi-grid-3x3-gap" style="margin-right:6px"></i>All Products</a>
                        <a href="#" class="tab" data-filter="hot"><i class="bi bi-cup-hot" style="margin-right:6px"></i>Hot Drinks</a>
                        <a href="#" class="tab" data-filter="cold"><i class="bi bi-snow" style="margin-right:6px"></i>Cold Drinks</a>
                        <a href="#" class="tab" data-filter="pastries"><i class="bi bi-egg-fried" style="margin-right:6px"></i>Pastries</a>
                    </div>

                    <!-- Products Search -->
                    <div id="products-search-bar" style="margin:10px 0 16px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                        <div style="position:relative;flex:1;min-width:260px;max-width:420px;">
                            <input type="text" id="products-search-input" aria-label="Search products" placeholder="Search products by name or ID..."
                                   style="width:100%;padding:10px 36px 10px 12px;border:1px solid #cbd5e1;border-radius:10px;font-size:0.95rem;">
                            <button type="button" id="products-search-clear" aria-label="Clear search"
                                    title="Clear" style="position:absolute;right:6px;top:50%;transform:translateY(-50%);border:none;background:transparent;color:#64748b;font-size:18px;cursor:pointer;display:none;">&times;</button>
                        </div>
                    </div>

                    <div class="table-container">
                        <div style="font-size:12px;color:#64748b;margin:6px 0 10px;">
                            For pastries: columns show Per piece, Box of 4, Box of 6. For drinks: only Grande pricing is used; other columns are not applicable.
                        </div>
                        <table id="products-table" class="products-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Category</th>
                                    <th id="col-price-a">Per piece</th>
                                    <th id="col-price-b">Box of 4</th>
                                    <th id="col-price-c">Box of 6</th>
                                    <th>Stock</th>
                                    <th>Status</th>
                                    <th>Sales</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $products = $db->fetch_products_with_sales_pdo();
                                // Map of size prices to prefill edit modal
                                $sizePriceMap = [];
                                try { $sizePriceMap = $db->get_all_size_prices_for_active(); } catch (Throwable $e) { $sizePriceMap = []; }
                                // Map of pastry variants for active products: [product_id => [ ['label'=>..,'price'=>..], ... ]]
                                $pastryVariantsAll = [];
                                try { $pastryVariantsAll = $db->get_all_pastry_variants(); } catch (Throwable $e) { $pastryVariantsAll = []; }
                                foreach ($products as $product): ?>
                                    <tr data-product-id="<?= htmlspecialchars($product['product_id']) ?>"
                                        data-product-name="<?= htmlspecialchars($product['name']) ?>"
                                        data-product-category="<?= htmlspecialchars($product['category_id']) ?>"
                                        data-product-type="<?= htmlspecialchars(strtolower($product['data_type'] ?? '')) ?>"
                                        data-product-price="<?= htmlspecialchars($product['price']) ?>"
                                        data-price-grande="<?= isset($sizePriceMap[$product['product_id']]['grande']) ? htmlspecialchars($sizePriceMap[$product['product_id']]['grande']) : '' ?>"
                                        
                                        data-product-status="<?= htmlspecialchars($product['status']) ?>">
                                        <td><?= htmlspecialchars($product['product_id']) ?></td>
                                        <td>
                                            <?php $ptype = strtolower($product['data_type'] ?? ''); ?>
                                            <?php if ($ptype): ?>
                                                <span class="type-badge <?= htmlspecialchars($ptype) ?>">
                                                    <?php if ($ptype === 'hot'): ?>
                                                        <i class="bi bi-cup-hot"></i>
                                                    <?php elseif ($ptype === 'cold'): ?>
                                                        <i class="bi bi-snow"></i>
                                                    <?php elseif ($ptype === 'pastries'): ?>
                                                        <i class="bi bi-egg-fried"></i>
                                                    <?php endif; ?>
                                                    <?= ucfirst($ptype) ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color:#64748b">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <?php
                                            $pid = $product['product_id'];
                                            $ptype = strtolower($product['data_type'] ?? '');
                                            if ($ptype === 'pastries') {
                                                $vmap = $pastryVariantsAll[$pid] ?? [];
                                                $labels = ['per piece'=>null,'box of 4'=>null,'box of 6'=>null];
                                                foreach ($vmap as $v) {
                                                    $lbl = strtolower(trim($v['label'] ?? ''));
                                                    // normalize common synonyms
                                                    if (preg_match('/^(per\s*piece|piece|per\s*pc|pc|per\s*pcs|pcs)$/i', $lbl)) { $labels['per piece'] = (float)$v['price']; continue; }
                                                    if (preg_match('/^(box\s*of\s*4|4\s*pcs|4pcs|box\s*\(?4\)?|pack\s*of\s*4)$/i', $lbl)) { $labels['box of 4'] = (float)$v['price']; continue; }
                                                    if (preg_match('/^(box\s*of\s*6|6\s*pcs|6pcs|box\s*\(?6\)?|pack\s*of\s*6)$/i', $lbl)) { $labels['box of 6'] = (float)$v['price']; continue; }
                                                }
                                                $p1 = $labels['per piece'];
                                                $p4 = $labels['box of 4'];
                                                $p6 = $labels['box of 6'];
                                                echo '<td>' . ($p1 !== null ? '₱' . number_format($p1,2) : '<span style="color:#64748b">—</span>') . '</td>';
                                                echo '<td>' . ($p4 !== null ? '₱' . number_format($p4,2) : '<span style="color:#64748b">—</span>') . '</td>';
                                                echo '<td>' . ($p6 !== null ? '₱' . number_format($p6,2) : '<span style="color:#64748b">—</span>') . '</td>';
                                            } else {
                                                $gPrice = isset($sizePriceMap[$pid]['grande']) ? (float)$sizePriceMap[$pid]['grande'] : 0.0;
                                                echo '<td><div>₱' . number_format($gPrice,2) . '</div><span class="price-sub">Grande</span></td>';
                                                echo '<td><span style="color:#64748b">—</span></td>';
                                                echo '<td><span style="color:#64748b">—</span></td>';
                                            }
                                        ?>
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
                                                    style="display:none; position:absolute; z-index:10; left:-160px; top:0; background:#fff; border:1px solid #e5e7eb; border-radius:8px; box-shadow:0 4px 10px rgba(0,0,0,0.1); padding:6px 0; width:220px; display:flex; flex-direction:column; gap:0;">

                                                    <button type="button" class="menu-item edit-product-btn" data-product-id="<?= htmlspecialchars($product['product_id']) ?>"
                                                        style="width:100%; text-align:left; padding:10px 16px; background:none; border:none; font-size:14px; color:#374151; cursor:pointer; white-space:nowrap;">
                                                        Edit
                                                    </button>

                                                    <button type="button" class="menu-item btn-price-history" data-product-id="<?= htmlspecialchars($product['product_id']) ?>"
                                                        style="width:100%; text-align:left; padding:10px 16px; background:none; border:none; font-size:14px; color:#059669; cursor:pointer; white-space:nowrap;">
                                                        Price history
                                                    </button>

                                                    <button type="button" class="menu-item btn-toggle-product"
                                                        data-id="<?= htmlspecialchars($product['product_id']) ?>"
                                                        data-status="<?= htmlspecialchars($product['status']) ?>"
                                                        style="width:100%; text-align:left; padding:10px 16px; background:none; border:none; font-size:14px; color:#2563eb; cursor:pointer; white-space:nowrap;">
                                                        Set <?= $product['status'] === 'active' ? 'Inactive' : 'Active' ?>
                                                    </button>

                                                    <?php if (Database::isSuperAdmin()): ?>
                                                    <button type="button" class="menu-item delete-product-btn" data-product-id="<?= htmlspecialchars($product['product_id']) ?>"
                                                        style="width:100%; text-align:left; padding:10px 16px; background:none; border:none; font-size:14px; color:#dc2626; cursor:pointer; white-space:nowrap;">
                                                        Delete
                                                    </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Products Pagination -->
                    <div id="products-pagination" class="pagination" aria-label="Products pagination" style="margin-top:12px"></div>

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
                        <div class="modal-content" style="background:#fff;padding:28px 24px;border-radius:20px;max-width:460px;width:100%;position:relative;box-shadow:0 4px 18px rgba(0,0,0,0.1);">
                            <button id="closeEditProductModal" type="button" style="position:absolute;top:16px;right:16px;font-size:1.5rem;background:none;border:none;cursor:pointer;color:#555;">&times;</button>
                            <h2 style="margin-bottom:20px;font-size:1.25rem;font-weight:600;color:#333;">Edit Product</h2>
                            <form id="editProductForm" method="post">
                                <input type="hidden" name="product_id" id="editProductId">
                                <input type="hidden" name="new_price" id="editHiddenBasePrice" value="">
                                <div class="form-group" style="margin-bottom:14px;">
                                    <label style="display:block;margin-bottom:6px;font-size:0.95rem;color:#555;">Name</label>
                                    <input type="text" name="new_name" id="editProductName" required class="form-control" placeholder="Product Name" style="width:100%;padding:10px 12px;border:1px solid #ccc;border-radius:10px;font-size:0.95rem;">
                                </div>
                                <div class="form-group" style="margin-bottom:14px;display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                                    <div>
                                        <label style="display:block;margin-bottom:6px;font-size:0.95rem;color:#555;">Grande Price</label>
                                        <input type="number" name="new_grande_price" id="editGrandePrice" step="0.01" class="form-control" placeholder="e.g. 140.00" style="width:100%;padding:10px 12px;border:1px solid #ccc;border-radius:10px;font-size:0.95rem;">
                                    </div>
                                </div>
                                <div class="form-group" style="margin-bottom:14px;">
                                    <label style="display:block;margin-bottom:6px;font-size:0.95rem;color:#555;">Category</label>
                                    <input type="text" name="new_category" id="editProductCategory" required class="form-control" placeholder="Category" style="width:100%;padding:10px 12px;border:1px solid #ccc;border-radius:10px;font-size:0.95rem;">
                                </div>
                                <button type="submit" class="btn-primary" style="width:100%;background:#059669;color:#fff;padding:12px 0;border:none;border-radius:10px;font-size:0.95rem;cursor:pointer;font-weight:600;">Save Changes</button>
                            </form>
                            <div id="editProductResult" style="margin-top:14px;color:#059669;font-weight:600;font-size:0.95rem;"></div>
                        </div>
                    </div>

                    <!-- Edit Pastry Variants Modal -->
                    <div id="editPastryModal" class="modal" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:10000;align-items:center;justify-content:center;background:rgba(0,0,0,0.15);">
                        <div class="modal-content" style="background:#fff;padding:24px;border-radius:16px;max-width:560px;width:100%;position:relative;box-shadow:0 6px 22px rgba(0,0,0,0.12);">
                            <button id="closeEditPastryModal" type="button" style="position:absolute;top:12px;right:12px;font-size:1.5rem;background:none;border:none;color:#555;cursor:pointer">&times;</button>
                            <h2 style="margin-bottom:14px;font-size:1.2rem;font-weight:600;">Edit Pastry Options</h2>
                            <div style="font-size:0.9rem;color:#444;margin-bottom:10px;">Configure labels and prices for this pastry (e.g. Per piece, Box of 4, Box of 6).</div>
                            <input type="hidden" id="pastryProductId" value="">
                            <div class="table-container" style="max-height:320px;overflow:auto;border:1px solid #e5e7eb;border-radius:10px;">
                                <table class="products-table" style="width:100%;">
                                    <thead>
                                        <tr>
                                            <th style="width:55%">Label</th>
                                            <th style="width:30%;text-align:right;">Price (₱)</th>
                                            <th style="width:15%"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="pastryVariantsBody">
                                    </tbody>
                                </table>
                            </div>
                            <div style="display:flex;gap:10px;margin-top:12px;">
                                <button type="button" id="addPastryVariantRow" class="btn-secondary">+ Add Option</button>
                                <div style="flex:1"></div>
                                <button type="button" id="savePastryVariantsBtn" class="btn-primary">Save Variants</button>
                            </div>
                            <div id="savePastryResult" style="margin-top:10px;font-weight:600;"></div>
                        </div>
                    </div>
                </div>

                <!-- Reports Section -->
                <div id="reports-section" class="content-section reports-section">
                    <h1 class="report-title">Monthly Sales Report</h1>
                    <div class="report-filters">
                        <div class="report-filter-group">
                            <label for="report-month">Select Month</label>
                            <input type="month" id="report-month" value="<?php echo date('Y-m'); ?>" class="report-field" />
                        </div>
                        <div class="report-filter-group">
                            <label for="report-location">Location (optional)</label>
                            <select id="report-location" class="report-field">
                                <option value="">All Locations</option>
                                <?php
                                try {
                                    $locStmt = $con->prepare("SELECT DISTINCT name FROM locations WHERE status='open' ORDER BY name ASC");
                                    $locStmt->execute();
                                    $locs = $locStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
                                    foreach ($locs as $lname) {
                                        echo '<option value="' . htmlspecialchars($lname, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($lname) . '</option>';
                                    }
                                } catch (Throwable $e) { /* ignore */ }
                                ?>
                            </select>
                        </div>
                        <div class="report-filter-group">
                            <label for="report-type">Product Type (optional)</label>
                            <select id="report-type" class="report-field">
                                <option value="">All Types</option>
                                <option value="hot">Hot</option>
                                <option value="cold">Cold</option>
                                <option value="pastries">Pastries</option>
                            </select>
                        </div>
                        <div class="report-filter-actions">
                            <button id="btn-load-report" class="btn-primary report-load-btn">Load Report</button>
                            <button id="btn-download-report" class="btn-secondary" style="margin-left:8px;">Download CSV</button>
                            <div id="report-loading" class="report-loading">Loading...</div>
                        </div>
                    </div>

                    <div id="report-summary" class="report-summary-grid"></div>

                    <div class="report-tables-grid">
                        <div class="report-card-block">
                            <h3 class="report-block-title">Daily Breakdown</h3>
                            <div class="table-container">
                                <table id="report-daily-table" class="report-table">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th class="text-right">No. of Orders</th>
                                            <th class="text-right">No. of Items Sold</th>
                                            <th class="text-right">Revenue (₱)</th>
                                        </tr>
                                    </thead>
                                    <tbody id="report-daily-body">
                                        <tr><td colspan="4" class="empty-row">No data</td></tr>
                                    </tbody>
                                </table>
                            </div>
                            <div id="report-daily-pagination" class="pagination" aria-label="Daily breakdown pagination" style="margin-top:10px;"></div>
                        </div>
                        <div class="report-card-block">
                            <h3 class="report-block-title">Products Sold (This Month)</h3>
                            <div class="table-container">
                                <table id="report-products-table" class="report-table">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th class="text-right">Qty</th>
                                        </tr>
                                    </thead>
                                    <tbody id="report-products-body">
                                        <tr><td colspan="2" class="empty-row">No data</td></tr>
                                    </tbody>
                                </table>
                            </div>
                            <div id="report-products-pagination" class="pagination" aria-label="Products sold pagination" style="margin-top:10px;"></div>
                        </div>
                    </div>
                </div>



                    <!-- Toppings Section (admin) -->
                    <div id="toppings-section" class="content-section">
                        <h1 style="margin-bottom:12px;">Add-ons Management</h1>

                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;">
                            <div class="tabs">
                                <a href="#" class="tab active">All Add-ons</a>
                            </div>
                            <button id="showAddToppingModalBtn" class="btn-primary" style="padding:10px 14px;border-radius:8px;">+ Add Add-on</button>
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
                                            <th style="width:240px;text-align:center;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Add/Edit Topping Modal (unchanged) -->
                        <div id="addToppingModal" class="modal" style="display:none;position:fixed;inset:0;align-items:center;justify-content:center;background:rgba(0,0,0,0.15);z-index:9999;">
                            <div class="modal-content" style="background:#fff;padding:24px;border-radius:12px;max-width:420px;width:100%;">
                                <button id="closeAddToppingModal" type="button" style="position:absolute;right:18px;top:12px;background:none;border:none;font-size:20px;">&times;</button>
                                <h3 id="addToppingTitle">Add Add-ons</h3>
                                <form id="toppingForm">
                                    <input type="hidden" id="toppingId" name="topping_id" value="">
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

                    <div class="card section-card">
                        <div id="promos-grid" class="promos-grid">
                            <?php
                            $stmt = $con->prepare("SELECT promo_id, title, image, active, created_at FROM promos ORDER BY created_at DESC");
                            $stmt->execute();
                            $promos = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            if (empty($promos)) {
                                echo '<div>No promos yet.</div>';
                            } else {
                                foreach ($promos as $pr) {
                                    $pid = (int)$pr['promo_id'];
                                    $rawImg = $pr['image'];
                                    $title = htmlspecialchars($pr['title'] ?: '');
                                    $isActive = isset($pr['active']) ? (intval($pr['active']) === 1) : true;
                                    // Fallback path (for very old records) relative to web root
                                    $fallback = $rawImg ? '../' . ltrim($rawImg, '/') : '';
                                    $dateLabel = htmlspecialchars(date('Y-m-d', strtotime($pr['created_at'])));
                                    $imgTag = "<div class='promo-thumb'>".
                                              "<img class='promo-thumb-img' src='serve_promo.php?promo_id={$pid}' alt='".$title."' data-fallback='".htmlspecialchars($fallback, ENT_QUOTES, 'UTF-8')."' " .
                                              "onerror=\"if(!this.dataset.tried && this.dataset.fallback){this.dataset.tried=1;this.src=this.dataset.fallback;}\" loading='lazy'>".
                                              "</div>";

                                    echo "<div class='promo-card' data-promo-id='{$pid}' data-active='".($isActive?1:0)."'>\n".
                                         $imgTag .
                                         "<div class='promo-title'>$title</div>\n".
                                         "<div class='promo-date'>$dateLabel</div>\n".
                                         "<div style='display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:8px;'>".
                                            "<span class='status-badge ".($isActive ? 'active' : 'inactive')." promo-status-badge'>".($isActive ? 'Active' : 'Inactive')."</span>".
                                            "<div style='display:flex;gap:6px;'>".
                                                "<form class='promo-toggle-form' method='post' action='update_promos.php' style='margin:0;'>".
                                                    "<input type='hidden' name='promo_id' value='{$pid}'>".
                                                    "<input type='hidden' name='active' value='".($isActive ? 0 : 1)."'>".
                                                    "<button type='submit' class='btn-primary promo-toggle-btn' style='padding:6px 10px;'>".($isActive ? 'Set Inactive' : 'Set Active')."</button>".
                                                "</form>".
                                                "<form class='promo-delete-form' method='post' action='delete_promo.php' style='margin:0;'>".
                                                    "<input type='hidden' name='id' value='{$pid}'>".
                                                    "<button type='submit' class='btn-secondary promo-delete-btn' style='padding:6px 10px;'>Delete</button>".
                                                "</form>".
                                            "</div>".
                                         "</div>".
                                         "</div>";
                                }
                            }
                            ?>
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

                <!-- Customers Section -->
                <div id="block-users-section" class="content-section">
                    <h1 style="margin-bottom:12px;">Customers</h1>
                    <div class="card" style="background:#fff;border-radius:12px;padding:18px;box-shadow:0 4px 14px rgba(0,0,0,0.06);">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;gap:12px;flex-wrap:wrap;">
                            <div style="font-weight:600;color:#374151;">All Registered Users</div>
                            <div style="display:flex;gap:8px;align-items:center;">
                                <input id="customers-search" type="text" placeholder="Search name or email" style="padding:8px 10px;border:1px solid #e5e7eb;border-radius:8px;min-width:220px;">
                                <button id="refresh-customers" class="btn-secondary" type="button">Refresh</button>
                            </div>
                        </div>
                        <div class="table-container">
                            <table class="products-table" style="width:100%;">
                                <thead>
                                    <tr>
                                        <th style="width:90px;">User</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th style="width:120px;text-align:center;">Status</th>
                                        <th style="width:160px;text-align:center;">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="customers-tbody"></tbody>
                            </table>
                            <div id="customers-pagination" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;justify-content:flex-end;margin-top:10px;"></div>
                        </div>
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

            // Dropdown menu functionality (floating popover)
            function restoreMenu(menu){
                try {
                    if (menu && menu.__originParent && menu.parentNode === document.body) {
                        if (menu.__originNextSibling && menu.__originNextSibling.parentNode === menu.__originParent) {
                            menu.__originParent.insertBefore(menu, menu.__originNextSibling);
                        } else {
                            menu.__originParent.appendChild(menu);
                        }
                    }
                } catch(_) {}
                menu.style.position = '';
                menu.style.top = '';
                menu.style.bottom = '';
                menu.style.left = '';
                menu.style.right = '';
                menu.style.maxHeight = '';
                menu.style.visibility = '';
                menu.classList.remove('open-up');
            }

            function closeAllMenus(){
                document.querySelectorAll('.dropdown-menu').forEach(function(menu){
                    menu.style.display = 'none';
                    restoreMenu(menu);
                });
            }

            document.addEventListener('click', function(e) {
                // Close all dropdowns when clicking outside
                if (!e.target.closest('.action-menu') && !e.target.closest('.dropdown-menu')) {
                    closeAllMenus();
                }
            }, true);

            // Close any open dropdown on ESC and on scroll
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    document.querySelectorAll('.dropdown-menu').forEach(function(menu) {
                        menu.style.display = 'none';
                    });
                }
            });
            window.addEventListener('scroll', function() { closeAllMenus(); }, true);
            window.addEventListener('resize', function() { closeAllMenus(); });

            // Toggle dropdown menus
            document.querySelectorAll('.action-btn').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();

                    // Close all other dropdowns first
                    document.querySelectorAll('.dropdown-menu').forEach(function(menu) {
                        if (menu !== btn.nextElementSibling) {
                            menu.style.display = 'none';
                            restoreMenu(menu);
                        }
                    });

                    // Toggle current dropdown
                    const dropdown = btn.nextElementSibling;
                    if (dropdown && dropdown.classList.contains('dropdown-menu')) {
                        const willOpen = (dropdown.style.display === 'flex' || dropdown.style.display === 'block') ? false : true;
                        if (!willOpen) { dropdown.style.display = 'none'; restoreMenu(dropdown); return; }

                        // Move to body and position as fixed popover
                        dropdown.__originParent = dropdown.parentNode;
                        dropdown.__originNextSibling = dropdown.nextSibling;
                        dropdown.style.display = 'block';
                        dropdown.style.visibility = 'hidden';
                        document.body.appendChild(dropdown);
                        dropdown.style.position = 'fixed';

                        const btnRect = btn.getBoundingClientRect();
                        const menuW = dropdown.offsetWidth || 220;
                        const menuH = dropdown.offsetHeight || 220;
                        const vw = window.innerWidth || document.documentElement.clientWidth;
                        const vh = window.innerHeight || document.documentElement.clientHeight;

                        let left = Math.min(vw - 8 - menuW, Math.max(8, Math.round(btnRect.right - menuW)));
                        const spaceBelow = vh - btnRect.bottom;
                        const preferAbove = spaceBelow < menuH + 12;
                        let top;
                        if (preferAbove) {
                            top = Math.max(8, Math.round(btnRect.top - menuH - 8));
                            dropdown.classList.add('open-up');
                        } else {
                            top = Math.min(vh - 8 - menuH, Math.round(btnRect.bottom + 8));
                            dropdown.classList.remove('open-up');
                        }

                        const available = preferAbove ? (btnRect.top - 16) : (vh - btnRect.bottom - 16);
                        dropdown.style.maxHeight = Math.max(160, Math.min(available, Math.floor(vh * 0.8))) + 'px';
                        dropdown.style.overflowY = 'auto';

                        dropdown.style.left = left + 'px';
                        dropdown.style.top = top + 'px';
                        dropdown.style.right = 'auto';
                        dropdown.style.bottom = 'auto';
                        dropdown.style.visibility = 'visible';
                        dropdown.style.display = 'block';
                    }
                });
            });

            // Product status toggle
            document.querySelectorAll('.btn-toggle-product').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var row = btn.closest('tr');
                    var id = row ? row.getAttribute('data-product-id') : '';
                    if (!id) { id = btn.getAttribute('data-id') || btn.getAttribute('data-product-id') || ''; }
                    if (!row && id) { row = document.querySelector(`tr[data-product-id="${CSS.escape(id)}"]`); }
                    if (!row || !id) return;
                    var currentStatus = (row.getAttribute('data-product-status') || '').toLowerCase();
                    var newStatus = currentStatus === 'active' ? 'inactive' : 'active';

                    // Warn admin when deactivating a product
                    if (newStatus === 'inactive') {
                        var confirmMsg = 'This product will be inactive and removed from the menu until reactivated. Continue?';
                        if (!confirm(confirmMsg)) return;
                    }

                    fetch('update_product_status.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'product_id=' + encodeURIComponent(id) + '&status=' + encodeURIComponent(newStatus)
                    })
                    .then(function(res) { return res.json(); })
                    .then(function(data) {
                        if (data && data.success) {
                            // Update row dataset
                            row.setAttribute('data-product-status', newStatus);

                            // Update stock cell and its class
                            var cells = row.querySelectorAll('td');
                            // cells: [Product(ID), Category, Grande, Supreme, Stock, Status, Sales, Action]
                            var stockCell = cells[4];
                            if (stockCell) {
                                stockCell.textContent = (newStatus === 'active') ? 'In Stock' : 'Out of Stock';
                                stockCell.classList.toggle('stock-good', newStatus === 'active');
                                stockCell.classList.toggle('stock-out', newStatus !== 'active');
                            }

                            // Update status badge
                            var badge = row.querySelector('.status-badge');
                            if (badge) {
                                badge.textContent = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
                                badge.classList.toggle('active', newStatus === 'active');
                                badge.classList.toggle('inactive', newStatus !== 'active');
                            }

                            // Update button text and data-status for next toggle
                            btn.textContent = 'Set ' + (newStatus === 'active' ? 'Inactive' : 'Active');
                            btn.setAttribute('data-status', newStatus);

                            // Close dropdown
                            var dropdown = btn.closest('.dropdown-menu');
                            if (dropdown) dropdown.style.display = 'none';
                        } else {
                            alert((data && data.message) ? data.message : 'Failed to update product status');
                        }
                    })
                    .catch(function() {
                        alert('Request failed');
                    });
                });
            });

            // Super admin: delete / force-delete product handlers
            document.addEventListener('click', function(e) {
                const delBtn = e.target.closest('.delete-product-btn');
                const forceBtn = e.target.closest('.force-delete-product-btn');
                if (!delBtn && !forceBtn) return;

                e.preventDefault();
                e.stopPropagation();

                const btn = delBtn || forceBtn;
                let row = btn.closest('tr');
                let id = row ? row.getAttribute('data-product-id') : '';
                if (!id) { id = btn.getAttribute('data-product-id') || ''; }
                if (!row && id) { row = document.querySelector(`tr[data-product-id="${CSS.escape(id)}"]`); }
                if (!row || !id) return;

                const action = delBtn ? 'delete' : 'force_delete';
                const confirmMsg = action === 'delete'
                    ? 'Delete this product? This will fail if it has references in past transactions.'
                    : 'Force delete this product and remove references from past transactions? This cannot be undone.';
                if (!confirm(confirmMsg)) return;

                const dropdown = btn.closest('.dropdown-menu');
                if (dropdown) dropdown.style.display = 'none';

                function postDelete(act) {
                    return fetch('delete_products.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=' + encodeURIComponent(act) + '&id=' + encodeURIComponent(id)
                    }).then(res => res.text().then(txt => {
                        let data = {};
                        try { data = JSON.parse(txt); } catch (_) {}
                        return { ok: res.ok, status: res.status, data, raw: txt };
                    }));
                }

                postDelete(action).then(result => {
                    const { ok, status, data } = result;
                    if (ok && data && data.success) {
                        row.remove();
                        alert(data.message || 'Product deleted');
                        return;
                    }
                    // If normal delete failed with conflict, offer force delete
                    if (action === 'delete' && status === 409) {
                        if (confirm('Delete failed due to references. Force delete instead? This will remove references and cannot be undone.')) {
                            return postDelete('force_delete').then(fr => {
                                if (fr.ok && fr.data && fr.data.success) {
                                    row.remove();
                                    alert(fr.data.message || 'Force-deleted product');
                                } else {
                                    alert((fr.data && fr.data.message) ? fr.data.message : 'Force delete failed');
                                }
                            });
                        }
                        return;
                    }
                    alert((data && data.message) ? data.message : 'Delete failed');
                }).catch(err => {
                    console.error('delete product error', err);
                    alert('Delete request failed');
                });
            });

            // Product management functionality
            document.querySelectorAll('.toggle-location-status-btn').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    var row = btn.closest('tr');
                    if (!row) return;
                    var id = row.getAttribute('data-location-id');
                    var currentStatus = (row.getAttribute('data-location-status') || '').toLowerCase();
                    var newStatus = currentStatus === 'open' ? 'closed' : 'open';
                    if (!id) return;

                    // Warn admin when closing a location
                    if (newStatus === 'closed') {
                        var confirmMsg = 'This location will be removed from the pickup options until reopened. Continue?';
                        if (!confirm(confirmMsg)) return;
                    }

                    fetch('locations.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded'
                            },
                            body: 'action=toggle_status&location_id=' + encodeURIComponent(id) + '&status=' + encodeURIComponent(newStatus)
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                // update attribute and visible badge/button text without reloading
                                row.setAttribute('data-location-status', newStatus);
                                var badge = row.querySelector('.status-badge');
                                if (badge) {
                                    badge.textContent = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
                                    badge.classList.toggle('active', newStatus === 'open');
                                    badge.classList.toggle('inactive', newStatus !== 'open');
                                }
                                // update the menu button text to reflect next action
                                btn.textContent = 'Set ' + (newStatus === 'open' ? 'Closed' : 'Open');
                                // close dropdown if open
                                var dropdown = btn.closest('.dropdown-menu');
                                if (dropdown) dropdown.style.display = 'none';
                            } else {
                                alert(data.message || 'Failed to update status');
                            }
                        })
                        .catch(() => {
                            alert('Request failed');
                        });
                });
            });

            // Edit product modal logic
            const editModal = document.getElementById('editProductModal');
            const editForm = document.getElementById('editProductForm');
            const editGrandePrice = document.getElementById('editGrandePrice');
            
            const closeEditModalBtn = document.getElementById('closeEditProductModal');
            // Pastry modal elements
            const pastryModal = document.getElementById('editPastryModal');
            const closePastryModalBtn = document.getElementById('closeEditPastryModal');
            const pastryVariantsBody = document.getElementById('pastryVariantsBody');
            const savePastryBtn = document.getElementById('savePastryVariantsBtn');
            const addPastryRowBtn = document.getElementById('addPastryVariantRow');
            const pastryProductIdInput = document.getElementById('pastryProductId');
            const savePastryResult = document.getElementById('savePastryResult');

            if (editModal && editForm && closeEditModalBtn) {
                document.querySelectorAll('.edit-product-btn').forEach(function(btn) {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        // Close dropdown if open
                        var dd = btn.closest('.dropdown-menu');
                        if (dd) { dd.style.display = 'none'; }
                        var row = btn.closest('tr');
                        var pid = row ? row.getAttribute('data-product-id') : '';
                        if (!pid) { pid = btn.getAttribute('data-product-id') || ''; }
                        if (!row) {
                            // Attempt to locate the row by product id
                            row = pid ? document.querySelector(`tr[data-product-id="${CSS.escape(pid)}"]`) : null;
                        }
                        if (!row || !pid) { alert('Missing product id.'); return; }
                        const dtype = (row.getAttribute('data-product-type')||'').toLowerCase();
                        // For pastries, open the pastry variants modal instead of the regular edit
                        if (dtype === 'pastries' && pastryModal) {
                            pastryProductIdInput.value = pid;
                            // load existing variants
                            pastryVariantsBody.innerHTML = '<tr><td colspan="3" style="text-align:center;padding:10px;">Loading…</td></tr>';
                            fetch('AJAX/pastry_variants.php?action=list&product_id='+encodeURIComponent(pid))
                                .then(r=>r.json())
                                .then(js=>{
                                    const list = (js && js.variants && Array.isArray(js.variants)) ? js.variants : [];
                                    pastryVariantsBody.innerHTML = '';
                                    if (!list.length) {
                                        // default common rows
                                        addVariantRow('Per piece', '0.00');
                                        addVariantRow('Box of 4', '0.00');
                                        addVariantRow('Box of 6', '0.00');
                                    } else {
                                        list.forEach(v => addVariantRow(v.label, Number(v.price).toFixed(2)));
                                    }
                                })
                                .catch(()=>{ pastryVariantsBody.innerHTML = ''; addVariantRow('Per piece','0.00'); });
                            pastryModal.style.display = 'flex';
                            return;
                        }
                        document.getElementById('editProductId').value = pid;
                        document.getElementById('editProductName').value = row.getAttribute('data-product-name');
                        // Prefill grande price (if available)
                        const g = row.getAttribute('data-price-grande') || '';
                        if (editGrandePrice) editGrandePrice.value = g;
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
                    const id = formData.get('product_id');
                    const newName = formData.get('new_name');
                    // Compute a base price to keep the table's single Price column (use grande if provided)
                    const g = parseFloat((formData.get('new_grande_price')||'').toString());
                    const hasG = !isNaN(g);
                    const newPrice = (hasG ? g : 0).toFixed(2);
                    const hidden = document.getElementById('editHiddenBasePrice');
                    if (hidden) hidden.value = newPrice;
                    const newCat = formData.get('new_category');

                    fetch('update_product.php', {
                            method: 'POST',
                            body: new URLSearchParams(formData)
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (!data || data.success !== true) {
                                alert((data && data.message) ? data.message : 'Failed to update product');
                                return;
                            }
                            // Update row in-place only on success
                            const row = document.querySelector(`tr[data-product-id="${id}"]`);
                            if (row) {
                                row.setAttribute('data-product-name', newName);
                                row.setAttribute('data-product-price', newPrice);
                                row.setAttribute('data-product-category', newCat);
                                // Also update stored grande for next edits
                                if (hasG) row.setAttribute('data-price-grande', g.toFixed(2));
                                // cells: [Product(ID), Category, Price A, Price B, Price C, Stock, Status, Sales, Action]
                                const cells = row.querySelectorAll('td');
                                if (cells[1]) cells[1].textContent = newCat;
                                // Grande
                                if (cells[2] && hasG) cells[2].textContent = '₱' + g.toFixed(2);
                            }
                            editModal.style.display = 'none';
                        })
                        .catch(() => alert('Request failed'));
                };
            }

            // Helpers for pastry modal rows
            function addVariantRow(label = '', price = '') {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td><input type="text" class="pv-label" value="${label.replace(/"/g,'&quot;')}" style="width:100%;padding:8px;border:1px solid #e5e7eb;border-radius:8px"></td>
                    <td style="text-align:right;"><input type="number" step="0.01" class="pv-price" value="${price}" style="width:120px;padding:8px;border:1px solid #e5e7eb;border-radius:8px;text-align:right"></td>
                    <td style="text-align:center;"><button type="button" class="btn-secondary pv-remove">Remove</button></td>
                `;
                pastryVariantsBody.appendChild(tr);
            }

            if (addPastryRowBtn) {
                addPastryRowBtn.addEventListener('click', function(){ addVariantRow('', '0.00'); });
            }

            if (pastryVariantsBody) {
                pastryVariantsBody.addEventListener('click', function(e){
                    const btn = e.target.closest('.pv-remove');
                    if (!btn) return;
                    const tr = btn.closest('tr');
                    tr && tr.remove();
                });
            }

            if (savePastryBtn) {
                savePastryBtn.addEventListener('click', function(){
                    const pid = pastryProductIdInput.value;
                    const rows = Array.from(pastryVariantsBody.querySelectorAll('tr'));
                    const variants = rows.map(tr => {
                        const label = tr.querySelector('.pv-label')?.value?.trim() || '';
                        const price = parseFloat(tr.querySelector('.pv-price')?.value || '0');
                        return { label, price };
                    }).filter(v => v.label && !isNaN(v.price));

                    savePastryResult.textContent = '';
                    fetch('AJAX/pastry_variants.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({ action:'save', product_id: pid, variants: JSON.stringify(variants) })
                    }).then(r=>r.json()).then(js=>{
                        if (js && js.success) {
                            savePastryResult.style.color = '#059669';
                            savePastryResult.textContent = 'Saved variants successfully';
                            setTimeout(()=>{ savePastryResult.textContent=''; pastryModal.style.display='none'; }, 700);
                        } else {
                            savePastryResult.style.color = '#dc2626';
                            savePastryResult.textContent = (js && js.message) ? js.message : 'Failed to save variants';
                        }
                    }).catch(()=>{
                        savePastryResult.style.color = '#dc2626';
                        savePastryResult.textContent = 'Request failed';
                    });
                });
            }

            if (closePastryModalBtn && pastryModal) {
                closePastryModalBtn.addEventListener('click', ()=>{ pastryModal.style.display='none'; });
                pastryModal.addEventListener('click', (e)=>{ if (e.target === pastryModal) pastryModal.style.display='none'; });
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


            function applyToppingsDeleteVisibility(responseJson) {
                const isSuper = (responseJson && typeof responseJson.is_super !== 'undefined') ?
                    responseJson.is_super :
                    (window.IS_SUPER_ADMIN === true);

                if (!isSuper) {
                    // remove any hard-delete buttons (give them class "topping-delete" in server-rendered HTML)
                    document.querySelectorAll('.topping-delete').forEach(el => el.remove());
                }
            }

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
                    const formData = new FormData(addProductForm);
                    fetch('add_products.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(res => res.text())
                        .then(text => {
                            if (addProductResult) addProductResult.textContent = text;
                            if (text.toLowerCase().includes('success')) {
                                addProductModal.style.display = 'none';
                                addProductForm.reset();
                                // TODO: optionally append the new product row here without reload
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
                                addLocationModal.style.display = 'none';
                                addLocationForm.reset();
                                // TODO: optionally append the new location row here without reload
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
                    // Ensure expected field naming
                    if (!formData.has('location_id')) {
                        formData.append('location_id', formData.get('id') || document.getElementById('editLocationId').value);
                        formData.delete('id');
                    }
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

            if (window.IS_SUPER_ADMIN) {
                document.querySelectorAll('.delete-location-btn').forEach(function(btn) {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        if (!confirm('Are you sure you want to delete this location?')) return;
                        const row = btn.closest('tr');
                        const id = row && row.getAttribute('data-location-id');
                        if (!id) return;
                        fetch('locations.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded'
                                },
                                body: 'action=delete&location_id=' + encodeURIComponent(id)
                            })
                            .then(res => res.json())
                            .then(data => {
                                if (data.success) {
                                    if (row) row.remove(); // remove row in-place
                                } else {
                                    alert(data.message || 'Delete failed');
                                }
                            })
                            .catch(() => {
                                alert('Delete request failed');
                            });
                    });
                });
            }

            document.querySelectorAll('.toggle-location-status-btn').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopImmediatePropagation(); // <- ensure no other handler runs
                    var row = btn.closest('tr');
                    if (!row) return;
                    var id = row.getAttribute('data-location-id');
                    var currentStatus = (row.getAttribute('data-location-status') || '').toLowerCase();
                    var newStatus = currentStatus === 'open' ? 'closed' : 'open';
                    if (!id) return;

                    // Warn admin when closing a location
                    if (newStatus === 'closed') {
                        var confirmMsg = 'Closing this location will remove it from the pickup options until reopened. Continue?';
                        if (!confirm(confirmMsg)) return;
                    }

                    fetch('locations.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded'
                            },
                            body: 'action=toggle_status&location_id=' + encodeURIComponent(id) + '&status=' + encodeURIComponent(newStatus)
                        })
                        .then(res => res.json().catch(() => ({
                            success: false,
                            message: 'Invalid JSON from server'
                        })))
                        .then(data => {
                            if (data.success) {
                                row.setAttribute('data-location-status', newStatus);
                                var badge = row.querySelector('.status-badge');
                                if (badge) {
                                    badge.textContent = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
                                    badge.classList.toggle('active', newStatus === 'open');
                                    badge.classList.toggle('inactive', newStatus !== 'open');
                                }
                                btn.textContent = 'Set ' + (newStatus === 'open' ? 'Closed' : 'Open');
                                var dropdown = btn.closest('.dropdown-menu');
                                if (dropdown) dropdown.style.display = 'none';
                            } else {
                                alert(data.message || 'Failed to update status');
                            }
                        })
                        .catch(() => {
                            alert('Request failed');
                        });
                });
            });

            // Add Admin functionality
            var addAdminForm = document.getElementById('addAdminForm');
            var addAdminResult = document.getElementById('addAdminResult');

            // Small helper: toast fallback if showNotification isn't globally available
            function notify(msg){
                if (typeof showNotification === 'function') { try { showNotification(msg); return; } catch(e){} }
                // Fallback toast using existing .notification styles
                const n = document.createElement('div');
                n.className = 'notification';
                n.textContent = msg;
                document.body.appendChild(n);
                requestAnimationFrame(()=> n.classList.add('show'));
                setTimeout(()=>{ n.classList.remove('show'); setTimeout(()=> n.remove(), 300); }, 3000);
            }

            if (addAdminForm) {
                addAdminForm.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    if (addAdminResult) { addAdminResult.textContent = ''; addAdminResult.style.color = '#dc2626'; }
                    const formData = new FormData(addAdminForm);
                    try {
                        const res = await fetch('add_admin.php', { method: 'POST', body: formData, credentials: 'same-origin' });
                        let data;
                        try { data = await res.json(); }
                        catch { const txt = await res.text(); data = { success: /success/i.test(txt), message: txt }; }
                        if (data && data.success) {
                            notify('Admin added successfully');
                            addAdminForm.reset();
                            if (addAdminResult) addAdminResult.textContent = '';
                        } else {
                            const msg = (data && data.message) ? data.message : 'Failed to add admin.';
                            if (addAdminResult) addAdminResult.textContent = msg;
                            notify(msg);
                        }
                    } catch (err) {
                        if (addAdminResult) addAdminResult.textContent = 'Failed to add admin.';
                    }
                });
            }

            const liveOrdersTabs = document.querySelectorAll('#live-orders-tabs .tab');
            liveOrdersTabs.forEach(tab => {
                tab.addEventListener('click', function(e) {
                    e.preventDefault();
                    const status = this.getAttribute('data-status') || '';
                    // activate clicked tab
                    liveOrdersTabs.forEach(t => t.classList.remove('active'));
                    this.classList.add('active');
                    // show section and load orders
                    if (typeof showSection === 'function') showSection('live-orders');
                    if (typeof fetchOrders === 'function') fetchOrders(status);
                    // update URL without navigation
                    history.replaceState(null, '', location.pathname + '?status=' + encodeURIComponent(status));
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



            // Revenue Overview AJAX update
            function updateRevenueOverview() {
                fetch('AJAX/fetch_revenue_overview.php')
                    .then(res => res.json())
                    .then(data => {
                        if (data.success && data.data) {
                            const today = data.data.today || 0;
                            const week = data.data.week || 0;
                            const month = data.data.month || 0;
                            const year = data.data.year || 0;
                            document.getElementById('revenue-today').textContent = `₱${today.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}`;
                            document.getElementById('revenue-week').textContent = `₱${week.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}`;
                            document.getElementById('revenue-month').textContent = `₱${month.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}`;
                            if (document.getElementById('revenue-year')) {
                                document.getElementById('revenue-year').textContent = `₱${year.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}`;
                            }
                            // Bar widths (relative to month)
                            let max = Math.max(today, week, month, year, 1);
                            document.getElementById('bar-today').style.width = (today / max * 100) + '%';
                            document.getElementById('bar-week').style.width = (week / max * 100) + '%';
                            document.getElementById('bar-month').style.width = (month / max * 100) + '%';
                            if (document.getElementById('bar-year')) document.getElementById('bar-year').style.width = (year / max * 100) + '%';
                        }
                    });
            }
            updateRevenueOverview();
            setInterval(updateRevenueOverview, 15000);

            // Monthly Report logic
            const reportMonthInput = document.getElementById('report-month');
            const reportBtn = document.getElementById('btn-load-report');
            const reportLoading = document.getElementById('report-loading');
            const reportSummary = document.getElementById('report-summary');
            const reportDailyBody = document.getElementById('report-daily-body');
            const reportProductsBody = document.getElementById('report-products-body');
            const reportDailyPag = document.getElementById('report-daily-pagination');
            const reportProductsPag = document.getElementById('report-products-pagination');
            const REPORTS_ROWS_PER_PAGE = 15;

            function paginateTable(tbody, pagEl, perPage = REPORTS_ROWS_PER_PAGE) {
                if (!tbody || !pagEl) return;
                const rows = Array.from(tbody.querySelectorAll('tr'));
                const total = rows.length;
                const totalPages = Math.max(1, Math.ceil(total / perPage));

                function render(page) {
                    const current = Math.min(Math.max(1, page), totalPages);
                    const start = (current - 1) * perPage;
                    const end = start + perPage;
                    const set = new Set(rows.slice(start, end));
                    rows.forEach(tr => { tr.style.display = set.has(tr) ? '' : 'none'; });
                    // pager
                    pagEl.innerHTML = '';
                    if (totalPages <= 1) return;
                    const mk = (label, p, disabled=false, active=false) => {
                        const b = document.createElement('button');
                        b.type = 'button';
                        b.className = 'pager-btn' + (active ? ' active' : '');
                        b.textContent = label;
                        b.disabled = !!disabled;
                        b.dataset.page = String(p);
                        return b;
                    };
                    pagEl.appendChild(mk('Prev', Math.max(1, current-1), current===1));
                    for (let p=1;p<=totalPages;p++) pagEl.appendChild(mk(String(p), p, false, p===current));
                    pagEl.appendChild(mk('Next', Math.min(totalPages, current+1), current===totalPages));
                    pagEl.onclick = (ev) => {
                        const btn = ev.target.closest('button.pager-btn');
                        if (!btn) return;
                        const n = parseInt(btn.dataset.page||'1',10)||1;
                        render(n);
                    };
                }
                render(1);
            }
                const reportLocation = document.getElementById('report-location');
                const reportType = document.getElementById('report-type');

            function money(v){ return '₱' + Number(v||0).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2}); }

            function loadMonthlyReport() {
                if (!reportMonthInput) return;
                const m = reportMonthInput.value; // YYYY-MM
                    const loc = reportLocation ? reportLocation.value : '';
                    const typ = reportType ? reportType.value : '';
                reportLoading && (reportLoading.style.display = 'block');
                    const params = new URLSearchParams();
                    params.set('month', m);
                    if (loc) params.set('location', loc);
                    if (typ) params.set('type', typ);
                    fetch('AJAX/monthly_report.php?' + params.toString())
                    .then(r=>r.json())
                    .then(data => {
                        reportLoading && (reportLoading.style.display = 'none');
                        if (!data || !data.success) {
                            reportSummary.innerHTML = '<div style="color:#dc2626;font-weight:600;">Failed to load report</div>';
                            return;
                        }
                        const t = data.totals || {};
                        const cards = [
                            { icon:'💰', label:'Total Revenue', value: money(t.revenue||0) },
                            { icon:'📦', label:'Total Orders', value: (t.orders||0).toLocaleString() },
                            { icon:'🧁', label:'Items Sold', value: (t.items_sold||0).toLocaleString() },
                            { icon:'👥', label:'Distinct Customers', value: (t.distinct_customers||0).toLocaleString() }
                        ];
                        reportSummary.innerHTML = cards.map(c => `
                            <div class="report-stat-card">
                                <div class="report-stat-icon" aria-hidden="true">${c.icon}</div>
                                <div class="report-stat-meta">
                                    <span class="report-stat-label">${c.label}</span>
                                    <span class="report-stat-value">${c.value}</span>
                                </div>
                            </div>`).join('');

                        // Daily
                        const daily = Array.isArray(data.daily) ? data.daily : [];
                        if (!daily.length) {
                            reportDailyBody.innerHTML = '<tr><td colspan="4" class="empty-row">No daily data</td></tr>';
                        } else {
                            reportDailyBody.innerHTML = daily.map(d => {
                                return `<tr>
                                    <td>${d.d}</td>
                                    <td class="text-right">${d.orders}</td>
                                    <td class="text-right">${d.items}</td>
                                    <td class="text-right">${money(d.revenue)}</td>
                                </tr>`;
                            }).join('');
                            paginateTable(reportDailyBody, reportDailyPag, REPORTS_ROWS_PER_PAGE);
                        }

                        // Products
                        const prods = Array.isArray(data.products) ? data.products : [];
                        if (!prods.length) {
                            reportProductsBody.innerHTML = '<tr><td colspan="2" class="empty-row">No products sold</td></tr>';
                        } else {
                            reportProductsBody.innerHTML = prods.map(p => `<tr>
                                <td>${p.name}</td>
                                <td class="text-right">${p.qty}</td>
                            </tr>`).join('');
                            paginateTable(reportProductsBody, reportProductsPag, REPORTS_ROWS_PER_PAGE);
                        }
                    })
                    .catch(err => {
                        reportLoading && (reportLoading.style.display = 'none');
                        console.error('monthly report error', err);
                        reportSummary.innerHTML = '<div style="color:#dc2626;font-weight:600;">Error loading report</div>';
                    });
            }

            if (reportBtn) reportBtn.addEventListener('click', () => loadMonthlyReport());

            // Auto-load the current month when navigating to reports section
            const navReports = document.querySelector('.nav-item[data-section="reports"]');
            if (navReports) {
                navReports.addEventListener('click', () => {
                    // Delay to allow section to show
                    setTimeout(() => loadMonthlyReport(), 50);
                });
            }

            // Active locations now server-rendered; obsolete dynamic code removed

            // Inventory (Stocks) logic
            const stocksBody = document.getElementById('stocks-body');
            const stocksRefresh = document.getElementById('stocks-refresh');
            const stocksSaving = document.getElementById('stocks-saving');
            const stocksError = document.getElementById('stocks-error');

            function loadStocks() {
                if (!stocksBody) return;
                stocksBody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:12px;">Loading…</td></tr>';
                fetch('AJAX/inventory.php?action=list')
                    .then(r=>r.json())
                    .then(js => {
                        if (!js || !js.success) {
                            stocksBody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:12px;color:#dc2626;">Failed to load</td></tr>';
                            return;
                        }
                        const rows = js.items.map(it => {
                            const statusBadge = `<span style=\"display:inline-block;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600;color:${it.status==='active'?'#065f46':'#92400e'};background:${it.status==='active'?'#d1fae5':'#fde68a'};\">${it.status}</span>`;
                            return `<tr data-product-id=\"${it.product_id}\">
                                <td style=\"padding:6px 8px;font-size:0.85rem;\">${it.name}</td>
                                <td style=\"padding:6px 8px;font-size:0.85rem;\">${statusBadge}</td>
                                <td style=\"padding:6px 8px;font-size:0.85rem;text-align:right;\">
                                    <input type=\"number\" min=\"0\" value=\"${it.quantity}\" class=\"stock-input\" style=\"width:90px;padding:4px 6px;border:1px solid #ccc;border-radius:6px;text-align:right;\" />
                                </td>
                                <td style=\"padding:6px 8px;font-size:0.85rem;text-align:center;\">
                                    <button class=\"btn-primary btn-update-stock\" style=\"padding:6px 10px;font-size:12px;\">Save</button>
                                </td>
                            </tr>`;
                        }).join('');
                        stocksBody.innerHTML = rows || '<tr><td colspan="4" style="text-align:center;padding:12px;">No products</td></tr>';
                    })
                    .catch(err => {
                        console.error('inventory list error', err);
                        stocksBody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:12px;color:#dc2626;">Error</td></tr>';
                    });
            }

            function saveStock(row) {
                const pid = row.getAttribute('data-product-id');
                const input = row.querySelector('.stock-input');
                if (!pid || !input) return;
                const qty = parseInt(input.value,10); if (isNaN(qty) || qty < 0) { alert('Invalid quantity'); return; }
                stocksSaving && (stocksSaving.style.display = 'block');
                stocksError && (stocksError.style.display = 'none');
                fetch('AJAX/inventory.php', {
                    method:'POST',
                    headers:{'Content-Type':'application/x-www-form-urlencoded'},
                    body:'action=update&product_id=' + encodeURIComponent(pid) + '&quantity=' + encodeURIComponent(qty)
                }).then(r=>r.json()).then(js => {
                    stocksSaving && (stocksSaving.style.display = 'none');
                    if (!js || !js.success) {
                        stocksError && (stocksError.style.display = 'block');
                        setTimeout(()=>{ stocksError.style.display='none'; }, 2500);
                        return;
                    }
                    // brief visual cue
                    row.style.background = '#ecfdf5';
                    setTimeout(()=>{ row.style.background=''; }, 600);
                }).catch(err => {
                    console.error('inventory update error', err);
                    stocksSaving && (stocksSaving.style.display = 'none');
                    stocksError && (stocksError.style.display = 'block');
                    setTimeout(()=>{ stocksError.style.display='none'; }, 2500);
                });
            }

            if (stocksRefresh) stocksRefresh.addEventListener('click', loadStocks);
            if (stocksBody) {
                stocksBody.addEventListener('click', e => {
                    const btn = e.target.closest('.btn-update-stock');
                    if (!btn) return;
                    const row = btn.closest('tr');
                    row && saveStock(row);
                });
            }

            const navStocks = document.querySelector('.nav-item[data-section="stocks"]');
            if (navStocks) {
                navStocks.addEventListener('click', () => setTimeout(loadStocks, 60));
            }
        });
    </script>
    <script src="js/main.js?v=20251018"></script>
        <script>
            // Minimal receipt modal wiring (kept separate to avoid coupling to main.js build)
            (function(){
                const modal = document.getElementById('receiptModal');
                const img = document.getElementById('receiptImg');
                const closeBtn = document.getElementById('receiptCloseBtn');
                function open(url){ if (!modal||!img) return; img.src = url; modal.style.display='flex'; }
                function close(){ if (!modal||!img) return; img.src=''; modal.style.display='none'; }
                window.__openReceipt = open; window.__closeReceipt = close;
                if (closeBtn) closeBtn.addEventListener('click', close);
                if (modal) modal.addEventListener('click', function(e){ if (e.target && e.target.getAttribute('data-close')==='backdrop') close(); });
            })();
        </script>
        <!-- Price History Modal -->
        <div id="priceHistoryModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:6000;align-items:center;justify-content:center;">
            <div style="background:#fff;padding:16px;border-radius:12px;max-width:800px;width:92vw;max-height:85vh;display:flex;flex-direction:column;gap:10px;">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <strong>Price history</strong>
                    <button id="phCloseBtn" style="background:#111827;color:#fff;border:none;border-radius:6px;padding:6px 10px;cursor:pointer;">Close</button>
                </div>
                <div id="phBody" style="overflow:auto;border:1px solid #e5e7eb;border-radius:8px;">
                    <div style="padding:12px;color:#6b7280;">Loading…</div>
                </div>
            </div>
            <div data-close="ph-backdrop" style="position:absolute;inset:0;"></div>
        </div>
        <script>
            (function(){
                const modal = document.getElementById('priceHistoryModal');
                const body = document.getElementById('phBody');
                const closeBtn = document.getElementById('phCloseBtn');
                function open(){ if (!modal) return; modal.style.display='flex'; }
                function close(){ if (!modal) return; modal.style.display='none'; if (body) body.innerHTML=''; }
                window.__openPriceHistory = function(html){ if (body) { body.innerHTML = html; } open(); };
                window.__closePriceHistory = close;
                if (closeBtn) closeBtn.addEventListener('click', close);
                if (modal) modal.addEventListener('click', function(e){ if (e.target && e.target.getAttribute('data-close')==='ph-backdrop') close(); });
            })();
        </script>
    <script>
        // expose super-admin flag to admin UI JS (used to show force-delete)
        window.IS_SUPER_ADMIN = <?php echo Database::isSuperAdmin() ? 'true' : 'false'; ?>;
    </script>
    <script src="https://www.gstatic.com/firebasejs/10.12.0/firebase-app-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/10.12.0/firebase-messaging-compat.js"></script>

    <script>
        (async function initAdminPush() {
            if (!('Notification' in window)) {
                console.warn('[FCM] Notifications not supported');
                return;
            }

            const perm = await Notification.requestPermission();
            if (perm !== 'granted') {
                console.warn('[FCM] Permission denied');
                return;
            }

            const config = {
                apiKey: "AIzaSyBrrSPfXUSCvL4ZHx4P8maqBcGjMAzTk8k",
                authDomain: "coffeeshop-8ce2a.firebaseapp.com",
                projectId: "coffeeshop-8ce2a",
                storageBucket: "coffeeshop-8ce2a.appspot.com",
                messagingSenderId: "398338296558",
                appId: "1:398338296558:web:8c44c2b36eccad9fbdc1ff",
            };

            if (!firebase.apps.length) firebase.initializeApp(config);

            const messaging = firebase.messaging();
            // Register service worker from site root so scope covers entire application
            const swPath = (location.pathname.includes('/cupscuddles/')) ?
                '/cupscuddles/firebase-messaging-sw.js' :
                '/firebase-messaging-sw.js';
            const swReg = await navigator.serviceWorker.register(swPath);
            const vapidKey = "BBD435Y3Qib-8dPJ_-eEs2ScDyXZ2WhWzFzS9lmuKv_xQ4LSPcDnZZVqS7FHBtinlM_tNNQYsocQMXCptrchO68";

            async function registerToken(force = false) {
                try {
                    const token = await messaging.getToken({
                        vapidKey,
                        serviceWorkerRegistration: swReg
                    });

                    if (!token) {
                        console.warn('[FCM] No token retrieved');
                        return;
                    }

                    if (!force && localStorage.getItem('last_fcm_token') === token) return;

                    const res = await fetch('saveAdminFcmToken.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            token
                        })
                    });

                    const js = await res.json();
                    console.log('[FCM] register response', js);

                    if (js.success) {
                        localStorage.setItem('last_fcm_token', token);
                    }
                } catch (err) {
                    console.error('[FCM] getToken error', err);
                }
            }
            messaging.onMessage(payload => {
                console.log('[FCM] foreground payload:', payload);
                const d = payload.data || {};
                const n = payload.notification || {};
                const title = d.title || n.title || 'Notification';
                const body = d.body || n.body || '';
                const icon = d.icon || n.icon || '/img/kape.png';
                const image = d.image || n.image || '/img/logo.png';

                // Slide-in bar
                const bar = document.createElement('div');
                bar.style.cssText = 'position:fixed;top:12px;right:12px;z-index:99999;background:#059669;color:#fff;' +
                    'padding:12px 16px;border-radius:8px;font-size:14px;box-shadow:0 4px 12px rgba(0,0,0,.18);cursor:pointer;';
                bar.textContent = title + (body ? (' - ' + body) : '');
                bar.onclick = () => {
                    document.querySelector('.nav-item[data-section="order-history"]')?.click();
                    window.focus();
                };
                document.body.appendChild(bar);
                setTimeout(() => bar.remove(), 6000);

                // OS-level notification (include body/icon/image)
                if (Notification.permission === 'granted') {
                    try {
                        new Notification(title, {
                            body,
                            icon,
                            image,
                            badge: icon,
                            data: d,
                            requireInteraction: false
                        });
                    } catch (e) {
                        console.warn('[FCM] Notification error', e);
                    }
                }
            });

            await registerToken(true);
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible') registerToken();
            });
        })();
    </script>


    <script>
        (function() {
            const tbody = document.getElementById('pickedup-orders-tbody');
            const pager = document.getElementById('pickedup-pagination');
            const sortSelect = document.getElementById('pickedup-sort');
            if (!tbody || !pager) return;

            let pickedUpPage = 1;
            const pageSize = 10;

            function money(v) {
                return Number(v || 0).toFixed(2);
            }

            function renderRows(list) {
                tbody.innerHTML = '';
                if (!list.length) {
                    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:12px;">No picked up orders.</td></tr>';
                    return;
                }
                const esc = (s) => String(s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[c]));
                list.forEach(o => {
                    const items = Array.isArray(o.items) ? o.items : [];
                    const itemsHtml = items.length
                        ? `<ul class="oh-items-list">${items.map(i => `<li>${esc(`${i.quantity}x ${i.name}${i.size?(' ('+i.size+')'):''}`)}</li>`).join('')}</ul>`
                        : '-';
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                <td style="padding:6px;">${o.reference_number}</td>
                <td style="padding:6px;">${o.customer_name}</td>
                <td style="padding:6px;">${itemsHtml}</td>
                <td style="padding:6px;">${money(o.total_amount)}</td>
                <td style="padding:6px;text-transform:capitalize;">${o.status}</td>
                <td style="padding:6px;">${new Date(o.created_at).toLocaleString()}</td>`;
                    tbody.appendChild(tr);
                });
            }

            function button(page, label, disabled = false, active = false) {
                const b = document.createElement('button');
                b.textContent = label;
                b.disabled = disabled;
                b.style.cssText = `padding:6px 10px;border:1px solid #059669;border-radius:6px;
            background:${active?'#059669':'#fff'};color:${active?'#fff':'#059669'};
            cursor:${disabled?'not-allowed':'pointer'};font-size:12px;`;
                if (!disabled && !active) b.addEventListener('click', () => load(page));
                return b;
            }

            function renderPager(meta) {
                pager.innerHTML = '';
                if (meta.totalPages <= 1) return;
                if (pickedUpPage > 1) pager.appendChild(button(pickedUpPage - 1, '«'));
                const max = 7;
                let start = Math.max(1, pickedUpPage - 3);
                let end = Math.min(meta.totalPages, start + max - 1);
                if (end - start + 1 < max) start = Math.max(1, end - max + 1);
                if (start > 1) {
                    pager.appendChild(button(1, '1'));
                    if (start > 2) {
                        const span = document.createElement('span');
                        span.textContent = '...';
                        span.style.cssText = 'padding:6px 4px;font-size:12px;';
                        pager.appendChild(span);
                    }
                }
                for (let p = start; p <= end; p++) pager.appendChild(button(p, String(p), false, p === pickedUpPage));
                if (end < meta.totalPages) {
                    if (end < meta.totalPages - 1) {
                        const span = document.createElement('span');
                        span.textContent = '...';
                        span.style.cssText = 'padding:6px 4px;font-size:12px;';
                        pager.appendChild(span);
                    }
                    pager.appendChild(button(meta.totalPages, String(meta.totalPages)));
                }
                if (pickedUpPage < meta.totalPages) pager.appendChild(button(pickedUpPage + 1, '»'));
            }

            function load(page = 1) {
                pickedUpPage = page;
                const sort = sortSelect ? sortSelect.value : 'created_desc';
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:12px;">Loading…</td></tr>';
                fetch(`AJAX/fetch_pickedup_orders_page.php?page=${page}&pageSize=${pageSize}&sort=${encodeURIComponent(sort)}`, {
                        cache: 'no-store'
                    })
                    .then(r => r.json())
                    .then(d => {
                        if (!d.success) throw 0;
                        renderRows(d.orders || []);
                        renderPager(d);
                    })
                    .catch(() => {
                        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#b91c1c;padding:12px;">Failed to load.</td></tr>';
                        pager.innerHTML = '';
                    });
            }

            if (sortSelect) sortSelect.addEventListener('change', () => load(1));

            const observer = new MutationObserver(() => {
                const sec = document.getElementById('order-history-section');
                if (sec && sec.classList.contains('active') && !tbody.dataset.loaded) {
                    tbody.dataset.loaded = '1';
                    load(1);
                }
            });
            observer.observe(document.body, {
                subtree: true,
                attributes: true,
                attributeFilter: ['class']
            });

            const prev = window.forceDashRefresh;
            window.forceDashRefresh = function() {
                if (typeof prev === 'function') prev();
                if (document.getElementById('order-history-section')?.classList.contains('active')) {
                    load(pickedUpPage);
                }
            };

            if (document.getElementById('order-history-section')?.classList.contains('active')) {
                tbody.dataset.loaded = '1';
                load(1);
            }
        })();
    </script>
</body>

</html>