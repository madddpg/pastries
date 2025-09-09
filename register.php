<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/admin/database/db_connect.php';
require_once __DIR__ . '/Mailer/class.phpmailer.php';
require_once __DIR__ . '/Mailer/class.smtp.php';

$db = new Database();
$pdo = $db->opencon();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = isset($_POST['registerName']) ? trim($_POST['registerName']) : '';
    $lastName = isset($_POST['registerLastName']) ? trim($_POST['registerLastName']) : '';
    $email = isset($_POST['registerEmail']) ? trim($_POST['registerEmail']) : '';
    $password = isset($_POST['registerPassword']) ? $_POST['registerPassword'] : '';
    $confirmPassword = isset($_POST['confirmPassword']) ? $_POST['confirmPassword'] : '';

    if (empty($name) || empty($lastName) || empty($email) || empty($password) || empty($confirmPassword)) {
        echo json_encode(['success' => false, 'message' => 'All fields are required.']);
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
        exit;
    }
    if ($password !== $confirmPassword) {
        echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE user_email = ?");
    $stmt->execute([$email]);
    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => false, 'message' => 'User with this email already exists.']);
        exit;
    }

    // Store pending registration in session
    $_SESSION['pending_registration'] = [
        'user_FN' => $name,
        'user_LN' => $lastName,
        'user_email' => $email,
        'user_password' => password_hash($password, PASSWORD_BCRYPT),
        'created_at' => date('Y-m-d H:i:s')
    ];

    // Generate OTP + session state
    $otp = random_int(100000, 999999);
    $_SESSION['otp'] = (string)$otp;
    $_SESSION['mail'] = $email;
    $_SESSION['otp_expires'] = time() + 5 * 60; // 5 minutes
    $_SESSION['otp_attempts'] = 0;
    unset($_SESSION['otp_locked_until']);

    // Send email via PHPMailer
    $mail = new PHPMailer;
    $mail->CharSet    = 'UTF-8';
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->Port       = 587;
    $mail->SMTPAuth   = true;
    $mail->SMTPSecure = 'tls';

    // Log SMTP debug to Apache error.log
    $mail->SMTPDebug = 2;
    $mail->Debugoutput = function ($str, $level) {
        error_log("PHPMailer [$level]: $str");
    };
    $mail->Timeout = 20;

    // Force TLS 1.2
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
            'crypto_method'     => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
        ],
    ];
//ara
    // Gmail credentials
    $mail->Username = 'ahmadpaguta2005@gmail.com';
    $mail->Password = 'unwr kdad ejcd rysq';

    $mail->setFrom($mail->Username, 'Cups & Cuddles');
    $mail->addReplyTo('no-reply@cupscuddles.local', 'Cups & Cuddles');
    $mail->addAddress($email);

    $mail->isHTML(true);
    $mail->Subject = "Your verification code";
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $mail->Body    = "<p>Hi {$safeName},</p>
                      <p>Your OTP code is <b>{$otp}</b>.</p>
                      <p>This code expires in 5 minutes.</p>
                      <p>If you did not request this email, please ignore it.</p>
                      <p>See you soon!</p>
                      <p>Regards,<br>Cups & Cuddles</p>";
    $mail->AltBody = "Your OTP code is {$otp}. It expires in 5 minutes.";

    if (!$mail->send()) {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to send verification email. Please try again later.',
            'error'   => $mail->ErrorInfo,
        ]);
    } else {
        echo json_encode([
            'success'               => true,
            'message'               => 'Registration initiated, OTP sent to your email.',
            'pending_verification'  => true,
            'email'                 => $email,
            'expires_at'            => $_SESSION['otp_expires']
        ]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}