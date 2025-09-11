<?php
require_once __DIR__ . '/database/db_connect.php';
$firebase = require __DIR__ . '/firebase.php';

$db = new Database();
$result = $db->getAdminFcmToken(1); // Example: admin with ID 1
$token = $result['fcm_token'] ?? null;

if ($token) {
    $projectId   = $firebase['project_id'];
    $accessToken = $firebase['access_token'];

    $url = "https://fcm.googleapis.com/v1/projects/$projectId/messages:send";

    $message = [
        'message' => [
            'token' => $token,
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
        CURLOPT_POSTFIELDS => json_encode($message),
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    echo $response ?: "Notification sent (empty response)";
} else {
    echo "No FCM token found for admin.";
}
