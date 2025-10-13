<?php
// User-initiated order cancellation (only when status is 'pending')
// POST: id = transac_id (int)
// Response: JSON { success: bool, message: string }

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (!isset($_SESSION['user']) || !isset($_SESSION['user']['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!isset($_POST['id']) || !ctype_digit((string)$_POST['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid order id']);
    exit;
}

require_once __DIR__ . '/../admin/database/db_connect.php';

try {
    $db = new Database();
    $pdo = $db->opencon();
    $user_id = (int)$_SESSION['user']['user_id'];
    $id = (int)$_POST['id'];

    // Ensure the order belongs to the user and is still pending
    $check = $pdo->prepare("SELECT status FROM `transaction` WHERE transac_id = ? AND user_id = ? LIMIT 1");
    $check->execute([$id, $user_id]);
    $row = $check->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit;
    }
    $status = strtolower((string)$row['status']);
    if ($status !== 'pending') {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Only pending orders can be cancelled']);
        exit;
    }

    // Cancel the order and set notified=0 to trigger user notice elsewhere
    $upd = $pdo->prepare("UPDATE `transaction` SET status = 'cancelled', notified = 0 WHERE transac_id = ? AND user_id = ? AND status = 'pending'");
    $upd->execute([$id, $user_id]);
    if ($upd->rowCount() < 1) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Unable to cancel order']);
        exit;
    }

    echo json_encode(['success' => true, 'message' => 'Order cancelled']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
