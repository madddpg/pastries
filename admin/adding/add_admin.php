<?php
require_once '../database_connections/db_connect.php';
session_start();
echo '<!-- Debug: admin_role=' . (isset($_SESSION['admin_role']) ? $_SESSION['admin_role'] : 'not set') . ' -->';
if (!Database::isSuperAdmin()) {
    die('Access denied. Only super admin can add admins.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin_username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $admin_email = isset($_POST['admin_email']) ? trim($_POST['admin_email']) : '';
    $admin_password = isset($_POST['password']) ? $_POST['password'] : '';

    if ($admin_username === '' || $admin_email === '' || $admin_password === '') {
        echo "Username, email, and password are required.";
        exit;
    }

    // Hash the password for security
    $passwordHash = password_hash($admin_password, PASSWORD_BCRYPT);
    $db = new Database();
    $con = $db->opencon();
    // Insert with default role 'admin'
    $stmt = $con->prepare("INSERT INTO admin_users (username, admin_email, password, role) VALUES (?, ?, ?, 'admin')");
    if ($stmt->execute([$admin_username, $admin_email, $passwordHash])) {
        echo "Admin added successfully.";
    } else {
        echo "Error: " . implode(' ', $stmt->errorInfo());
    }
    exit;
}
echo "Invalid request.";
?>