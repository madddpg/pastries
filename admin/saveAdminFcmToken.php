<?php
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['admin_id'])) {
    echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit;
}

require_once __DIR__ . '/database/db_connect.php';

$input = json_decode(file_get_contents('php://input'), true);
$token = trim($input['token'] ?? '');

if ($token === '') {
    echo json_encode(['success'=>false,'message'=>'No token']); exit;
}

$db = new Database();
if (!$db->saveAdminFcmToken((int)$_SESSION['admin_id'], $token)) {
    echo json_encode(['success'=>false,'message'=>'DB update failed']); exit;
}

echo json_encode(['success'=>true,'message'=>'Token saved']);