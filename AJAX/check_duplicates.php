<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../admin/database/db_connect.php';
$db = new Database();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $field = isset($_POST['field']) ? $_POST['field'] : '';
    $value = isset($_POST['value']) ? trim($_POST['value']) : '';

    if (!in_array($field, ['user_FN', 'user_LN', 'user_email'])) {
        echo json_encode(['exists' => false]);
        exit;
    }

    $con = $db->opencon();
    $stmt = $con->prepare("SELECT COUNT(*) FROM users WHERE $field = ?");
    $stmt->execute([$value]);
    $count = $stmt->fetchColumn();

    echo json_encode(['exists' => $count > 0]);
    exit;
} else {
    echo json_encode(['exists' => false]);
    exit;
}
?>
    