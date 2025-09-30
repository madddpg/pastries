<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../database/db_connect.php';

session_start();
// Basic admin access guard
if (!(Database::isAdmin() || Database::isSuperAdmin() || (isset($_SESSION['admin_id']) && $_SESSION['admin_id']))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

try {
    $db = new Database();
    $con = $db->opencon();

    $monthParam = isset($_GET['month']) ? trim($_GET['month']) : '';
    // Expect format YYYY-MM; default to current month
    if (!preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
        $monthParam = date('Y-m');
    }

    [$year, $month] = array_map('intval', explode('-', $monthParam));
    if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
        $year = (int)date('Y');
        $month = (int)date('m');
        $monthParam = date('Y-m');
    }

    $start = sprintf('%04d-%02d-01', $year, $month);
    // Compute end-of-month by adding 1 month and subtracting 1 day
    $startDt = new DateTime($start, new DateTimeZone('Asia/Manila'));
    $endDt = clone $startDt;
    $endDt->modify('first day of next month')->modify('-1 day');
    $end = $endDt->format('Y-m-d');

    // Overall totals (exclude pending / cancelled like revenue overview)
    $stTotals = $con->prepare("SELECT COUNT(*) orders, COALESCE(SUM(total_amount),0) revenue, COUNT(DISTINCT user_id) customers
                               FROM transaction
                               WHERE DATE(created_at) BETWEEN ? AND ?
                                 AND status NOT IN ('pending','cancelled')");
    $stTotals->execute([$start, $end]);
    $totRow = $stTotals->fetch(PDO::FETCH_ASSOC) ?: ['orders'=>0,'revenue'=>0,'customers'=>0];
    $totalOrders = (int)$totRow['orders'];
    $totalRevenue = (float)$totRow['revenue'];
    $distinctCustomers = (int)$totRow['customers'];

    // Total items sold & product breakdown
    $stProducts = $con->prepare("SELECT ti.product_id, p.name, SUM(ti.quantity) qty,
                                        SUM(ti.quantity * ti.price) gross
                                 FROM transaction_items ti
                                 JOIN transaction t ON ti.transaction_id = t.transac_id
                                 JOIN products p ON ti.product_id = p.product_id
                                 WHERE DATE(t.created_at) BETWEEN ? AND ?
                                   AND t.status NOT IN ('pending','cancelled')
                                 GROUP BY ti.product_id, p.name
                                 ORDER BY qty DESC, gross DESC");
    $stProducts->execute([$start, $end]);
    $products = $stProducts->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $totalItems = 0;
    foreach ($products as &$pr) {
        $pr['qty'] = (int)$pr['qty'];
        $pr['gross'] = (float)$pr['gross'];
        $totalItems += $pr['qty'];
    }
    unset($pr);

    // Add percentage share to each product
    foreach ($products as &$pr) {
        $pr['share_pct'] = ($totalRevenue > 0) ? round(($pr['gross'] / $totalRevenue) * 100, 2) : 0.0;
    }
    unset($pr);

    // Daily breakdown (orders + revenue + items)
    $stDaily = $con->prepare("SELECT DATE(t.created_at) d,
                                     COUNT(*) orders,
                                     COALESCE(SUM(t.total_amount),0) revenue,
                                     COALESCE(SUM(itm_qty.total_qty),0) items
                              FROM transaction t
                              LEFT JOIN (
                                  SELECT ti.transaction_id, SUM(ti.quantity) total_qty
                                  FROM transaction_items ti
                                  GROUP BY ti.transaction_id
                              ) itm_qty ON itm_qty.transaction_id = t.transac_id
                              WHERE DATE(t.created_at) BETWEEN ? AND ?
                                AND t.status NOT IN ('pending','cancelled')
                              GROUP BY DATE(t.created_at)
                              ORDER BY d ASC");
    $stDaily->execute([$start, $end]);
    $daily = $stDaily->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($daily as &$drow) {
        $drow['orders'] = (int)$drow['orders'];
        $drow['revenue'] = (float)$drow['revenue'];
        $drow['items'] = (int)$drow['items'];
    }
    unset($drow);

    $avgOrderValue = $totalOrders > 0 ? ($totalRevenue / $totalOrders) : 0.0;

    echo json_encode([
        'success' => true,
        'meta' => [
            'month' => $monthParam,
            'start' => $start,
            'end' => $end
        ],
        'totals' => [
            'orders' => $totalOrders,
            'revenue' => $totalRevenue,
            'avg_order_value' => $avgOrderValue,
            'distinct_customers' => $distinctCustomers,
            'items_sold' => $totalItems
        ],
        'daily' => $daily,
        'products' => $products
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error', 'error' => $e->getMessage()]);
}
