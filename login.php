<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/admin/database/db_connect.php';

$db = new Database();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['user_email']) || !isset($_POST['password'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid request.']);
        exit;
    }
    $user_email = $_POST['user_email'];
    $password = $_POST['password'];

    
    $adminLogin = $db->loginAdmin($user_email, $password);
    if ($adminLogin['success']) {
        echo json_encode([
            'success' => true,
            'redirect' => 'admin/admin.php',
            'fullname' => $adminLogin['admin']['username'],
            'firstName' => explode(' ', $adminLogin['admin']['username'])[0],
            'initials' => strtoupper(substr($adminLogin['admin']['username'], 0, 1)),
            'user_id' => $adminLogin['admin']['admin_id'],
            'is_admin' => true
        ]);
        exit;
    }

  
    $result = $db->loginUser($user_email, $password);
    if ($result['success']) {
        $_SESSION['user'] = $result['user'];
        $user_FN = $result['user']['user_FN'];
        $user_id = $result['user']['user_id'];
        $is_admin = $result['user']['is_admin'];
        echo json_encode([
            'success' => true,
            'redirect' => $is_admin ? 'admin/admin.php' : 'index.php',
            'fullname' => $user_FN,
            'firstName' => explode(' ', $user_FN)[0],
            'initials' => strtoupper(substr($user_FN, 0, 1)),
            'user_id' => $user_id,
            'is_admin' => $is_admin
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => $result['message']]);
    }
    exit;
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}
