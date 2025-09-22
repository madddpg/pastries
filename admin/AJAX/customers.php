<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../database/db_connect.php';

if (!(Database::isAdmin() || Database::isSuperAdmin())) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$db = new Database();
$pdo = $db->opencon();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    if ($method === 'GET' && ($action === '' || $action === 'list')) {
        $users = $db->fetchAllUsers();
        echo json_encode(['success' => true, 'users' => $users]);
        exit;
    }

    if ($method === 'POST' && ($action === 'toggle' || $action === 'block' || $action === 'unblock')) {
        $user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
        if (!$user_id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing user_id']);
            exit;
        }
        $block = $action === 'block' ? true : ($action === 'unblock' ? false : (isset($_POST['block']) ? (bool)$_POST['block'] : null));
        if (!is_bool($block)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing block flag']);
            exit;
        }
        $ok = $db->setUserBlocked($user_id, $block);
        if (!$ok) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to update user status']);
            exit;
        }
        // Return the updated user row
        $stmt = $pdo->prepare("SELECT user_id, user_FN, user_LN, user_email, COALESCE(is_blocked,0) AS is_blocked, blocked_at FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'user' => $user]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unsupported action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
