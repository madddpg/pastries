<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/database/db_connect.php';
if (empty($_SESSION['admin_id'])) {
    echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit;
}

$raw = json_decode(file_get_contents('php://input'), true);
$token = trim($raw['token'] ?? '');
if ($token === '') {
    echo json_encode(['success'=>false,'message'=>'Empty token']); exit;
}

$db = new Database();

// Persist token in admin_users
if (!$db->saveAdminFcmToken((int)$_SESSION['admin_id'], $token)) {
    echo json_encode(['success'=>false,'message'=>'Token save failed']); exit;
}

// Subscribe token to topic (optional)
$serverKey = getenv('FCM_SERVER_KEY');
if ($serverKey) {
    $ch = curl_init("https://iid.googleapis.com/iid/v1/{$token}/rel/topics/admins");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: key={$serverKey}",
            "Content-Length: 0"
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10
    ]);
    curl_exec($ch); // ignore body
    curl_close($ch);
} else {
    error_log('FCM_SERVER_KEY missing (topic subscribe skipped)');
}

echo json_encode(['success'=>true]);