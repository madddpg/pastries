<?php
header('Content-Type: application/json');
require_once __DIR__ . '/database/db_connect.php';
$db = new Database();
$con = $db->opencon();


$stmt = $con->prepare("SELECT DISTINCT category FROM products WHERE name != '__placeholder__' ORDER BY category ASC");
$stmt->execute();
$categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Add new category if posted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_category'])) {
    $newCategory = trim($_POST['new_category']);
    if ($newCategory !== '' && !in_array($newCategory, $categories)) {
        // No need to insert a placeholder product. Just return success.
        echo json_encode(['success' => true, 'category' => $newCategory]);
        exit;
    } else {
        echo json_encode(['success' => false, 'error' => 'Category already exists or is empty.']);
        exit;
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode($categories);
    exit;
}
