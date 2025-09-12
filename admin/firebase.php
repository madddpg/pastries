<?php
require __DIR__ . '/../vendor/autoload.php';  // <-- go up one folder

use Google\Auth\Credentials\ServiceAccountCredentials;
use Dotenv\Dotenv;

// Load .env from project root
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$serviceAccountJson = base64_decode($_ENV['FIREBASE_CREDENTIALS_BASE64']);
$serviceAccount = json_decode($serviceAccountJson, true);

if (!$serviceAccount || !isset($serviceAccount['project_id'])) {
    throw new Exception('Invalid service account credentials');
}

$scopes = ['https://www.googleapis.com/auth/firebase.messaging'];
$credentials = new ServiceAccountCredentials($scopes, $serviceAccount);

$token = $credentials->fetchAuthToken();
if (!isset($token['access_token'])) {
    throw new Exception('Failed to obtain access token for FCM');
}

return [
    'access_token' => $token['access_token'],
    'project_id'   => $serviceAccount['project_id'],
];
