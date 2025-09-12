
<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/database/db_connect.php';

$raw = json_decode(file_get_contents('php://input'), true);
$token = trim($raw['token'] ?? '');
if ($token === '') { echo json_encode(['success'=>false,'message'=>'Empty token']); exit; }

$db = new Database();
$adminId = !empty($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : 1; // temp fallback

if (method_exists($db,'saveAdminFcmToken')) {
    if (!$db->saveAdminFcmToken($adminId, $token)) {
        echo json_encode(['success'=>false,'message'=>'Save failed']); exit;
    }
    echo json_encode(['success'=>true]); 
} else {
    echo json_encode(['success'=>false,'message'=>'saveAdminFcmToken() missing']);
}