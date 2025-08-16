<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../admin/database/db_connect.php';

// Require that an OTP flow and pending registration are in progress
if (!isset($_SESSION['pending_registration']) || !isset($_SESSION['otp'])) {
    echo json_encode(['success' => false, 'message' => 'No pending registration found.']);
    exit;
}

// Config
$MAX_ATTEMPTS = 5;
$LOCKOUT_MIN = 15;

// Parse request
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
$otp = isset($data['otp']) ? preg_replace('/\D+/', '', $data['otp']) : '';

// Validation
$errors = [];
$now = time();

// Lockout check
if (!empty($_SESSION['otp_locked_until']) && $now < $_SESSION['otp_locked_until']) {
    $remaining = $_SESSION['otp_locked_until'] - $now;
    echo json_encode(['success' => false, 'message' => 'Too many attempts. Try again in ' . ceil($remaining / 60) . ' minute(s).']);
    exit;
}

// Basic validation
if ($otp === '' || strlen($otp) < 6 || strlen($otp) > 6) {
    echo json_encode(['success' => false, 'message' => 'Enter a valid 6-digit code.']);
    exit;
}

// Check expiry
if (empty($_SESSION['otp_expires'])) {
    echo json_encode(['success' => false, 'message' => 'No active code. Please request a new one.']);
    exit;
} elseif ($now > (int)$_SESSION['otp_expires']) {
    echo json_encode(['success' => false, 'message' => 'The code has expired. Please request a new one.']);
    exit;
}

// Attempts tracking
$_SESSION['otp_attempts'] = $_SESSION['otp_attempts'] ?? 0;

// Verify OTP
$verified = hash_equals((string)$_SESSION['otp'], $otp);

if ($verified) {
    // Success! Now register the user
    try {
        $db = new Database();
        $pdo = $db->opencon();
        
        // Insert the user from pending registration
        $stmt = $pdo->prepare("INSERT INTO users (user_FN, user_LN, user_email, user_password) VALUES (?, ?, ?, ?)");
        $inserted = $stmt->execute([
            $_SESSION['pending_registration']['user_FN'],
            $_SESSION['pending_registration']['user_LN'],
            $_SESSION['pending_registration']['user_email'],
            $_SESSION['pending_registration']['user_password']
        ]);
        
        if ($inserted) {
            $userId = $pdo->lastInsertId();
            
            // Set user session
            $_SESSION['user_id'] = $userId;
            $_SESSION['user_email'] = $_SESSION['pending_registration']['user_email'];
            $_SESSION['user_name'] = $_SESSION['pending_registration']['user_FN'] . ' ' . $_SESSION['pending_registration']['user_LN'];
            
            // Clean up OTP and pending registration
            unset(
                $_SESSION['otp'],
                $_SESSION['otp_expires'],
                $_SESSION['otp_attempts'],
                $_SESSION['otp_locked_until'],
                $_SESSION['pending_registration']
            );
            
            echo json_encode([
                'success' => true,
                'message' => 'Registration successful! You are now logged in.',
                'redirect' => 'index.php' // Adjust as needed
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to create account. Please try again.']);
        }
    } catch (Exception $e) {
        error_log("Registration error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'An error occurred during registration.']);
    }
} else {
    // Failed verification
    $_SESSION['otp_attempts']++;
    
    if ($_SESSION['otp_attempts'] >= $MAX_ATTEMPTS) {
        $_SESSION['otp_locked_until'] = $now + ($LOCKOUT_MIN * 60);
        echo json_encode(['success' => false, 'message' => 'Too many incorrect attempts. Try again later.']);
    } else {
        $remainingAttempts = $MAX_ATTEMPTS - $_SESSION['otp_attempts'];
        echo json_encode(['success' => false, 'message' => 'Incorrect code. Attempts left: ' . $remainingAttempts . '.']);
    }
}