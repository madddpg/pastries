<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../admin/database/db_connect.php';

$order = isset($_GET['order']) && strtolower($_GET['order']) === 'liked' ? 'liked' : 'newest';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

try {
    $db = new Database();
    $pdo = $db->opencon();

    $orderBy = $order === 'liked' ? 'like_count DESC, created_at DESC' : 'created_at DESC';

    $total = (int)$pdo->query("SELECT COUNT(*) FROM inspirations")->fetchColumn();

    $st = $pdo->prepare("SELECT inspiration_id, user_id, author_name, content, like_count, created_at
                          FROM inspirations
                          ORDER BY $orderBy
                          LIMIT :lim OFFSET :off");
    $st->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $st->bindValue(':off', $offset, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $uid = isset($_SESSION['user']['user_id']) ? (int)$_SESSION['user']['user_id'] : 0;
    $likedMap = [];
    if ($uid > 0 && $rows) {
        $ids = array_column($rows, 'inspiration_id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st2 = $pdo->prepare("SELECT inspiration_id FROM inspiration_likes WHERE user_id = ? AND inspiration_id IN ($in)");
        $params = array_merge([$uid], $ids);
        $st2->execute($params);
        foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) { $likedMap[(int)$r['inspiration_id']] = 1; }
    }

    echo json_encode([
        'success' => true,
        'items' => $rows,
        'liked' => $likedMap,
        'total' => $total,
        'page' => $page,
        'perPage' => $perPage
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
