<?php
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/database/db_connect.php';
$db = new Database();
$con = $db->opencon();

// Require admin
if (!Database::isAdmin() && !Database::isSuperAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid id']);
    exit;
}

// accept explicit active (0 or 1) or toggle if not provided
$requested = null;
if (isset($_POST['active'])) {
    $requested = ($_POST['active'] === '0') ? 0 : 1;
}

try {
    if ($requested === null) {
        $s = $con->prepare("SELECT active FROM promos WHERE id = ? LIMIT 1");
        $s->execute([$id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Promo not found']);
            exit;
        }
        $new = $row['active'] ? 0 : 1;
    } else {
        $new = (int)$requested;
    }

    $u = $con->prepare("UPDATE promos SET active = ? WHERE id = ?");
    $u->execute([$new, $id]);

    echo json_encode(['success' => true, 'id' => $id, 'active' => $new]);
    exit;
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}
?>