
<?php
header('Content-Type: application/json');

// ensure session started before role checks
if (session_status() === PHP_SESSION_NONE) session_start();

// correct require path (was missing a slash)
require_once __DIR__ . '/database/db_connect.php';

if (!Database::isSuperAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Access denied. Only super admin can add admins.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin_username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $admin_email = isset($_POST['admin_email']) ? trim($_POST['admin_email']) : '';
    $admin_password = isset($_POST['password']) ? $_POST['password'] : '';

    if ($admin_username === '' || $admin_email === '' || $admin_password === '') {
        echo json_encode(['success' => false, 'message' => 'Username, email, and password are required.']);
        exit;
    }

    // Hash the password for security
    $passwordHash = password_hash($admin_password, PASSWORD_BCRYPT);
    $db = new Database();
    $con = $db->opencon();
    $stmt = $con->prepare("INSERT INTO admin_users (username, admin_email, password, role) VALUES (?, ?, ?, 'admin')");
    if ($stmt->execute([$admin_username, $admin_email, $passwordHash])) {
        echo json_encode(['success' => true, 'message' => 'Admin added successfully.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error: ' . implode(' ', $stmt->errorInfo())]);
    }
    exit;
}
echo json_encode(['success' => false, 'message' => 'Invalid request.']);
exit;
?>