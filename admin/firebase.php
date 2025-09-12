
<?php
require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use Google\Auth\Credentials\ServiceAccountCredentials;

$root = dirname(__DIR__);
$loaded = false;
foreach (['/.env','/.env.example'] as $rel) {
    $f = $root.$rel;
    if (is_file($f)) {
        Dotenv::createImmutable($root, basename($f))->safeLoad();
        $loaded = true;
        break;
    }
}

$path = $_ENV['FIREBASE_SERVICE_ACCOUNT_PATH'] ?? getenv('FIREBASE_SERVICE_ACCOUNT_PATH') ?: '';
if (!$path) throw new Exception('FIREBASE_SERVICE_ACCOUNT_PATH not set (no .env or .env.example value).');
if (!is_file($path)) throw new Exception('Service account file not found at: '.$path);

$sa = json_decode(file_get_contents($path), true);
if (!is_array($sa) ||
    ($sa['type'] ?? '') !== 'service_account' ||
    empty($sa['project_id']) ||
    empty($sa['private_key']) ||
    empty($sa['client_email'])) {
    throw new Exception('Invalid service account JSON.');
}

$scopes = ['https://www.googleapis.com/auth/firebase.messaging'];
$cred = new ServiceAccountCredentials($scopes, $sa);
$tokenArr = $cred->fetchAuthToken();
$token = $tokenArr['access_token'] ?? null;
if (!$token) throw new Exception('Failed to obtain access token.');

return [
    'access_token' => $token,
    'project_id'   => $sa['project_id'],
];