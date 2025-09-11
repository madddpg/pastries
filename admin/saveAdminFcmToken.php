<?php
session_start();
header('Content-Type: application/json');
if (empty($_SESSION['admin_id'])) {
    echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit;
}

require_once __DIR__ . '/db_connect.php';
$input = json_decode(file_get_contents('php://input'), true);
$token = trim($input['token'] ?? '');

if (!$token) {
    echo json_encode(['success'=>false,'message'=>'No token']); exit;
}

$db = new Database();
$conn = $db->opencon();
$adminId = $_SESSION['admin_id'];

$stmt = $conn->prepare("
    UPDATE admins 
    SET fcm_token = :token, fcm_token_updated_at = NOW()
    WHERE admin_id = :id
");
$ok = $stmt->execute([
    ':token' => $token,
    ':id'    => $adminId
]);

echo json_encode(['success'=>$ok,'message'=>$ok ? 'Token saved' : 'DB update failed']);

?>
