<?php
header('Content-Type: application/json');
$firebase = require __DIR__.'/firebase.php';

$token = $_GET['token'] ?? '';
$url = "https://fcm.googleapis.com/v1/projects/{$firebase['project_id']}/messages:send";

$message = $token
 ? ['token'=>$token,'notification'=>['title'=>'Direct Test','body'=>'Single token push'],'data'=>['click_action'=>'/admin/']]
 : ['topic'=>'admin','notification'=>['title'=>'Topic Test','body'=>'Admin topic test'],'data'=>['click_action'=>'/admin/']];

$payload = ['message'=>$message];

$ch = curl_init($url);
curl_setopt_array($ch,[
  CURLOPT_POST=>true,
  CURLOPT_HTTPHEADER=>[
    "Authorization: Bearer {$firebase['access_token']}",
    "Content-Type: application/json"
  ],
  CURLOPT_POSTFIELDS=>json_encode($payload),
  CURLOPT_RETURNTRANSFER=>true,
  CURLOPT_TIMEOUT=>15
]);
$r = curl_exec($ch);
$err = curl_error($ch);
$code = curl_getinfo($ch,CURLINFO_HTTP_CODE);
curl_close($ch);

echo json_encode([
  'httpCode'=>$code,
  'error'=>$err?:null,
  'resp'=>json_decode($r,true),
  'raw'=>$r
]);