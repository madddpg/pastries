<?php
header('Content-Type: application/json');
require_once __DIR__ . '/database/db_connect.php';
session_start();

try {
    if (empty($_SESSION['admin_id'])) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
        exit();
    }

    $admin_id = (int)$_SESSION['admin_id'];
    $username = isset($_POST['username']) ? trim((string)$_POST['username']) : '';
    $current  = isset($_POST['current_password']) ? (string)$_POST['current_password'] : '';
    $newPass  = isset($_POST['new_password']) ? (string)$_POST['new_password'] : '';
    $confirm  = isset($_POST['confirm_password']) ? (string)$_POST['confirm_password'] : '';

    if ($username === '') {
        echo json_encode(['success' => false, 'message' => 'Username is required.']);
        exit();
    }

    // Require current password for any changes
    if ($current === '') {
        echo json_encode(['success' => false, 'message' => 'Current password is required.']);
        exit();
    }

    if ($newPass !== '' || $confirm !== '') {
        if (strlen($newPass) < 8) {
            echo json_encode(['success' => false, 'message' => 'New password must be at least 8 characters.']);
            exit();
        }
        if ($newPass !== $confirm) {
            echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
            exit();
        }
    }

    $db = new Database();
    $pdo = $db->opencon();

    // Load existing admin
    $st = $pdo->prepare('SELECT username, password FROM admin_users WHERE admin_id = ? LIMIT 1');
    $st->execute([$admin_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Admin not found.']);
        exit();
    }

    if (!password_verify($current, $row['password'])) {
        echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
        exit();
    }

    // Prepare update
    $fields = ['username' => $username];
    $params = [$username, $admin_id];
    $sql = 'UPDATE admin_users SET username = ? WHERE admin_id = ?';

    if ($newPass !== '') {
        $hash = password_hash($newPass, PASSWORD_DEFAULT);
        $sql = 'UPDATE admin_users SET username = ?, password = ? WHERE admin_id = ?';
        $params = [$username, $hash, $admin_id];
    }

    $upd = $pdo->prepare($sql);
    $ok = $upd->execute($params);

    if ($ok) {
        $_SESSION['admin_username'] = $username;
        echo json_encode(['success' => true, 'message' => 'Profile updated.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'No changes saved.']);
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
