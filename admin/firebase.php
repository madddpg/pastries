
<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/database/db_connect.php';
if (!(Database::isAdmin() || Database::isSuperAdmin() || !empty($_SESSION['admin_id']))) {
    echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit;
}

$raw = json_decode(file_get_contents('php://input'), true);
$token = trim($raw['token'] ?? '');
if ($token === '') {
    echo json_encode(['success'=>false,'message'=>'Empty token']); exit;
}

$serverKey = getenv('FCM_SERVER_KEY');
if (!$serverKey) {
    echo json_encode(['success'=>false,'message'=>'Server key not configured']); exit;
}

$ch = curl_init("https://iid.googleapis.com/iid/v1/{$token}/rel/topics/admins");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: key={$serverKey}",
        "Content-Length: 0"
    ],
    CURLOPT_RETURNTRANSFER => true
]);
$response = curl_exec($ch);
$err = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err || $code >= 300) {
    error_log("FCM topic subscribe error: code=$code body=$response err=$err");
    echo json_encode(['success'=>false,'message'=>'Subscription failed']); exit;
}

echo json_encode(['success'=>true]);