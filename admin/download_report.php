<?php
// download_report.php
// Streams a CSV to the admin. By default, matches the Reports tables (Daily Breakdown + Products Sold)
// to keep CSV consistent with what you see in the UI.
// GET filters:
//   view=both|daily|products|transactions (default: both)
//   month=YYYY-MM (recommended; aligns with Reports). Optionally: from=YYYY-MM-DD, to=YYYY-MM-DD
//   location=<exact name>
//   type=hot|cold|pastries (product data_type)
// For view=transactions only:
//   status=pending|preparing|ready|picked up|cancelled
//   columns=comma,separated,list (optional) – see $defaultColumns keys below


ini_set('display_errors', '0');
session_start();

require_once __DIR__ . '/database/db_connect.php';

// Allow only logged-in admins
if (empty($_SESSION['admin_id'])) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$db = new Database();
$con = $db->opencon();

// Input filters
$view    = isset($_GET['view']) ? strtolower(trim($_GET['view'])) : 'both';
$month   = isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month']) ? $_GET['month'] : '';
$from    = isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from']) ? $_GET['from'] : null;
$to      = isset($_GET['to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'])   ? $_GET['to']   : null;
$status  = isset($_GET['status']) ? trim(strtolower($_GET['status'])) : '';
$type    = isset($_GET['type']) ? trim(strtolower($_GET['type'])) : ''; // product data_type
$location = isset($_GET['location']) ? trim($_GET['location']) : '';

$allowedStatuses = ['pending','preparing','ready','picked up','cancelled'];
$allowedTypes = ['hot','cold','pastries'];
if (!in_array($type, $allowedTypes, true)) { $type = ''; }

// Column selection (optional); default order below
$defaultColumns = [
    'reference_number'   => 'Reference Number',
    'created_at'         => 'Created At',
    'customer_name'      => 'Customer',
    'pickup_location'    => 'Pickup Location',
    'pickup_time'        => 'Pickup Time',
    'status'             => 'Status',
    'payment_method'     => 'Payment Method',
    'product_name'       => 'Product',
    'size'               => 'Size/Variant',
    'quantity'           => 'Qty',
    'item_price'         => 'Item Price',
    'line_total'         => 'Line Total',
    'transaction_total'  => 'Order Total'
];

$columnsParam = isset($_GET['columns']) ? trim($_GET['columns']) : '';
$selectedColumns = $defaultColumns; // used only for transactions view
if ($columnsParam !== '') {
    $keys = array_map('trim', explode(',', $columnsParam));
    $filtered = [];
    foreach ($keys as $k) {
        if ($k !== '' && isset($defaultColumns[$k])) {
            $filtered[$k] = $defaultColumns[$k];
        }
    }
    if (!empty($filtered)) $selectedColumns = $filtered;
}

// Prepare HTTP headers early
$filename = 'report_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename=' . $filename);
header('Pragma: no-cache');
header('Expires: 0');
// Optional BOM for Excel UTF-8
echo "\xEF\xBB\xBF";
// CSV stream
$out = fopen('php://output', 'w');

// If not explicitly requesting transactions, generate the aggregated report that matches the UI
if ($view !== 'transactions') {
    // Determine date range from month, or fallback to provided from/to
    if ($month !== '') {
        [$y, $m] = array_map('intval', explode('-', $month));
        $start = sprintf('%04d-%02d-01', $y, $m);
        $startDt = new DateTime($start, new DateTimeZone('Asia/Manila'));
        $endDt = clone $startDt;
        $endDt->modify('first day of next month')->modify('-1 day');
        $end = $endDt->format('Y-m-d');
    } else {
        // Fallback: use from/to or default to current month
        if (!$from || !$to) {
            $month = date('Y-m');
            [$y, $m] = array_map('intval', explode('-', $month));
            $start = sprintf('%04d-%02d-01', $y, $m);
            $startDt = new DateTime($start, new DateTimeZone('Asia/Manila'));
            $endDt = clone $startDt;
            $endDt->modify('first day of next month')->modify('-1 day');
            $end = $endDt->format('Y-m-d');
        } else {
            $start = $from; $end = $to;
        }
    }

    // Build filters to mirror monthly_report.php
    $locJoin = '';
    $cond = ' WHERE DATE(t.created_at) BETWEEN ? AND ? AND t.status NOT IN (\'pending\',\'cancelled\')';
    $baseParams = [$start, $end];
    if ($location !== '') { $locJoin = ' LEFT JOIN pickup_detail pd ON t.transac_id = pd.transaction_id '; $cond .= ' AND pd.pickup_location = ?'; $baseParams[] = $location; }

    // Daily breakdown
    if ($type !== '') {
        $dailySql = "SELECT DATE(t.created_at) d, COUNT(DISTINCT t.transac_id) orders, COALESCE(SUM(ti.quantity * ti.price),0) revenue, COALESCE(SUM(ti.quantity),0) items
                     FROM `transaction` t
                     JOIN transaction_items ti ON ti.transaction_id = t.transac_id
                     JOIN products p ON ti.product_id = p.product_id
                     $locJoin
                     $cond AND LOWER(p.data_type) = ?
                     GROUP BY DATE(t.created_at) ORDER BY d ASC";
        $dailyParams = $baseParams;
        $dailyParams[] = $type;
    } else {
        $dailySql = "SELECT DATE(t.created_at) d,
                            COUNT(*) orders,
                            COALESCE(SUM(t.total_amount),0) revenue,
                            COALESCE(SUM(itm_qty.total_qty),0) items
                     FROM `transaction` t
                     LEFT JOIN (
                         SELECT ti.transaction_id, SUM(ti.quantity) total_qty
                         FROM transaction_items ti
                         GROUP BY ti.transaction_id
                     ) itm_qty ON itm_qty.transaction_id = t.transac_id
                     $locJoin
                     $cond
                     GROUP BY DATE(t.created_at) ORDER BY d ASC";
        $dailyParams = $baseParams;
    }
    $stDaily = $con->prepare($dailySql);
    $stDaily->execute($dailyParams);
    $daily = $stDaily->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Products sold
    $prodSql = "SELECT ti.product_id, p.name, SUM(ti.quantity) qty, SUM(ti.quantity * ti.price) gross
                FROM transaction_items ti
                JOIN `transaction` t ON ti.transaction_id = t.transac_id
                JOIN products p ON ti.product_id = p.product_id
                $locJoin
                $cond";
    $prodParams = $baseParams;
    if ($type !== '') { $prodSql .= " AND LOWER(p.data_type) = ?"; $prodParams[] = $type; }
    $prodSql .= " GROUP BY ti.product_id, p.name ORDER BY qty DESC, gross DESC";
    $stProd = $con->prepare($prodSql);
    $stProd->execute($prodParams);
    $products = $stProd->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Output: Daily Breakdown section
    if ($view === 'both' || $view === 'daily') {
        fputcsv($out, ['Daily Breakdown']);
        fputcsv($out, ['Date', 'No. of Orders', 'No. of Items Sold', 'Revenue (PHP)']);
        foreach ($daily as $d) {
            fputcsv($out, [
                (string)$d['d'],
                (int)($d['orders'] ?? 0),
                (int)($d['items'] ?? 0),
                number_format((float)($d['revenue'] ?? 0), 2, '.', ''),
            ]);
        }
        fputcsv($out, []); // blank line between sections
    }

    // Output: Products Sold section
    if ($view === 'both' || $view === 'products') {
        $title = $month ? 'Products Sold (' . $month . ')' : 'Products Sold';
        fputcsv($out, [$title]);
        fputcsv($out, ['Product', 'Qty']);
        foreach ($products as $p) {
            fputcsv($out, [ (string)($p['name'] ?? ''), (int)($p['qty'] ?? 0) ]);
        }
    }

    fclose($out);
    exit;
}

// --- view=transactions (legacy/raw export) ---
// Build WHERE
$where = [];
$params = [];
if ($from) { $where[] = 'DATE(t.created_at) >= ?'; $params[] = $from; }
if ($to)   { $where[] = 'DATE(t.created_at) <= ?'; $params[] = $to; }
if ($status !== '' && in_array($status, $allowedStatuses, true)) { $where[] = 'LOWER(t.status) = ?'; $params[] = $status; }
if ($location !== '') { $where[] = 'p.pickup_location LIKE ?'; $params[] = $location . '%'; }
if ($type !== '') { $where[] = 'LOWER(COALESCE(pr.data_type, pr2.data_type)) = ?'; $params[] = $type; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Query rows, 1 row per line item
$sql = "SELECT
            t.transac_id,
            COALESCE(t.reference_number, t.transac_id) AS reference_number,
            t.created_at,
            t.status,
            COALESCE(t.payment_method, 'cash') AS payment_method,
            u.user_FN,
            u.user_LN,
            p.pickup_name,
            p.pickup_location,
            p.pickup_time,
            p.special_instructions,
            ti.quantity,
            ti.size,
            ti.price AS item_price,
            (ti.quantity * ti.price) AS line_total,
            t.total_amount AS transaction_total,
            COALESCE(pr.name, pr2.name) AS product_name
        FROM `transaction` t
        LEFT JOIN users u ON t.user_id = u.user_id
        LEFT JOIN pickup_detail p ON t.transac_id = p.transaction_id
        JOIN transaction_items ti ON ti.transaction_id = t.transac_id
        LEFT JOIN products pr  ON ti.products_pk IS NOT NULL AND pr.products_pk  = ti.products_pk
        LEFT JOIN products pr2 ON ti.products_pk IS NULL   AND pr2.product_id    = ti.product_id
        $whereSql
        ORDER BY t.created_at DESC, t.transac_id ASC, ti.size ASC";

$stmt = $con->prepare($sql);
$stmt->execute($params);

// Header row
fputcsv($out, array_values($selectedColumns));

// Stream rows
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    // Derive customer_name: prefer pickup_name else Users FN+LN else 'Guest'
    $customer = '';
    if (!empty($row['pickup_name'])) {
        $customer = $row['pickup_name'];
    } else {
        $fn = trim((string)($row['user_FN'] ?? ''));
        $ln = trim((string)($row['user_LN'] ?? ''));
        $customer = trim($fn . ' ' . $ln);
    }
    if ($customer === '') $customer = 'Guest';

    $record = [
        'reference_number'  => (string)$row['reference_number'],
        'created_at'        => (string)$row['created_at'],
        'customer_name'     => $customer,
        'pickup_location'   => (string)($row['pickup_location'] ?? ''),
        'pickup_time'       => (string)($row['pickup_time'] ?? ''),
        'status'            => (string)$row['status'],
        'payment_method'    => ucfirst((string)$row['payment_method']),
        'product_name'      => (string)($row['product_name'] ?? ''),
        'size'              => (string)($row['size'] ?? ''),
        'quantity'          => (int)($row['quantity'] ?? 0),
        'item_price'        => number_format((float)($row['item_price'] ?? 0), 2, '.', ''),
        'line_total'        => number_format((float)($row['line_total'] ?? 0), 2, '.', ''),
        'transaction_total' => number_format((float)($row['transaction_total'] ?? 0), 2, '.', ''),
    ];

    // Order by selected columns
    $ordered = [];
    foreach ($selectedColumns as $key => $_label) {
        $ordered[] = isset($record[$key]) ? $record[$key] : '';
    }
    fputcsv($out, $ordered);
}

fclose($out);
exit;
