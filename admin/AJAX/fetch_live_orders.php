
<?php
ini_set('display_errors', '0');
require_once __DIR__ . '/../database/db_connect.php';
header('Content-Type: text/html; charset=UTF-8');

$status  = isset($_GET['status']) ? trim($_GET['status']) : '';
$location = isset($_GET['location']) ? trim($_GET['location']) : '';
// Pagination params
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['perPage']) ? max(1, min(50, (int)$_GET['perPage'])) : 8; // clamp 1..50
$allowed = ['pending', 'preparing', 'ready'];
$params  = [];
$where   = "WHERE t.status IN ('pending','preparing','ready')";
if ($status !== '' && in_array($status, $allowed, true)) {
    $where = "WHERE t.status = ?";
    $params[] = $status;
}

// Optional location filter (prefix match to handle stored phone suffixes)
if ($location !== '') {
    // $where always starts with a WHERE status clause; safely append AND
    $where .= " AND p.pickup_location LIKE ?";
    $params[] = $location . '%';
}

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
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Full dataset with pagination (safe aliases and backticks)
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
                ORDER BY t.created_at DESC
                LIMIT :limit OFFSET :offset";
        $stmt = $con->prepare($sql);
        // Bind status/location params
        $idx = 1;
        foreach ($params as $p) {
            $stmt->bindValue($idx++, $p);
        }
        $stmt->bindValue(':limit', (int)$perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)(($page - 1) * $perPage), PDO::PARAM_INT);
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
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();
            header('X-Total-Count: ' . $total);
            $totalPages = (int)ceil($total / max(1, $perPage));
            header('X-Total-Pages: ' . $totalPages);
            header('X-Page: ' . $page);
            header('X-Per-Page: ' . $perPage);
        } catch (Throwable $e2) { /* ignore */ }

        $sql = "SELECT
                                    t.transac_id,
                                    t.transac_id AS reference_number,
                                    t.user_id,
                                    t.total_amount,
                                    t.status,
                                    t.created_at
                                FROM `transaction` t
                                LEFT JOIN pickup_detail p ON t.transac_id = p.transaction_id
                $where
                ORDER BY t.created_at DESC
                LIMIT :limit OFFSET :offset";
        $stmt = $con->prepare($sql);
        $idx = 1;
        foreach ($params as $p) { $stmt->bindValue($idx++, $p); }
        $stmt->bindValue(':limit', (int)$perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)(($page - 1) * $perPage), PDO::PARAM_INT);
        $stmt->execute();
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