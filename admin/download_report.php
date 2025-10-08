<?php
// download_report.php
// Streams a CSV of orders (with line items) to the admin.
// Uses the existing Database connection (db_connect.php). Optional filters via GET:
//   from=YYYY-MM-DD, to=YYYY-MM-DD, status=pending|preparing|ready|picked up|cancelled, location=<prefix>, type=hot|cold|pastries
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
$from    = isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from']) ? $_GET['from'] : null;
$to      = isset($_GET['to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'])   ? $_GET['to']   : null;
$status  = isset($_GET['status']) ? trim(strtolower($_GET['status'])) : '';
$type    = isset($_GET['type']) ? trim(strtolower($_GET['type'])) : ''; // product data_type
$location = isset($_GET['location']) ? trim($_GET['location']) : '';

$allowedStatuses = ['pending','preparing','ready','picked up','cancelled'];

// Column selection (optional); default order below
$defaultColumns = [
    'reference_number'   => 'Reference Number',
    'created_at'         => 'Created At',
    'customer_name'      => 'Customer',
    'pickup_location'    => 'Pickup Location',
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
$selectedColumns = $defaultColumns; // header=>label mapping
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
            DAY(t.created_at) AS created_at,
            t.status,
            COALESCE(t.payment_method, 'cash') AS payment_method,
            u.user_FN,
            u.user_LN,
            p.pickup_name,
            p.pickup_location,
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

// Prepare HTTP headers
$filename = 'orders_report_' . date('Y/m/d') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename=' . $filename);
header('Pragma: no-cache');
header('Expires: 0');

// Optional BOM for Excel UTF-8
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

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
