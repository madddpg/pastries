<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/admin/database/db_connect.php';

$db = new Database();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = isset($_POST['registerName']) ? trim($_POST['registerName']) : '';
    $lastName = isset($_POST['registerLastName']) ? trim($_POST['registerLastName']) : '';
    $email = isset($_POST['registerEmail']) ? trim($_POST['registerEmail']) : '';
    $password = isset($_POST['registerPassword']) ? $_POST['registerPassword'] : '';
    $confirmPassword = isset($_POST['confirmPassword']) ? $_POST['confirmPassword'] : '';
    $result = $db->registerUser($name, $lastName, $email, $password, $confirmPassword, null);
    echo json_encode($result);
    exit;
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}
?>

