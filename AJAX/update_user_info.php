<?php
session_start();
require_once __DIR__ . '/../admin/database/db_connect.php';
header('Content-Type: application/json');
$db = new Database();

if (!isset($_SESSION['user']['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

$user_id = $_SESSION['user']['user_id'];
$rawInput = file_get_contents('php://input');
error_log('RAW JSON: ' . $rawInput);

$data = json_decode($rawInput, true);
$new_FN = isset($data['user_FN']) ? trim($data['user_FN']) : '';
$new_LN = isset($data['user_LN']) ? trim($data['user_LN']) : '';
$new_email = isset($data['user_email']) ? trim($data['user_email']) : '';
$new_password = isset($data['user_password']) ? $data['user_password'] : null;

$result = $db->updateUserInfo($user_id, $new_FN, $new_LN, $new_email, $new_password);
if ($result['success']) {
    $_SESSION['user']['user_FN'] = $new_FN;
    $_SESSION['user']['user_LN'] = $new_LN;
    $_SESSION['user']['user_email'] = $new_email;
}
echo json_encode($result);
