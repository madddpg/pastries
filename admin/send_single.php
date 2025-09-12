
<?php
header('Content-Type: application/json');

try {
    $firebase = require __DIR__ . '/firebase.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'firebase_init_failed', 'message' => $e->getMessage()]);
    exit;
}

$token = $_GET['token'] ?? '';
if (!$token) {
    echo json_encode(['error' => 'token_required']);
    exit;
}

$url = "https://fcm.googleapis.com/v1/projects/{$firebase['project_id']}/messages:send";

/*
 Data‑only payload so:
  - Foreground: messaging.onMessage fires (we build UI + OS notification)
  - Background: service worker onBackgroundMessage builds OS notification
*/
$payload = [
    'message' => [
        'token' => $token,
        'data' => [
            'title'        => 'Direct Test',
            'body'         => 'Single token push',
            'click_action' => '/admin/',
            'icon'         => '/img/kape.png',
            'image'        => '/img/logo.png',
            'sent_at'      => (string)time()
        ]
    ]
];

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST            => true,
    CURLOPT_HTTPHEADER      => [
        "Authorization: Bearer {$firebase['access_token']}",
        "Content-Type: application/json"
    ],
    CURLOPT_POSTFIELDS      => json_encode($payload),
    CURLOPT_RETURNTRANSFER  => true,
    CURLOPT_TIMEOUT         => 15
]);
$response = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

echo json_encode([
    'httpCode' => $http,
    'curlError'=> $err ?: null,
    'resp'     => json_decode($response, true),
    'raw'      => $response
]);