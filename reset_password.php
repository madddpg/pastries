<?php
session_start();
require_once __DIR__ . '/admin/database/db_connect.php';
require_once __DIR__ . '/Mailer/class.phpmailer.php';
require_once __DIR__ . '/Mailer/class.smtp.php';

header('Content-Type: application/json');

$db = new Database();
$pdo = $db->opencon();

function json_fail($message) {
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

function json_ok($payload = []) {
    echo json_encode(array_merge(['success' => true], $payload));
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

if ($method !== 'POST') {
    json_fail('Invalid request method.');
}

if ($action === 'request') {
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_fail('Please provide a valid email.');
    }
    // Do not reveal whether email exists (to prevent user enumeration)
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT user_id, user_FN FROM users WHERE user_email = ? LIMIT 1');
        $st->execute([$email]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            // Pretend success
            $pdo->commit();
            json_ok(['message' => 'If the email exists, a reset link has been sent.']);
        }
        $user_id = (int)$user['user_id'];
        // Invalidate older tokens (optional)
        try {
            $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$user_id]);
        } catch (Throwable $_) {}
        // Create a new token
        $raw = bin2hex(random_bytes(32)); // 64 hex chars
        $token_hash = hash('sha256', $raw);
        $expires_at = date('Y-m-d H:i:s', time() + 60 * 60); // 60 minutes
        $ins = $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)');
        $ins->execute([$user_id, $token_hash, $expires_at]);
        $pdo->commit();

        // Send email
        $mail = new PHPMailer;
        $mail->CharSet    = 'UTF-8';
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->Port       = 587;
        $mail->SMTPAuth   = true;
        $mail->SMTPSecure = 'tls';
        $mail->SMTPDebug = 0;
        $mail->Timeout = 20;
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
                'crypto_method'     => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
            ],
        ];
        $mail->Username = 'cupscuddles@gmail.com';
        $mail->Password = 'ngjo tavi sdsn zpwq';
        $mail->setFrom($mail->Username, 'Cups & Cuddles');
        $mail->addReplyTo('no-reply@cupscuddles.local', 'Cups & Cuddles');
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = 'Reset your Cups & Cuddles password';

        // Build reset link (assume same host). If running locally, adjust accordingly.
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
        $resetUrl = $scheme . '://' . $host . $base . '/reset_password_form.php?token=' . urlencode($raw);

        $safeName = htmlspecialchars($user['user_FN'] ?? 'there', ENT_QUOTES, 'UTF-8');
        $mail->Body = '<p>Hi ' . $safeName . ',</p>' .
                      '<p>We received a request to reset your password. Click the link below to set a new password:</p>' .
                      '<p><a href="' . $resetUrl . '" target="_blank">Reset your password</a></p>' .
                      '<p>This link will expire in 60 minutes. If you did not request this, you can ignore this email.</p>' .
                      '<p>— Cups & Cuddles</p>';
        $mail->AltBody = "Open this link to reset your password: $resetUrl";

        if (!$mail->send()) {
            json_ok(['message' => 'If the email exists, a reset link has been sent.']);
        } else {
            json_ok(['message' => 'If the email exists, a reset link has been sent.']);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        json_ok(['message' => 'If the email exists, a reset link has been sent.']);
    }
}

if ($action === 'reset') {
    $token = isset($_POST['token']) ? trim($_POST['token']) : '';
    $newPassword = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    if ($token === '' || $newPassword === '' || $confirm === '') {
        json_fail('Missing fields.');
    }
    if ($newPassword !== $confirm) {
        json_fail('Passwords do not match.');
    }
    if (strlen($newPassword) < 8) {
        json_fail('Password must be at least 8 characters.');
    }
    $token_hash = hash('sha256', $token);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id, user_id, expires_at, used_at FROM password_resets WHERE token_hash = ? LIMIT 1');
        $st->execute([$token_hash]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->rollBack();
            json_fail('Invalid or expired token.');
        }
        if (!empty($row['used_at'])) {
            $pdo->rollBack();
            json_fail('This link has already been used.');
        }
        if (strtotime($row['expires_at']) < time()) {
            $pdo->rollBack();
            json_fail('This link has expired.');
        }
        $user_id = (int)$row['user_id'];
        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $u = $pdo->prepare('UPDATE users SET user_password = ? WHERE user_id = ?');
        $u->execute([$hash, $user_id]);
        $m = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?');
        $m->execute([(int)$row['id']]);
        $pdo->commit();
        json_ok(['message' => 'Password has been reset. You can now sign in.']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        json_fail('Unable to reset password.');
    }
}

json_fail('Unknown action.');
