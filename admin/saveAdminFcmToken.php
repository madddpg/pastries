
<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/database/db_connect.php';

$raw = json_decode(file_get_contents('php://input'), true);
$token = trim($raw['token'] ?? '');
if ($token === '') { echo json_encode(['success'=>false,'message'=>'Empty token']); exit; }

$db = new Database();

// Use logged in admin_id or fallback (must exist)
$adminId = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : 1;

if (!$db->saveAdminFcmToken($adminId, $token)) {
    echo json_encode(['success'=>false,'message'=>'DB save failed']); exit;
}

echo json_encode(['success'=>true]);