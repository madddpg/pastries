<?php
header('Content-Type: application/json');

$firebasePath = __DIR__ . '/firebase.php';
if (!is_file($firebasePath)) {
    http_response_code(500);
    echo json_encode(['error'=>'firebase.php not found at '.$firebasePath]);
    exit;
}

$firebase = require $firebasePath;

if (empty($firebase['project_id']) || empty($firebase['access_token'])) {
    http_response_code(500);
    echo json_encode(['error'=>'Firebase credentials not loaded']);
    exit;
}

$projectId   = $firebase['project_id'];
$accessToken = $firebase['access_token'];

$url = "https://fcm.googleapis.com/v1/projects/$projectId/messages:send";

$message = [
    'message' => [
        'topic' => 'admin',
        'notification' => [
            'title' => 'New Order',
            'body'  => 'You have a new coffee order!'
        ],
        'data' => [
            'click_action' => '/admin/orders'
        ]
    ]
];

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $accessToken",
        "Content-Type: application/json"
    ],
    CURLOPT_POSTFIELDS => json_encode($message, JSON_UNESCAPED_SLASHES),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15
]);
$response = curl_exec($ch);
$error = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo json_encode([
    'httpCode' => $httpCode,
    'error'    => $error ?: null,
    'response' => json_decode($response, true)
]);