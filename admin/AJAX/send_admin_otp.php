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

// Simple TCP connectivity probe to provide clearer diagnostics
function probePort($host, $port, $timeout = 5) {
    $errno = 0; $errstr = '';
    $fp = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout);
    if ($fp) {
        @fclose($fp);
        return [true, 'ok'];
    }
    return [false, trim("{$errno} {$errstr}")];
}

// Helper to configure and send email; returns [success, error, meta]
function sendAdminOtpMail($email, $otp, $port, $secure) {
    // Ensure a local log file exists for SMTP debug
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $debugFile = $logDir . '/mail_debug.log';

    $mail = new PHPMailer;
    $mail->CharSet    = 'UTF-8';
    $mail->isSMTP();
    // Use hostname to preserve SNI; rely on system DNS to pick IPv4 if needed
    $mail->Host       = 'smtp.gmail.com';
    $mail->Port       = (int)$port;
    $mail->SMTPAuth   = true;
    $mail->SMTPSecure = $secure; // 'tls' for 587, 'ssl' for 465
    $mail->SMTPAutoTLS = true;
    $mail->SMTPDebug  = 4; // most verbose debug for troubleshooting
    // Capture debug in memory, append to a local log file, and mirror to PHP error_log
    $debugLines = [];
    $mail->Debugoutput = function ($str, $level) use ($debugFile, &$debugLines) {
        $line = date('Y-m-d H:i:s') . " PHPMailer [admin][$level]: " . $str;
        $debugLines[] = $line;
        @error_log($line . PHP_EOL, 3, $debugFile);
        @error_log($line);
    };
    // Set EHLO/HELO hostname (helps some SMTP servers)
    $mail->Hostname = (string) (gethostname() ?: 'localhost');
    $mail->Helo     = $mail->Hostname;
    $mail->Timeout  = 30; // Increase timeout for slow connections
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
            // Force TLS 1.2+ and disable weak ciphers
            'crypto_method'     => STREAM_CRYPTO_METHOD_TLS_CLIENT,
            'ciphers'          => 'DEFAULT:!DH',
            // Ensure SNI peer name is set (critical for Gmail)
            'peer_name'         => 'smtp.gmail.com',
            'SNI_enabled'       => true,
            // Disable compression to avoid CRIME attacks
            'disable_compression' => true,
        ],
        'socket' => [
            // Prefer IPv4 by binding to IPv4 wildcard
            'bindto' => '0.0.0.0:0',
        ],
    ];

    // Gmail credentials (App Password recommended)
    $mail->Username = 'cupsandcuddles@gmail.com';
    $mail->Password = 'ngjo tavi sdsn zpwq';

    $mail->setFrom($mail->Username, 'Cups & Cuddles Admin');
    $mail->addReplyTo('no-reply@cupscuddles.local', 'Cups & Cuddles');
    $mail->addAddress($email);
    $mail->isHTML(true);
    $mail->Subject = 'Your Admin Verification Code';
    $mail->Body    = '<p>Your admin OTP code is <b>' . htmlspecialchars((string)$otp) . '</b></p><p>This code expires in 5 minutes.</p>';
    $mail->AltBody = 'Your admin OTP code is ' . $otp . '. It expires in 5 minutes.';

    // Attempt to send with retry logic
    $maxRetries = 2;
    for ($retry = 0; $retry <= $maxRetries; $retry++) {
        try {
            if ($mail->send()) {
                return [true, '', ['debug_log' => $debugFile, 'retry' => $retry]];
            }
        } catch (Throwable $e) {
            @error_log("PHPMailer send attempt " . ($retry + 1) . " failed: " . $e->getMessage());
            if ($retry < $maxRetries) {
                sleep(1); // Brief pause before retry
                continue;
            }
        }
        
        // If we reach here, the send failed
        if ($retry < $maxRetries) {
            sleep(1);
            continue;
        }
    }
    
    // Collect detailed error info after all retries failed
    $smtpErr = null;
    try {
        $smtp = $mail->getSMTPInstance();
        if ($smtp && method_exists($smtp, 'getError')) {
            $smtpErr = $smtp->getError();
        }
    } catch (Throwable $e) {
        $smtpErr = ['exception' => $e->getMessage()];
    }
    // Attach last 50 debug lines to help triage
    $debugTail = [];
    if (!empty($debugLines)) {
        $debugTail = array_slice($debugLines, -50);
    }

    return [false, $mail->ErrorInfo, [
        'debug_log' => $debugFile, 
        'smtp_error' => $smtpErr,
        'retries' => $maxRetries + 1,
        'debug_tail' => $debugTail
    ]];
}

// Attempt with STARTTLS 587 first, then fallback to SSL 465
// Preflight connectivity probes (to help distinguish auth vs network)
list($probe587Ok, $probe587Err) = probePort('smtp.gmail.com', 587, 5);
list($probe465Ok, $probe465Err) = probePort('smtp.gmail.com', 465, 5);

list($ok587, $err587, $meta587) = sendAdminOtpMail($email, $otp, 587, 'tls');
if ($ok587) {
    $_SESSION['admin_last_otp_sent_at'] = $now;
    echo json_encode([
        'success' => true,
        'message' => 'Verification code sent to email.',
        'email'   => $email,
        'expires_at' => $_SESSION['admin_otp_expires'],
        'cooldown'   => $cooldown,
        'transport'  => 'tls:587',
        'debug_log'  => isset($meta587['debug_log']) ? basename($meta587['debug_log']) : null,
        'debug_tail' => isset($meta587['debug_tail']) ? $meta587['debug_tail'] : null,
        'retry'      => isset($meta587['retry']) ? $meta587['retry'] : null
    ]);
    exit;
}

list($ok465, $err465, $meta465) = sendAdminOtpMail($email, $otp, 465, 'ssl');
if ($ok465) {
    $_SESSION['admin_last_otp_sent_at'] = $now;
    echo json_encode([
        'success' => true,
        'message' => 'Verification code sent to email.',
        'email'   => $email,
        'expires_at' => $_SESSION['admin_otp_expires'],
        'cooldown'   => $cooldown,
        'transport'  => 'ssl:465',
        'note'       => 'Delivered using SSL:465 fallback',
        'debug_log'  => isset($meta465['debug_log']) ? basename($meta465['debug_log']) : null,
        'debug_tail' => isset($meta465['debug_tail']) ? $meta465['debug_tail'] : null,
        'retry'      => isset($meta465['retry']) ? $meta465['retry'] : null
    ]);
    exit;
}

echo json_encode([
    'success' => false,
    'message' => 'Failed to send verification email. Please try again later.',
    'error'   => '587: ' . $err587 . ' | 465: ' . $err465,
    'connectivity' => [
        '587' => $probe587Ok ? 'ok' : $probe587Err,
        '465' => $probe465Ok ? 'ok' : $probe465Err,
    ],
    'smtp_error' => [
        '587' => isset($meta587['smtp_error']) ? $meta587['smtp_error'] : null,
        '465' => isset($meta465['smtp_error']) ? $meta465['smtp_error'] : null,
    ],
    'debug_tail' => [
        '587' => isset($meta587['debug_tail']) ? $meta587['debug_tail'] : null,
        '465' => isset($meta465['debug_tail']) ? $meta465['debug_tail'] : null,
    ],
    'env' => [
        'php' => PHP_VERSION,
        'openssl' => defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : null,
    ],
    'hint'    => 'If connectivity is failing, allow outbound SMTP on ports 587/465 in Windows Firewall/AV and your router/ISP. If connectivity is ok but still failing, double-check Gmail account 2FA + App Password, and ensure TLS 1.2 is supported in your PHP/OpenSSL stack.'
]);
