
<?php
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../database/db_connect.php';

$page     = max(1, (int)($_GET['page'] ?? 1));
$pageSize = (int)($_GET['pageSize'] ?? 10);
if ($pageSize < 1)  $pageSize = 10;
if ($pageSize > 50) $pageSize = 50;
$offset = ($page - 1) * $pageSize;

$sort = $_GET['sort'] ?? 'id_desc';
switch ($sort) {
    case 'id_asc':       $orderBy = 't.transac_id ASC'; break;
    case 'created_asc':  $orderBy = 't.created_at ASC'; break;
    case 'created_desc': $orderBy = 't.created_at DESC'; break;
    default:             $orderBy = 't.transac_id DESC'; // id_desc (default)
}

try {
    $db  = new Database();
    $pdo = $db->openPdo();

    $total = (int)$pdo->query("SELECT COUNT(*) FROM `transaction` WHERE status = 'picked up'")->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $pageSize));
    $orders = [];

    if ($total > 0) {
        $sql = "SELECT 
                    t.transac_id,
                    COALESCE(t.reference_number, t.transac_id) AS reference_number,
                    t.total_amount,
                    t.status,
                    t.created_at,
                    u.user_FN AS customer_name
                FROM `transaction` t
                LEFT JOIN users u ON t.user_id = u.user_id
                WHERE t.status = 'picked up'
                ORDER BY $orderBy
                LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limit',  $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,   PDO::PARAM_INT);
        $stmt->execute();
        $orders = $stmt->fetchAll();

        if ($orders) {
            $itemStmt = $pdo->prepare("
                SELECT ti.quantity, ti.size, ti.price, p.name
                FROM transaction_items ti
                JOIN products p ON ti.product_id = p.id
                WHERE ti.transaction_id = ?
            ");
            foreach ($orders as &$o) {
                $itemStmt->execute([(int)$o['transac_id']]);
                $o['items'] = $itemStmt->fetchAll() ?: [];
            }
            unset($o);
        }
    }

    echo json_encode([
        'success'    => true,
        'page'       => $page,
        'pageSize'   => $pageSize,
        'total'      => $total,
        'totalPages' => $totalPages,
        'orders'     => array_map(function ($o) {
            return [
                'reference_number' => $o['reference_number'],
                'customer_name'    => $o['customer_name'] ?? 'Unknown',
                'total_amount'     => (float)$o['total_amount'],
                'status'           => (string)$o['status'],
                'created_at'       => $o['created_at'],
                'items'            => array_map(function ($it) {
                    return [
                        'name'     => $it['name'],
                        'quantity' => (int)$it['quantity'],
                        'size'     => $it['size'],
                        'price'    => (float)$it['price'],
                    ];
                }, $o['items'] ?? []),
            ];
        }, $orders),
    ]);
} catch (Throwable $e) {
    error_log('fetch_all_pickedup_orders error: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error']);
}