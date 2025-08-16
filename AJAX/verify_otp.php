<?php
session_start();

// Require that an OTP flow is in progress (adjust based on your app)
if (!isset($_SESSION['pending_user_id'])) {
    header('Location: login.php');
    exit;
}

// Config
$MAX_ATTEMPTS   = 5;
$LOCKOUT_MIN    = 15;

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$success = false;

// Lockout check
$now = time();
if (!empty($_SESSION['otp_locked_until']) && $now < $_SESSION['otp_locked_until']) {
    $remaining = $_SESSION['otp_locked_until'] - $now;
    $errors[] = 'Too many attempts. Try again in ' . ceil($remaining / 60) . ' minute(s).';
}

// Handle submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
    // CSRF
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors[] = 'Invalid request. Please refresh the page and try again.';
    } else {
        // Normalize OTP
        $otp = isset($_POST['otp']) ? preg_replace('/\D+/', '', $_POST['otp']) : '';
        if ($otp === '' || strlen($otp) < 4 || strlen($otp) > 8) { // adjust length as needed
            $errors[] = 'Enter a valid code.';
        }

        // Check expiry data present
        if (empty($_SESSION['otp_expires'])) {
            $errors[] = 'No active code. Please request a new one.';
        } elseif ($now > (int)$_SESSION['otp_expires']) {
            $errors[] = 'The code has expired. Please request a new one.';
        }

        // Attempts tracking
        $_SESSION['otp_attempts'] = $_SESSION['otp_attempts'] ?? 0;

        if (empty($errors)) {
            $verified = false;

            if (!empty($_SESSION['otp_hash'])) {
                // Preferred: OTP hashed when generated (e.g., password_hash)
                $verified = password_verify($otp, $_SESSION['otp_hash']);
            } elseif (isset($_SESSION['otp'])) {
                // Fallback: plain OTP stored in session (less secure)
                $verified = hash_equals((string)$_SESSION['otp'], $otp);
            } else {
                $errors[] = 'No code to verify. Please request a new one.';
            }

            if ($verified) {
                $success = true;

                // Example: mark verification complete (adjust per app)
                $_SESSION['otp_verified'] = true;

                // If you need to mark a user as verified in DB, do it here
                // try {
                //     $pdo = new PDO('mysql:host=localhost;dbname=YOUR_DB;charset=utf8mb4', 'user', 'pass', [
                //         PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                //     ]);
                //     $stmt = $pdo->prepare('UPDATE users SET is_verified = 1 WHERE id = ?');
                //     $stmt->execute([$_SESSION['pending_user_id']]);
                // } catch (Throwable $e) {
                //     // Log error securely
                // }

                // Clean OTP artifacts
                unset($_SESSION['otp'], $_SESSION['otp_hash'], $_SESSION['otp_expires'], $_SESSION['otp_attempts'], $_SESSION['otp_locked_until']);

                // Redirect to next step
                header('Location: dashboard.php');
                exit;
            } else {
                $_SESSION['otp_attempts']++;

                if ($_SESSION['otp_attempts'] >= $MAX_ATTEMPTS) {
                    $_SESSION['otp_locked_until'] = $now + ($LOCKOUT_MIN * 60);
                    $errors[] = 'Too many incorrect attempts. Try again later.';
                } else {
                    $remainingAttempts = $MAX_ATTEMPTS - $_SESSION['otp_attempts'];
                    $errors[] = 'Incorrect code. Attempts left: ' . $remainingAttempts . '.';
                }
            }
        }
    }
}

// Helper for escaping
function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Verify Code</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; margin: 2rem; }
        .card { max-width: 420px; padding: 1.5rem; border: 1px solid #ddd; border-radius: 8px; }
        .error { color: #b00020; margin-bottom: .75rem; }
        .success { color: #056608; margin-bottom: .75rem; }
        label { display: block; margin: .5rem 0 .25rem; }
        input[type="text"] { width: 100%; padding: .5rem; font-size: 1rem; }
        button { margin-top: 1rem; padding: .6rem 1rem; font-size: 1rem; }
        .row { display: flex; gap: .5rem; align-items: center; }
        .muted { color: #666; font-size: .9rem; }
        .actions { display: flex; justify-content: space-between; align-items: center; margin-top: .75rem; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Enter the verification code</h2>

        <?php foreach ($errors as $e): ?>
            <div class="error"><?= h($e) ?></div>
        <?php endforeach; ?>

        <?php if ($success): ?>
            <div class="success">Verified. Redirecting…</div>
        <?php endif; ?>

        <form method="post" action="">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <label for="otp">Code</label>
            <input
                id="otp"
                name="otp"
                type="text"
                inputmode="numeric"
                autocomplete="one-time-code"
                pattern="[0-9]{4,8}"
                minlength="4"
                maxlength="8"
                required
                placeholder="Enter code"
            />
            <div class="actions">
                <span class="muted">
                    <?php if (!empty($_SESSION['otp_expires'])): ?>
                        Expires at: <?= h(date('H:i:s', (int)$_SESSION['otp_expires'])) ?>
                    <?php endif; ?>
                </span>
                <button type="submit">Verify</button>
            </div>
        </form>

        <div class="row" style="margin-top: .75rem;">
            <form method="post" action="resend_otp.php" style="margin:0;">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <button type="submit">Resend code</button>
            </form>
            <a class="muted" href="logout.php">Use a different account</a>
        </div>
    </div>
</body>
</html>