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
$payment_method = 'cash';


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
        }
    } catch (Exception $e) {
        error_log("pickup_checkout: failed to update payment_method: " . $e->getMessage());
    }

   echo json_encode([
    'success' => true,
    'message' => 'Pickup order placed successfully.',
    'reference_number' => $result['reference_number'],
    'received_payment_method' => 'cash'
]);
    exit;
}

echo json_encode([
    'success' => false,
    'message' => $result['message'] ?? 'Failed to create order.',
    'received_payment_method' => 'cash'
]);

?>