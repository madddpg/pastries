
<?php
header('Content-Type: application/json');
$firebase = require __DIR__ . '/firebase.php';

$projectId = $firebase['project_id'];
$accessToken = $firebase['access_token'];
$url = "https://fcm.googleapis.com/v1/projects/$projectId/messages:send";

$token = $_GET['token'] ?? '';

$payload = [
  'message' => $token
    ? [
        'token' => $token,
        'notification' => ['title'=>'Direct Test','body'=>'Single token push'],
        'data' => ['click_action'=>'/admin/']
      ]
    : [
        'topic' => 'admin',
        'notification' => ['title'=>'Topic Test','body'=>'Admin topic test'],
        'data' => ['click_action'=>'/admin/']
      ]
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
  CURLOPT_TIMEOUT => 15
]);
$r = curl_exec($ch);
$err = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo json_encode([
  'httpCode'=>$code,
  'error'=>$err?:null,
  'resp'=>json_decode($r,true),
  'raw'=>$r
]);