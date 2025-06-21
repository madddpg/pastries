<?php
require_once __DIR__ . '/../database_connections/db_connect.php';
$db = new Database();
$con = $db->opencon();

// Fetch all unique categories from products table, excluding placeholder products
$stmt = $con->prepare("SELECT DISTINCT category FROM products WHERE name != '__placeholder__' ORDER BY category ASC");
$stmt->execute();
$categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Add new category if posted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_category'])) {
    $newCategory = trim($_POST['new_category']);
    if ($newCategory !== '' && !in_array($newCategory, $categories)) {
        // Insert a placeholder product to ensure the category exists in the products table
        $placeholderName = '__placeholder__';
        $stmt = $con->prepare("INSERT INTO products (id, name, category, price, status, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $placeholderId = uniqid('cat_');
        $stmt->execute([$placeholderId, $placeholderName, $newCategory, 0, 'inactive']);
        echo json_encode(['success' => true, 'category' => $newCategory]);
        exit;
    } else {
        echo json_encode(['success' => false, 'error' => 'Category already exists or is empty.']);
        exit;
    }
}

// For GET, return categories as JSON
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode($categories);
    exit;
}
