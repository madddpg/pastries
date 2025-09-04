<?php
header('Content-Type: application/json');
require_once __DIR__ . '/database/db_connect.php';

$db = new Database();
$pdo = $db->opencon();

try {
    if (isset($_POST['id'], $_POST['status'])) {
        $id = $_POST['id'];
        $status = $_POST['status'] === 'active' ? 'active' : 'inactive';
        $stmt = $pdo->prepare("UPDATE products SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
        echo json_encode(['success' => true]);
        exit();
    }
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
exit();