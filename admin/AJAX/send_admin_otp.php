<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../database/db_connect.php';
require_once __DIR__ . '/../../Mailer/class.phpmailer.php';
require_once __DIR__ . '/../../Mailer/class.smtp.php';

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
    // Check if email already exists
    $st = $pdo->prepare('SELECT admin_id FROM admin_users WHERE admin_email = ? LIMIT 1');
    $st->execute([$email]);
    if ($st->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Email is already registered as admin.']); exit;
    }
} catch (Throwable $e) {
    // continue; DB check is best-effort here
}

$now = time();
$cooldown = 30; // seconds
if (!empty($_SESSION['admin_last_otp_sent_at']) && ($now - (int)$_SESSION['admin_last_otp_sent_at']) < $cooldown) {
    $remain = $cooldown - ($now - (int)$_SESSION['admin_last_otp_sent_at']);
    echo json_encode(['success' => false, 'message' => "Please wait {$remain}s before requesting a new code.", 'cooldown' => $remain]); exit;
}

// Save pending admin (hash password now)
$pwdHash = password_hash($password, PASSWORD_BCRYPT);
$_SESSION['pending_admin'] = [
    'username' => $username,
    'admin_email' => $email,
    'password' => $pwdHash,
    'role' => $role,
    'created_at' => $now,
];

// Generate OTP
$otp = random_int(100000, 999999);
$_SESSION['admin_otp_code'] = (string)$otp;
$_SESSION['admin_otp_expires'] = $now + 5 * 60; // 5 minutes
$_SESSION['admin_otp_attempts'] = 0;
unset($_SESSION['admin_otp_locked_until']);

$mail = new PHPMailer;
$mail->CharSet    = 'UTF-8';
$mail->isSMTP();
$mail->Host       = 'smtp.gmail.com';
$mail->Port       = 587;
$mail->SMTPAuth   = true;
$mail->SMTPSecure = 'tls';
$mail->SMTPDebug  = 0; // set to 2 to debug
$mail->Timeout    = 20;
$mail->SMTPOptions = [
    'ssl' => [
        'verify_peer'       => false,
        'verify_peer_name'  => false,
        'allow_self_signed' => true,
        'crypto_method'     => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
    ],
];

$mail->Username = 'cupsandcuddles@gmail.com';
$mail->Password = 'ngjo tavi sdsn zpwq';

$mail->setFrom($mail->Username, 'Cups & Cuddles Admin');
$mail->addReplyTo('no-reply@cupscuddles.local', 'Cups & Cuddles');
$mail->addAddress($email);
$mail->isHTML(true);
$mail->Subject = 'Your Admin Verification Code';
$mail->Body    = '<p>Your admin OTP code is <b>' . htmlspecialchars((string)$otp) . '</b></p><p>This code expires in 5 minutes.</p>';
$mail->AltBody = 'Your admin OTP code is ' . $otp . '. It expires in 5 minutes.';

if ($mail->send()) {
    $_SESSION['admin_last_otp_sent_at'] = $now;
    echo json_encode([
        'success' => true,
        'message' => 'Verification code sent to email.',
        'email'   => $email,
        'expires_at' => $_SESSION['admin_otp_expires'],
        'cooldown'   => $cooldown
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to send verification email. Please try again later.']);
}
