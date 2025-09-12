<?php
require_once __DIR__ . '/database/db_connect.php';
$firebase = require __DIR__ . './firebase.php';

$projectId   = $firebase['project_id'];
$accessToken = $firebase['access_token'];

$url = "https://fcm.googleapis.com/v1/projects/$projectId/messages:send";

// Build the notification payload
$message = [
    'message' => [
        'topic' => 'admins', // ✅ send to all "admins"
        'notification' => [
            'title' => 'New Order',
            'body'  => 'You have a new coffee order!'
        ],
        'data' => [
            'click_action' => '/admin/orders'
        ]
    ]
];

// Send request
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $accessToken",
        "Content-Type: application/json"
    ],
    CURLOPT_POSTFIELDS => json_encode($message),
    CURLOPT_RETURNTRANSFER => true,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Output
header('Content-Type: application/json');
echo json_encode([
    'httpCode'  => $httpCode,
    'response'  => json_decode($response, true)
]);
