<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../admin/database/db_connect.php';

if (!isset($_SESSION['user']['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$userId = (int)$_SESSION['user']['user_id'];

try {
    $db = new Database();
    $pdo = $db->opencon();
    $st = $pdo->prepare("UPDATE users SET has_seen_onboarding = 1, onboarding_seen_at = NOW() WHERE user_id = ?");
    $ok = $st->execute([$userId]);
    echo json_encode(['success' => $ok === true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
