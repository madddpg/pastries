<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../admin/database/db_connect.php';

if (!isset($_SESSION['user']['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Please sign in to like posts.']);
    exit;
}

$userId = (int)$_SESSION['user']['user_id'];
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
$inspirationId = isset($data['id']) ? (int)$data['id'] : 0;
if ($inspirationId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid post.']);
    exit;
}

try {
    $db = new Database();
    $pdo = $db->opencon();

    // Toggle like
    $st = $pdo->prepare("SELECT 1 FROM inspiration_likes WHERE inspiration_id = ? AND user_id = ? LIMIT 1");
    $st->execute([$inspirationId, $userId]);
    $liked = (bool)$st->fetchColumn();

    if ($liked) {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM inspiration_likes WHERE inspiration_id = ? AND user_id = ?")
            ->execute([$inspirationId, $userId]);
        $pdo->prepare("UPDATE inspirations SET like_count = GREATEST(like_count - 1, 0) WHERE inspiration_id = ?")
            ->execute([$inspirationId]);
        $pdo->commit();
        echo json_encode(['success' => true, 'liked' => false]);
    } else {
        $pdo->beginTransaction();
        $pdo->prepare("INSERT IGNORE INTO inspiration_likes (inspiration_id, user_id) VALUES (?, ?)")
            ->execute([$inspirationId, $userId]);
        $pdo->prepare("UPDATE inspirations SET like_count = like_count + 1 WHERE inspiration_id = ?")
            ->execute([$inspirationId]);
        $pdo->commit();
        echo json_encode(['success' => true, 'liked' => true]);
    }
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) { $pdo->rollBack(); }
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
