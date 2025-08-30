<?php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/database/db_connect.php';
$db = new Database();
$con = $db->opencon();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// restrict hard delete to super-admin
if (!Database::isSuperAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden: super-admin required']);
    exit;
}

$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid id']);
    exit;
}

try {
    // delete promo row and remove file if exists
    $stmt = $con->prepare("SELECT image FROM promos WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $del = $con->prepare("DELETE FROM promos WHERE id = ?");
    $del->execute([$id]);
    if ($del->rowCount() > 0) {
        // remove image file (best-effort)
        if (!empty($row['image'])) {
            $path = __DIR__ . '/..' . DIRECTORY_SEPARATOR . $row['image'];
            if (file_exists($path)) @unlink($path);
        }
        echo json_encode(['success' => true, 'message' => 'Promo deleted']);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Promo not found']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
exit;