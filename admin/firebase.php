
<?php
require __DIR__.'/../vendor/autoload.php';
use Google\Auth\Credentials\ServiceAccountCredentials;

$path = '/home/u778762049/domains/cupsandcuddles.online/secure/service_account.json';
if(!is_file($path)) throw new Exception("SA file missing: $path");
$sa = json_decode(file_get_contents($path), true);
$cred = new ServiceAccountCredentials(
  ['https://www.googleapis.com/auth/firebase.messaging'],
  $sa
);
$tok = $cred->fetchAuthToken();
if(empty($tok['access_token'])) throw new Exception('Access token failure');
return [
  'access_token'=>$tok['access_token'],
  'project_id'=>$sa['project_id']
];