<?php
require_once __DIR__ . '/../database_connections/db_connect.php';
header('Content-Type: application/json');

try {
    $db = new Database();
    $con = $db->opencon();
    $tz = new DateTimeZone('Asia/Manila');
    $today = (new DateTime('now', $tz))->format('Y-m-d');
    $startOfWeek = (new DateTime('monday this week', $tz))->format('Y-m-d');
    // Today
    $stmtToday = $con->prepare("SELECT SUM(total_amount) as total FROM transaction WHERE DATE(created_at) = ?");
    $stmtToday->execute([$today]);
    $todayRevenue = (float)($stmtToday->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    // This week
    $stmtWeek = $con->prepare("SELECT SUM(total_amount) as total FROM transaction WHERE DATE(created_at) >= ? AND DATE(created_at) <= ?");
    $stmtWeek->execute([$startOfWeek, $today]);
    $weekRevenue = (float)($stmtWeek->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    echo json_encode([
        'success' => true,
        'data' => [
            'today' => $todayRevenue,
            'week' => $weekRevenue
        ]
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
