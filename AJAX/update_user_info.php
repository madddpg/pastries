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
// Email cannot be changed via Edit Profile; always use the one from session
$new_email = isset($_SESSION['user']['user_email']) ? trim($_SESSION['user']['user_email']) : '';
$new_password = isset($data['user_password']) ? $data['user_password'] : null;

$result = $db->updateUserInfo($user_id, $new_FN, $new_LN, $new_email, $new_password);
if ($result['success']) {
    $_SESSION['user']['user_FN'] = $new_FN;
    $_SESSION['user']['user_LN'] = $new_LN;
    // Do not change email in session here; it remains the same
}
echo json_encode($result);
