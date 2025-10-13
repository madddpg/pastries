
<?php
ini_set('display_errors', '0');
require_once __DIR__ . '/../database/db_connect.php';
header('Content-Type: text/html; charset=UTF-8');

$status  = isset($_GET['status']) ? trim($_GET['status']) : '';
$location = isset($_GET['location']) ? trim($_GET['location']) : '';
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
// Pagination params
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['perPage']) ? max(1, min(50, (int)$_GET['perPage'])) : 8; // clamp 1..50
$allowed = ['pending', 'preparing', 'ready'];
// Build WHERE with named binds to avoid mixing placeholders
$bind = [];
$clauses = [];
// Status clause: for specific statuses include user-cancelled alongside; for special tab cancelled_user show only user-cancelled
if ($status === 'cancelled_user') {
    $clauses[] = "(t.status = 'cancelled' AND (t.admin_id IS NULL OR t.admin_id = 0))";
} elseif ($status !== '' && in_array($status, $allowed, true)) {
    $clauses[] = "(t.status = :status OR (t.status = 'cancelled' AND (t.admin_id IS NULL OR t.admin_id = 0)))";
    $bind[':status'] = $status;
} else {
    $clauses[] = "(t.status IN ('pending','preparing','ready') OR (t.status = 'cancelled' AND (t.admin_id IS NULL OR t.admin_id = 0)))";
}
if ($location !== '') {
    $clauses[] = 'p.pickup_location LIKE :location';
    $bind[':location'] = $location . '%';
}
if ($q !== '') {
    $clauses[] = "(CONCAT_WS(' ', u.user_FN, u.user_LN) LIKE :q1 OR u.user_FN LIKE :q2 OR u.user_LN LIKE :q3)";
    $like = '%' . $q . '%';
    $bind[':q1'] = $like;
    $bind[':q2'] = $like;
    $bind[':q3'] = $like;
}
// Only show today's orders so previous-day live orders expire at midnight
$clauses[] = 'DATE(t.created_at) = CURDATE()';
$where = 'WHERE ' . implode(' AND ', $clauses);

try {
    $db  = new Database();
    $con = $db->opencon();

    $orders = [];
    try {
        // Count total for pagination
    $countSql = "SELECT COUNT(*)
                FROM `transaction` t
                LEFT JOIN users u ON t.user_id = u.user_id
                LEFT JOIN pickup_detail p ON t.transac_id = p.transaction_id
                $where";
        $countStmt = $con->prepare($countSql);
        foreach ($bind as $k => $v) { $countStmt->bindValue($k, $v); }
        $countStmt->execute();
        $total = (int)$countStmt->fetchColumn();

        // Full dataset with pagination (safe aliases and backticks)
        $limit = (int)$perPage;
        $offset = (int)(($page - 1) * $perPage);
                                                                                                                                $sql = "SELECT
                  t.transac_id,
                  COALESCE(t.reference_number, t.transac_id) AS reference_number,
                  t.user_id,
                  t.total_amount,
                  t.status,
                                    t.admin_id,
                  t.created_at,
                                                                        COALESCE(t.payment_method,'gcash') AS payment_method,
                                                                        COALESCE(t.gcash_receipt_path, t.gcash_reciept_path) AS gcash_receipt_path,
                                    u.user_FN AS user_FN,
                                    u.user_LN AS user_LN,
                                    u.user_FN AS customer_name,
                                    p.pickup_location,
                  p.pickup_time,
                  p.special_instructions
                FROM `transaction` t
                LEFT JOIN users u ON t.user_id = u.user_id
                LEFT JOIN pickup_detail p ON t.transac_id = p.transaction_id
                $where
                ORDER BY t.created_at DESC
                LIMIT $limit OFFSET $offset";
        $stmt = $con->prepare($sql);
        foreach ($bind as $k => $v) { $stmt->bindValue($k, $v); }
        $stmt->execute();
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Set pagination headers
        header('X-Total-Count: ' . $total);
        $totalPages = (int)ceil($total / max(1, $perPage));
        header('X-Total-Pages: ' . $totalPages);
        header('X-Page: ' . $page);
        header('X-Per-Page: ' . $perPage);
    } catch (Throwable $primaryQueryError) {
        error_log('fetch_live_orders full query failed: ' . $primaryQueryError->getMessage());
        // Minimal fallback (no optional columns) + include pagination and joins for filtering
        // Recompute total as best-effort
        try {
            $countSql = "SELECT COUNT(*) FROM `transaction` t LEFT JOIN pickup_detail p ON t.transac_id = p.transaction_id $where";
            $countStmt = $con->prepare($countSql);
            foreach ($bind as $k => $v) { $countStmt->bindValue($k, $v); }
            $countStmt->execute();
            $total = (int)$countStmt->fetchColumn();
            header('X-Total-Count: ' . $total);
            $totalPages = (int)ceil($total / max(1, $perPage));
            header('X-Total-Pages: ' . $totalPages);
            header('X-Page: ' . $page);
            header('X-Per-Page: ' . $perPage);
        } catch (Throwable $e2) { /* ignore */ }

        $limit = (int)$perPage;
        $offset = (int)(($page - 1) * $perPage);
        $sql = "SELECT
                                    t.transac_id,
                                    COALESCE(t.reference_number, t.transac_id) AS reference_number,
                                    t.user_id,
                                    t.total_amount,
                                    t.status,
                                    t.admin_id,
                                    t.created_at,
                                    u.user_FN AS user_FN,
                                    u.user_LN AS user_LN
                                FROM `transaction` t
                                LEFT JOIN users u ON t.user_id = u.user_id
                                LEFT JOIN pickup_detail p ON t.transac_id = p.transaction_id
                $where
                ORDER BY t.created_at DESC
                LIMIT $limit OFFSET $offset";
        $stmt = $con->prepare($sql);
        foreach ($bind as $k => $v) { $stmt->bindValue($k, $v); }
        $stmt->execute();
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Try to attach items; skip silently if table/columns differ
    if ($orders) {
        try {
            $itemStmt = $con->prepare(
                "SELECT ti.transaction_id, ti.quantity, ti.size, ti.price, ti.sugar_level, p.name
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