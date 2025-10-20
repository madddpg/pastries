<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../admin/database/db_connect.php';
// Load site config
$config = [];
$cfgFile = __DIR__ . '/../config/site.php';
if (file_exists($cfgFile)) { $config = require $cfgFile; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method']);
    exit;
}

$userId = isset($_SESSION['user']['user_id']) ? (int)$_SESSION['user']['user_id'] : null;
$userName = isset($_SESSION['user']['user_FN']) ? trim($_SESSION['user']['user_FN'] . ' ' . ($_SESSION['user']['user_LN'] ?? '')) : '';

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST;
$content = trim($data['content'] ?? '');
$author = trim($data['author'] ?? ($userName ?: 'Anonymous'));

// Enforce sign-in if guest posting disabled
$allowGuest = isset($config['allowGuestInspirations']) ? (bool)$config['allowGuestInspirations'] : true;
if (!$allowGuest && !$userId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Please sign in to post.']);
    exit;
}

if ($content === '') {
    echo json_encode(['success' => false, 'message' => 'Please enter your quote or message.']);
    exit;
}
$author = mb_substr($author, 0, 120);

try {
    $db = new Database();
    $pdo = $db->opencon();
    $st = $pdo->prepare("INSERT INTO inspirations (user_id, author_name, content) VALUES (?,?,?)");
    $ok = $st->execute([$userId, $author, $content]);
    echo json_encode(['success' => $ok === true]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
