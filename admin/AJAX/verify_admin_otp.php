<?php
session_start();
header('Content-Type: application/json');
// Deprecated endpoint: OTP flow removed
http_response_code(410);
echo json_encode([
    'success' => false,
    'message' => 'OTP flow has been removed from admin.',
    'error'   => 'Endpoint deprecated'
]);
exit;
require_once __DIR__ . '/../database/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']); exit;
}

if (!Database::isSuperAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Access denied. Super admin only.']); exit;
}

$MAX_ATTEMPTS = 5;
$LOCKOUT_MIN = 15;

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
$otp  = isset($data['otp']) ? preg_replace('/\D+/', '', (string)$data['otp']) : '';
$now = time();

// Preconditions: pending_admin exists and an OTP has been sent
if (empty($_SESSION['pending_admin']) || empty($_SESSION['admin_otp_code'])) {
    echo json_encode(['success' => false, 'message' => 'No pending admin to verify. Please start again.']); exit;
}

if (!empty($_SESSION['admin_otp_locked_until']) && $now < (int)$_SESSION['admin_otp_locked_until']) {
    $remaining = (int)$_SESSION['admin_otp_locked_until'] - $now;
    echo json_encode(['success' => false, 'message' => 'Too many attempts. Try again in ' . ceil($remaining/60) . ' minute(s).']); exit;
}

if ($otp === '' || strlen($otp) !== 6) {
    echo json_encode(['success' => false, 'message' => 'Enter a valid 6-digit code.']); exit;
}

if (empty($_SESSION['admin_otp_expires']) || $now > (int)$_SESSION['admin_otp_expires']) {
    echo json_encode(['success' => false, 'message' => 'The code has expired. Please request a new one.']); exit;
}

$_SESSION['admin_otp_attempts'] = $_SESSION['admin_otp_attempts'] ?? 0;
$verified = hash_equals((string)$_SESSION['admin_otp_code'], $otp);

if ($verified) {
    try {
        $db = new Database();
        $pdo = $db->opencon();
        $p = $_SESSION['pending_admin'];

        // Insert admin user
        $stmt = $pdo->prepare('INSERT INTO admin_users (username, admin_email, password, role) VALUES (?, ?, ?, ?)');
        $ok = $stmt->execute([$p['username'], $p['admin_email'], $p['password'], $p['role']]);
        if ($ok) {
            $adminId = $pdo->lastInsertId();
            // Cleanup
            unset(
                $_SESSION['pending_admin'],
                $_SESSION['admin_otp_code'],
                $_SESSION['admin_otp_expires'],
                $_SESSION['admin_otp_attempts'],
                $_SESSION['admin_otp_locked_until'],
                $_SESSION['admin_last_otp_sent_at']
            );
            echo json_encode(['success' => true, 'message' => 'Admin created successfully.', 'admin_id' => $adminId]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to create admin.']);
        }
    } catch (Throwable $e) {
        error_log('verify_admin_otp error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error while creating admin.']);
    }
} else {
    // Failed verification
    $_SESSION['admin_otp_attempts']++;
    if ($_SESSION['admin_otp_attempts'] >= $MAX_ATTEMPTS) {
        $_SESSION['admin_otp_locked_until'] = $now + ($LOCKOUT_MIN * 60);
        echo json_encode(['success' => false, 'message' => 'Too many incorrect attempts. Try again later.']);
    } else {
        $remaining = $MAX_ATTEMPTS - (int)$_SESSION['admin_otp_attempts'];
        echo json_encode(['success' => false, 'message' => 'Incorrect code. Attempts left: ' . $remaining . '.']);
    }
}
