<?php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../admin/database_connections/db_connect.php';

// Basic validation
$pickup_name = isset($_POST['pickup_name']) ? trim($_POST['pickup_name']) : '';
$pickup_location = isset($_POST['pickup_location']) ? trim($_POST['pickup_location']) : '';
$pickup_time = isset($_POST['pickup_time']) ? trim($_POST['pickup_time']) : '';
$special_instructions = isset($_POST['special_instructions']) ? trim($_POST['special_instructions']) : '';
$cart_items = isset($_POST['cart_items']) ? json_decode($_POST['cart_items'], true) : [];

if ($pickup_name === '' || $pickup_location === '' || $pickup_time === '' || empty($cart_items)) {
    echo json_encode([
        'success' => false,
        'message' => 'Please fill out all required pickup details and have at least one item in your cart.'
    ]);
    exit;
}

$user_id = isset($_SESSION['user']['user_id']) ? intval($_SESSION['user']['user_id']) : 0;
$db = new Database();
$result = $db->createPickupOrder($user_id, $cart_items, $pickup_name, $pickup_location, $pickup_time, $special_instructions);
if ($result['success']) {
    echo json_encode([
        'success' => true,
        'message' => 'Pickup order placed successfully.',
        'reference_number' => $result['reference_number']
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => $result['message']
    ]);
}
