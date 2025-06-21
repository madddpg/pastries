<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../admin/database_connections/db_connect.php';
$data = json_decode(file_get_contents('php://input'), true);

if (
    !$data ||
    !isset($data['user_id']) ||
    !isset($data['items']) ||
    !isset($data['total']) ||
    !isset($data['method'])
) {
    echo json_encode(['success' => false, 'message' => 'Invalid data', 'debug' => $data]);
    exit;
}

$db = new Database();
$result = $db->createTransaction(
    $data['user_id'],
    $data['items'],
    $data['total'],
    $data['method'],
    isset($data['pickupInfo']) ? $data['pickupInfo'] : null,
    isset($data['deliveryInfo']) ? $data['deliveryInfo'] : null
);
if ($result['success']) {
    echo json_encode(['success' => true, 'transaction_id' => $result['transaction_id']]);
} else {
    echo json_encode(['success' => false, 'message' => $result['message']]);
}