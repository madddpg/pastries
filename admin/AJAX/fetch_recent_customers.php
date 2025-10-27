<?php
require_once __DIR__ . '/../database/db_connect.php';
$db = new Database();
$con = $db->opencon();

function timeAgo($datetime) {
    if (!$datetime) return '';
    try {
        $tz = new DateTimeZone('Asia/Manila');
        $now = new DateTime('now', $tz);
        $dt  = new DateTime($datetime, $tz);
        $diff = max(0, $now->getTimestamp() - $dt->getTimestamp());
        if ($diff < 60)   return $diff . ' sec ago';
        if ($diff < 3600) return floor($diff/60) . ' min ago';
        if ($diff < 86400) return floor($diff/3600) . ' hr ago';
        return $dt->format('M d, Y H:i');
    } catch (Throwable $e) {
        return (string)$datetime;
    }
}

// Fetch each user's most recent transaction with its amount
$sql = "
    SELECT u.user_FN, u.user_LN, lt.last_transaction, lt.total_amount
    FROM users u
    JOIN (
        SELECT t1.user_id, t1.created_at AS last_transaction, t1.total_amount
        FROM `transaction` t1
        JOIN (
            SELECT user_id, MAX(created_at) AS last_created
            FROM `transaction`
            GROUP BY user_id
        ) m ON m.user_id = t1.user_id AND m.last_created = t1.created_at
    ) lt ON lt.user_id = u.user_id
    ORDER BY lt.last_transaction DESC
    LIMIT 5
";
$recentStmt = $con->prepare($sql);
$recentStmt->execute();
$recentCustomers = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

