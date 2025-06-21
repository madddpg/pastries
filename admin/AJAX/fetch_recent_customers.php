<?php
require_once __DIR__ . '/../database_connections/db_connect.php';
// No header for JSON, this is a PHP include for server-side rendering only

// Use the same connection logic as the rest of the admin dashboard
$db = new Database();
$con = $db->opencon();

function timeAgo($datetime) {
    $tz = new DateTimeZone('Asia/Manila');
    $now = new DateTime('now', $tz);
    $dt = new DateTime($datetime, $tz);
    $diff = $now->getTimestamp() - $dt->getTimestamp();
    if ($diff < 0) $diff = 0;
    if ($diff < 60) return $diff . ' sec ago';
    if ($diff < 3600) return floor($diff/60) . ' min ago';
    if ($diff < 86400) return floor($diff/3600) . ' hr ago';
    return $dt->format('M d, Y H:i');
}

// Fetch the 5 most recent unique customers (by user_id), with their latest transaction
$recentStmt = $con->prepare("SELECT u.user_FN, u.user_LN, MAX(t.created_at) as last_transaction, t.total_amount
    FROM users u
    JOIN transaction t ON t.user_id = u.user_id
    GROUP BY u.user_id
    ORDER BY last_transaction DESC
    LIMIT 5");
$recentStmt->execute();
$recentCustomers = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
// This file is now ready to be included directly in admin.php for server-side rendering.
