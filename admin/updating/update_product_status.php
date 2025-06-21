<?php
require_once '../database_connections/db_connect.php';
// Use PDO connection
$db = new Database();
$pdo = $db->opencon();
if (isset($_POST['id'], $_POST['status'])) {
    $id = $_POST['id'];
    $status = $_POST['status'] === 'active' ? 'active' : 'inactive';
    $stmt = $pdo->prepare("UPDATE products SET status=? WHERE id=?");
    $stmt->execute([$status, $id]);
    echo json_encode(['success' => true]);
    exit();
}
echo json_encode(['success' => false]);
exit();
