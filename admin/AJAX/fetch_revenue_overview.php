<?php
require_once __DIR__ . '/../database/db_connect.php';
header('Content-Type: application/json');

try {
    $db = new Database();
    $con = $db->opencon();
    $tz = new DateTimeZone('Asia/Manila');
    $today = (new DateTime('now', $tz))->format('Y-m-d');
    $startOfWeek = (new DateTime('monday this week', $tz))->format('Y-m-d');
    $startOfMonth = (new DateTime('first day of this month', $tz))->format('Y-m-d');
    $startOfYear = (new DateTime('first day of January ' . date('Y'), $tz))->format('Y-m-d');
    // Today
    $stmtToday = $con->prepare("SELECT SUM(total_amount) as total FROM transaction WHERE DATE(created_at) = ? AND status NOT IN ('pending', 'cancelled')");
    $stmtToday->execute([$today]);
    $todayRevenue = (float)($stmtToday->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    // This week
    $stmtWeek = $con->prepare("SELECT SUM(total_amount) as total FROM transaction WHERE DATE(created_at) >= ? AND DATE(created_at) <= ? AND status NOT IN ('pending', 'cancelled')");
    $stmtWeek->execute([$startOfWeek, $today]);
    $weekRevenue = (float)($stmtWeek->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    // This month
    $stmtMonth = $con->prepare("SELECT SUM(total_amount) as total FROM transaction WHERE DATE(created_at) >= ? AND DATE(created_at) <= ? AND status NOT IN ('pending', 'cancelled')");
    $stmtMonth->execute([$startOfMonth, $today]);
    $monthRevenue = (float)($stmtMonth->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    // This year
    $stmtYear = $con->prepare("SELECT SUM(total_amount) as total FROM transaction WHERE DATE(created_at) >= ? AND DATE(created_at) <= ? AND status NOT IN ('pending', 'cancelled')");
    $stmtYear->execute([$startOfYear, $today]);
    $yearRevenue = (float)($stmtYear->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    echo json_encode([
        'success' => true,
        'data' => [
            'today' => $todayRevenue,
            'week' => $weekRevenue,
            'month' => $monthRevenue,
            'year' => $yearRevenue
        ]
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
