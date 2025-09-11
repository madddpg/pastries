<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/database/db_connect.php';
$firebase = require __DIR__ . '/firebase.php';

if (empty($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$raw = json_decode(file_get_contents('php://input'), true);
$token = trim($raw['token'] ?? '');
if ($token === '') {
    echo json_encode(['success' => false, 'message' => 'Empty token']);
    exit;
}

$db = new Database();

// Save token in DB
if (!$db->saveAdminFcmToken((int)$_SESSION['admin_id'], $token)) {
    echo json_encode(['success' => false, 'message' => 'Token save failed']);
    exit;
}

// Subscribe to topic "admins"
$projectId   = $firebase['project_id'];
$accessToken = $firebase['access_token'];
$topic       = 'admins';

$url = "https://iid.googleapis.com/v1:batchAdd";
$payload = [
    'to' => "/topics/$topic",
    'registration_tokens' => [$token],
];

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $accessToken",
        "Content-Type: application/json"
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true,
]);
$response = curl_exec($ch);
curl_close($ch);

echo json_encode(['success' => true]);
