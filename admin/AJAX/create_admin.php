<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../database/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']); exit;
}

if (!Database::isSuperAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Access denied. Super admin only.']); exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

$username = isset($data['username']) ? trim((string)$data['username']) : '';
$email    = isset($data['email']) ? trim((string)$data['email']) : '';
$role     = isset($data['role']) ? trim((string)$data['role']) : 'admin';
$password = isset($data['password']) ? (string)$data['password'] : '';
$confirm  = isset($data['confirm']) ? (string)$data['confirm'] : '';

if ($username === '' || $email === '' || $password === '' || $confirm === '') {
    echo json_encode(['success' => false, 'message' => 'All fields are required.']); exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Enter a valid email.']); exit;
}
if (!in_array($role, ['admin','super_admin'], true)) {
    $role = 'admin';
}
if ($password !== $confirm) {
    echo json_encode(['success' => false, 'message' => 'Passwords do not match.']); exit;
}

try {
    $db = new Database();
    $pdo = $db->opencon();

    // Uniqueness checks
    $st = $pdo->prepare('SELECT 1 FROM admin_users WHERE admin_email = ? LIMIT 1');
    $st->execute([$email]);
    if ($st->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Email is already registered as admin.']); exit;
    }

    $stU = $pdo->prepare('SELECT 1 FROM admin_users WHERE username = ? LIMIT 1');
    $stU->execute([$username]);
    if ($stU->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Username is already taken.']); exit;
    }

    $pwdHash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare('INSERT INTO admin_users (username, admin_email, password, role) VALUES (?, ?, ?, ?)');
    $ok = $stmt->execute([$username, $email, $pwdHash, $role]);
    if ($ok) {
        echo json_encode(['success' => true, 'message' => 'Admin created successfully.', 'admin_id' => $pdo->lastInsertId()]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to create admin.']);
    }
} catch (Throwable $e) {
    error_log('create_admin error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error while creating admin.']);
}
exit;
?>
<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../database/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']); exit;
}

if (!Database::isSuperAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Access denied. Super admin only.']); exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

$username = isset($data['username']) ? trim((string)$data['username']) : '';
$email    = isset($data['email']) ? trim((string)$data['email']) : '';
$role     = isset($data['role']) ? trim((string)$data['role']) : 'admin';
$password = isset($data['password']) ? (string)$data['password'] : '';
$confirm  = isset($data['confirm']) ? (string)$data['confirm'] : '';

if ($username === '' || $email === '' || $password === '' || $confirm === '') {
    echo json_encode(['success' => false, 'message' => 'All fields are required.']); exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Enter a valid email.']); exit;
}
if (!in_array($role, ['admin','super_admin'], true)) {
    $role = 'admin';
}
if ($password !== $confirm) {
    echo json_encode(['success' => false, 'message' => 'Passwords do not match.']); exit;
}

try {
    $db = new Database();
    $pdo = $db->opencon();
    // Uniqueness checks
    $st = $pdo->prepare('SELECT admin_id FROM admin_users WHERE admin_email = ? LIMIT 1');
    $st->execute([$email]);
    if ($st->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Email is already registered as admin.']); exit;
    }

    // Optional: also ensure username is unique if schema allows duplicates otherwise
    try {
        $st2 = $pdo->prepare('SELECT admin_id FROM admin_users WHERE username = ? LIMIT 1');
        $st2->execute([$username]);
        if ($st2->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Username is already taken.']); exit;
        }
    } catch (Throwable $e) {
        // If username uniqueness is not enforced in DB, ignore
    }

    $pwdHash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare('INSERT INTO admin_users (username, admin_email, password, role) VALUES (?, ?, ?, ?)');
    $ok = $stmt->execute([$username, $email, $pwdHash, $role]);
    if ($ok) {
        $adminId = $pdo->lastInsertId();
        echo json_encode(['success' => true, 'message' => 'Admin created successfully.', 'admin_id' => $adminId]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to create admin.']);
    }
} catch (Throwable $e) {
    error_log('create_admin error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error while creating admin.']);
}
