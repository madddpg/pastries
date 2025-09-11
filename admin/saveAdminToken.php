<?php
session_start();
header('Content-Type: application/json');
if (empty($_SESSION['admin_id'])) {
    echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit;
}

require_once __DIR__ . '/database/db_connect.php';
$input = json_decode(file_get_contents('php://input'), true);
$token = trim($input['token'] ?? '');

if (!$token) {
    echo json_encode(['success'=>false,'message'=>'No token']); exit;
}

$db = new Database();
$adminId = $_SESSION['admin_id'];

// Upsert token (if admin already has one, update it)
$stmt = $db->opencon()->prepare("
    INSERT INTO admin_tokens (admin_id, token) VALUES (?, ?)
    ON DUPLICATE KEY UPDATE token = VALUES(token)
");
$stmt->execute([$adminId, $token]);

echo json_encode(['success'=>true,'message'=>'Token saved']);
