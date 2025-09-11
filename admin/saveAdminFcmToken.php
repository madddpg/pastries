<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/database/db_connect.php';
$firebase = require __DIR__ . '/firebase.php';

if (empty($_SESSION['admin_id'])) {
    echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit;
}

$raw = json_decode(file_get_contents('php://input'), true);
$token = trim($raw['token'] ?? '');
if ($token === '') {
    echo json_encode(['success'=>false,'message'=>'Empty token']); exit;
}

$db = new Database();

// Save token in DB
if (!$db->saveAdminFcmToken((int)$_SESSION['admin_id'], $token)) {
    echo json_encode(['success'=>false,'message'=>'Token save failed']); exit;
}

// ✅ No client unsubscribe/subscribe (handled by backend with FCM v1)
echo json_encode(['success'=>true]);
