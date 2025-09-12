
<?php
require __DIR__ . '/../vendor/autoload.php';
use Google\Auth\Credentials\ServiceAccountCredentials;

$path = '/home/u778762049/domains/cupsandcuddles.online/secure/service_account.json'; // confirmed FOUND

if (!is_file($path)) {
    throw new Exception("Service account file not found at: $path");
}

$sa = json_decode(file_get_contents($path), true);
if (($sa['type'] ?? '') !== 'service_account'
    || empty($sa['project_id'])
    || empty($sa['private_key'])
    || empty($sa['client_email'])) {
    throw new Exception("Invalid service account JSON");
}

$cred = new ServiceAccountCredentials(
    ['https://www.googleapis.com/auth/firebase.messaging'],
    $sa
);
$tok = $cred->fetchAuthToken();
if (empty($tok['access_token'])) throw new Exception('Failed to obtain access token');

return [
  'access_token' => $tok['access_token'],
  'project_id'   => $sa['project_id']
];