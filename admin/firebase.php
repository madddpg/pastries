
<?php
require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use Google\Auth\Credentials\ServiceAccountCredentials;

$root = dirname(__DIR__);
$serviceAccount = null;

/**
 * 1. If .env exists and looks like KEY=VALUE, load it (Dotenv).
 *    - Prefer FIREBASE_CREDENTIALS_BASE64
 *    - Or individual FIREBASE_* vars
 * 2. If .env exists but is pure JSON (your current file), parse it directly.
 * 3. Else try service_account.json (ignored by Git).
 */
$envPath = $root . '/.env';
if (is_file($envPath)) {
    $raw = file_get_contents($envPath);
    if (strpos($raw, '=') !== false) {
        // Standard env format
        Dotenv::createImmutable($root)->safeLoad();
        if (!empty($_ENV['FIREBASE_CREDENTIALS_BASE64'])) {
            $serviceAccount = json_decode(base64_decode($_ENV['FIREBASE_CREDENTIALS_BASE64']), true);
        } elseif (!empty($_ENV['FIREBASE_PROJECT_ID']) && !empty($_ENV['FIREBASE_PRIVATE_KEY'])) {
            // Build from individual vars
            $serviceAccount = [
                'type' => 'service_account',
                'project_id' => $_ENV['FIREBASE_PROJECT_ID'],
                'private_key_id' => $_ENV['FIREBASE_PRIVATE_KEY_ID'] ?? '',
                'private_key' => str_replace(["\\n","\\r\\n"], "\n", $_ENV['FIREBASE_PRIVATE_KEY']),
                'client_email' => $_ENV['FIREBASE_CLIENT_EMAIL'],
                'client_id' => $_ENV['FIREBASE_CLIENT_ID'] ?? '',
                'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
                'token_uri' => 'https://oauth2.googleapis.com/token',
                'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
                'client_x509_cert_url' => $_ENV['FIREBASE_CLIENT_X509_CERT_URL'] ?? ''
            ];
        }
    } else {
        // Treat as raw JSON service account (current state)
        $serviceAccount = json_decode($raw, true);
    }
}

// Fallback to separate JSON file if still not loaded
if (!$serviceAccount && is_file($root . '/service_account.json')) {
    $serviceAccount = json_decode(file_get_contents($root . '/service_account.json'), true);
}

if (!$serviceAccount || empty($serviceAccount['project_id']) || empty($serviceAccount['private_key'])) {
    throw new Exception('Firebase service account credentials not found or invalid.');
}

// Build OAuth token via google/auth
$scopes = ['https://www.googleapis.com/auth/firebase.messaging'];
$credentials = new ServiceAccountCredentials($scopes, $serviceAccount);
$tokenArr = $credentials->fetchAuthToken();

if (empty($tokenArr['access_token'])) {
    throw new Exception('Failed to obtain Firebase access token.');
}

return [
    'access_token' => $tokenArr['access_token'],
    'project_id'   => $serviceAccount['project_id'],
];