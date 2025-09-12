
<?php
require __DIR__ . '/../vendor/autoload.php';
use Google\Auth\Credentials\ServiceAccountCredentials;

$candidates = [
    '/home/u778762049/domains/cupsandcuddles.online/secure/service_account.json', // based on screenshot
    '/home/u778762049/secure/service_account.json',                               // earlier guess
];

$path = null;
foreach ($candidates as $p) {
    if (is_file($p)) { $path = $p; break; }
}
if (!$path) {
    throw new Exception("Service account file not found. Tried:\n" . implode("\n", $candidates));
}

$sa = json_decode(file_get_contents($path), true);
if (($sa['type'] ?? '') !== 'service_account') {
    throw new Exception("Invalid service account JSON at $path");
}

$cred = new ServiceAccountCredentials(
    ['https://www.googleapis.com/auth/firebase.messaging'],
    $sa
);
$tokArr = $cred->fetchAuthToken();
$token = $tokArr['access_token'] ?? null;
if (!$token) throw new Exception('Failed to obtain access token');

return [
  'access_token' => $token,
  'project_id'   => $sa['project_id'],
];