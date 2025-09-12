<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__.'/database/db_connect.php';

$in = json_decode(file_get_contents('php://input'), true);
$token = trim($in['token'] ?? '');
if ($token === '') { echo json_encode(['success'=>false,'message'=>'empty token']); exit; }

$adminId = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : 0;
if ($adminId <= 0) { echo json_encode(['success'=>false,'message'=>'unauthorized']); exit; }
$db = new Database();
$ok = $db->saveAdminFcmToken($adminId,$token);
$tokens = $ok ? $db->getAdminFcmTokens($adminId) : [];
echo json_encode(['success'=>$ok,'tokens'=>$tokens,'count'=>count($tokens)]);