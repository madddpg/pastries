<?php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/admin/database/db_connect.php';

// Basic validation
$pickup_name = isset($_POST['pickup_name']) ? trim($_POST['pickup_name']) : '';
$pickup_location = isset($_POST['pickup_location']) ? trim($_POST['pickup_location']) : '';
$pickup_time = isset($_POST['pickup_time']) ? trim($_POST['pickup_time']) : '';
$special_instructions = isset($_POST['special_instructions']) ? trim($_POST['special_instructions']) : '';
$cart_items = isset($_POST['cart_items']) ? json_decode($_POST['cart_items'], true) : [];
// Force/accept only gcash; default to gcash when missing
$payment_method = isset($_POST['payment_method']) ? strtolower(trim($_POST['payment_method'])) : 'gcash';
if ($payment_method !== 'gcash') { $payment_method = 'gcash'; }


// Debug logging
error_log("Payment method received: " . $payment_method);

if ($pickup_name === '' || $pickup_location === '' || $pickup_time === '' || empty($cart_items)) {
    echo json_encode([
        'success' => false,
        'message' => 'Please fill out all required pickup details and have at least one item in your cart.',
        'received_payment_method' => $payment_method
    ]);
    exit;
}

// For GCash payments, require an image file to be uploaded
if ($payment_method === 'gcash') {
    $uploadErr = isset($_FILES['gcash_receipt']['error']) ? (int)$_FILES['gcash_receipt']['error'] : UPLOAD_ERR_NO_FILE;
    if ($uploadErr !== UPLOAD_ERR_OK) {
        $msg = 'Please attach a clear screenshot of your GCash payment receipt.';
        if ($uploadErr === UPLOAD_ERR_INI_SIZE || $uploadErr === UPLOAD_ERR_FORM_SIZE) {
            $msg = 'The uploaded file is too large. Please upload a smaller image (try under 5MB).';
        }
        echo json_encode([
            'success' => false,
            'message' => $msg,
            'received_payment_method' => $payment_method,
            'upload_error' => $uploadErr
        ]);
        exit;
    }
}

$user_id = isset($_SESSION['user']['user_id']) ? intval($_SESSION['user']['user_id']) : 0;
$db = new Database();

// Pass payment_method to createPickupOrder
$result = $db->createPickupOrder($user_id, $cart_items, $pickup_name, $pickup_location, $pickup_time, $special_instructions, $payment_method);

if ($result['success'] && !empty($result['reference_number'])) {
    try {
        $pdo = $db->opencon();
        if ($pdo) {
            // Use prepared statement to ensure payment_method is properly escaped
            $stmt = $pdo->prepare("UPDATE `transaction` SET payment_method = ? WHERE reference_number = ?");
            $stmt->execute([$payment_method, $result['reference_number']]);
            
            // Debug log the update
            error_log("Updated payment_method for ref {$result['reference_number']}: $payment_method");
            
            // If gcash, persist the uploaded receipt image
            $uploadErr = isset($_FILES['gcash_receipt']['error']) ? (int)$_FILES['gcash_receipt']['error'] : null;
            if ($payment_method === 'gcash' && isset($_FILES['gcash_receipt']) && is_array($_FILES['gcash_receipt']) && ($uploadErr ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $tmp = $_FILES['gcash_receipt']['tmp_name'];
                $orig = $_FILES['gcash_receipt']['name'] ?? 'receipt';
                $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg','jpeg','png','webp'])) { $ext = 'jpg'; }

                $uploadsDir = __DIR__ . '/uploads/gcash';
                if (!is_dir($uploadsDir)) { @mkdir($uploadsDir, 0775, true); }
                $safeRef = preg_replace('/[^A-Za-z0-9\-_.]/', '_', $result['reference_number']);
                $fileName = $safeRef . '.' . $ext;
                $dest = $uploadsDir . '/' . $fileName;

                if (!@move_uploaded_file($tmp, $dest)) { @copy($tmp, $dest); }

                $relPath = 'uploads/gcash/' . $fileName; // web path relative to site root
                try {
                    $up = $pdo->prepare("UPDATE `transaction` SET gcash_receipt_path = ? WHERE reference_number = ?");
                    $up->execute([$relPath, $result['reference_number']]);
                } catch (Exception $e) {
                    error_log('Failed to update gcash_receipt_path: ' . $e->getMessage());
                    // Fallback to legacy misspelling if present
                    try { $pdo->exec("UPDATE `transaction` SET gcash_reciept_path = '" . addslashes($relPath) . "' WHERE reference_number = '" . addslashes($result['reference_number']) . "'"); }
                    catch (Throwable $_) { /* ignore */ }
                }
                error_log("Saved GCash receipt for {$result['reference_number']} at /" . $relPath);
            } else if ($payment_method === 'gcash') {
                // Log non-OK upload error codes for diagnosis
                error_log('GCash upload missing or errored. $_FILES present: ' . (isset($_FILES['gcash_receipt']) ? 'yes' : 'no') . ', error=' . var_export($uploadErr, true));
            }
        }
    } catch (Exception $e) {
        error_log("pickup_checkout: failed to update payment_method: " . $e->getMessage());
    }

   echo json_encode([
    'success' => true,
    'message' => 'Pickup order placed successfully.',
    'reference_number' => $result['reference_number'],
    'received_payment_method' => $payment_method
]);
    exit;
}

echo json_encode([
    'success' => false,
    'message' => $result['message'] ?? 'Failed to create order.',
    'received_payment_method' => $payment_method
]);

?>