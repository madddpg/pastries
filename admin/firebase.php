
<?php
require __DIR__ . '/../vendor/autoload.php';
use Google\Auth\Credentials\ServiceAccountCredentials;

// HARD-CODE PRODUCTION PATH ONLY
$path = '/home/u778762049/secure/service_account.json';

if (!is_file($path)) {
    // TEMP debug (remove after it works)
    throw new Exception("Service account file not found at: $path");
}

$sa = json_decode(file_get_contents($path), true);
if (($sa['type'] ?? '') !== 'service_account') {
    throw new Exception("Invalid service account JSON");
}

$cred = new ServiceAccountCredentials(
    ['https://www.googleapis.com/auth/firebase.messaging'],
    $sa
);
$tokenArr = $cred->fetchAuthToken();
$token = $tokenArr['access_token'] ?? null;
if (!$token) throw new Exception('Failed to obtain access token');

return [
    'access_token' => $token,
    'project_id'   => $sa['project_id']
];