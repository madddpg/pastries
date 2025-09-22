
<?php
ini_set('display_errors', '0');
require_once __DIR__ . '/../database/db_connect.php';
header('Content-Type: text/html; charset=UTF-8');

$status  = isset($_GET['status']) ? trim($_GET['status']) : '';
$location = isset($_GET['location']) ? trim($_GET['location']) : '';
$allowed = ['pending', 'preparing', 'ready'];
$params  = [];
$where   = "WHERE t.status IN ('pending','preparing','ready')";
if ($status !== '' && in_array($status, $allowed, true)) {
    $where = "WHERE t.status = ?";
    $params[] = $status;
}

// Optional location filter (prefix match to handle stored phone suffixes)
if ($location !== '') {
    // Ensure pickup_detail is joined; it's already LEFT JOINed below
    $where .= ($params ? " AND" : " WHERE") . " p.pickup_location LIKE ?";
    $params[] = $location . '%';
}

try {
    $db  = new Database();
    $con = $db->opencon();

    $orders = [];
    try {
        // Full dataset (safe aliases and backticks)
                $sql = "SELECT
                  t.transac_id,
                  t.transac_id AS reference_number,
                  t.user_id,
                  t.total_amount,
                  t.status,
                  t.created_at,
                  t.payment_method,
                  u.user_FN AS customer_name,
                                    p.pickup_location,
                  p.pickup_time,
                  p.special_instructions
                FROM `transaction` t
                LEFT JOIN users u ON t.user_id = u.user_id
                LEFT JOIN pickup_detail p ON t.transac_id = p.transaction_id
                $where
                ORDER BY t.created_at DESC";
    $stmt = $con->prepare($sql);
    $stmt->execute($params);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $primaryQueryError) {
        error_log('fetch_live_orders full query failed: ' . $primaryQueryError->getMessage());
        // Minimal fallback (no optional columns)
        $sql = "SELECT
                  t.transac_id,
                  t.transac_id AS reference_number,
                  t.user_id,
                  t.total_amount,
                  t.status,
                  t.created_at
                FROM `transaction` t
                $where
                ORDER BY t.created_at DESC";
        $stmt = $con->prepare($sql);
        $stmt->execute($params);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Try to attach items; skip silently if table/columns differ
    if ($orders) {
        try {
            $itemStmt = $con->prepare(
                "SELECT ti.transaction_id, ti.quantity, ti.size, ti.price, p.name
                 FROM transaction_items ti
                 JOIN products p ON ti.product_id = p.product_id
                 WHERE ti.transaction_id = ?"
            );
            foreach ($orders as &$o) {
                $itemStmt->execute([(int)$o['transac_id']]);
                $o['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            unset($o);
        } catch (Throwable $ie) {
            error_log('fetch_live_orders items query skipped: ' . $ie->getMessage());
        }
    }

    require __DIR__ . '/markup.php';
} catch (Throwable $e) {
    error_log('fetch_live_orders failed: ' . $e->getMessage());
    http_response_code(500);
    echo '<div class="error-state">Failed to load orders.</div>';
}

?>