<?php
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success'=>false,'message'=>'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'message'=>'POST required']);
    exit;
}

require_once __DIR__ . '/database/db_connect.php';

$raw = json_decode(file_get_contents('php://input'), true);
if (!is_array($raw)) {
    $raw = $_POST;
}

$token = trim($raw['token'] ?? '');
$title = trim($raw['title'] ?? '');
$body  = trim($raw['body'] ?? '');
$reference = trim($raw['reference'] ?? '');

$db = new Database();

// Register token only
if ($token && !$title && !$body && !$reference) {
    if ($db->saveAdminFcmToken((int)$_SESSION['admin_id'], $token)) {
        echo json_encode(['success'=>true,'message'=>'Token registered']);
    } else {
        http_response_code(500);
        echo json_encode(['success'=>false,'message'=>'Token save failed']);
    }
    exit;
}

// Need title & body for sending
if ($title === '' || $body === '') {
    http_response_code(422);
    echo json_encode(['success'=>false,'message'=>'Title/body required (unless only registering token)']);
    exit;
}

try {
    $db->pushAdminNotification($title, $body, $reference ?: null);
    echo json_encode(['success'=>true,'message'=>'Notification dispatched']);
} catch (Throwable $e) {
    error_log('send_fcm error: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Internal error']);
}