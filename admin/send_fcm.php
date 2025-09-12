
<?php
header('Content-Type: application/json');
$firebase = require __DIR__.'/firebase.php';
$token = $_GET['token'] ?? '';
$url="https://fcm.googleapis.com/v1/projects/{$firebase['project_id']}/messages:send";
$dataBase = [
  'title'=>$token?'Direct Test (data)':'Topic Test (data)',
  'body'=>$token?'Single token push':'Admin topic test',
  'click_action'=>'/admin/',
  'icon'=>'../img/kape.png',
  'image'=>'../img/logo.png'
];
$message = $token
  ? ['token'=>$token,'data'=>$dataBase]
  : ['topic'=>'admin','data'=>$dataBase];
$payload=['message'=>$message];
$ch=curl_init($url);
curl_setopt_array($ch,[
  CURLOPT_POST=>true,
  CURLOPT_HTTPHEADER=>[
    "Authorization: Bearer {$firebase['access_token']}",
    "Content-Type: application/json"
  ],
  CURLOPT_POSTFIELDS=>json_encode($payload),
  CURLOPT_RETURNTRANSFER=>true
]);
$r=curl_exec($ch);
$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);
$err=curl_error($ch);
curl_close($ch);
echo json_encode(['httpCode'=>$code,'error'=>$err?:null,'resp'=>json_decode($r,true),'raw'=>$r]);