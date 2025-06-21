<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Manila');
// Returns dashboard stats as JSON
require_once '../database_connections/db_connect.php'; // Fixed path
$db = new Database();
$con = $db->opencon();

// Total Orders Today
$today = date('Y-m-d');
$stmt = $con->prepare("SELECT COUNT(*) FROM transaction WHERE DATE(created_at) = ?");
$stmt->execute([$today]);
$totalOrdersToday = (int)$stmt->fetchColumn();

// Pending Orders
$stmt = $con->prepare("SELECT COUNT(*) FROM transaction WHERE LOWER(TRIM(status)) = ? AND DATE(created_at) = ?");
$stmt->execute(['pending', $today]);
$pendingOrders = (int)$stmt->fetchColumn();

// Preparing Orders
$stmt = $con->prepare("SELECT COUNT(*) FROM transaction WHERE LOWER(TRIM(status)) = ? AND DATE(created_at) = ?");
$stmt->execute(['preparing', $today]);
$preparingOrders = (int)$stmt->fetchColumn();

// Ready Orders
$stmt = $con->prepare("SELECT COUNT(*) FROM transaction WHERE LOWER(TRIM(status)) = ? AND DATE(created_at) = ?");
$stmt->execute(['ready', $today]);
$readyOrders = (int)$stmt->fetchColumn();

header('Content-Type: application/json');
echo json_encode([
    'totalOrdersToday' => $totalOrdersToday,
    'pendingOrders' => $pendingOrders,
    'preparingOrders' => $preparingOrders,
    'readyOrders' => $readyOrders
]);
