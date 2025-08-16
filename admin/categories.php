<?php
require_once __DIR__ . '/database/db_connect.php';
$db = new Database();
$pdo = $db->opencon();

// Fetch all categories (GET)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare('SELECT id, name FROM categories ORDER BY name ASC');
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($categories);
    exit;
}

// Add new category (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_category'])) {
    $newCategory = trim($_POST['new_category']);
    if ($newCategory !== '') {
        // Check if category already exists
        $checkStmt = $pdo->prepare('SELECT id FROM categories WHERE name = ?');
        $checkStmt->execute([$newCategory]);
        if ($checkStmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Category already exists.']);
            exit;
        }
        $stmt = $pdo->prepare("INSERT INTO categories (name) VALUES (?)");
        $stmt->execute([$newCategory]);
        $newId = $pdo->lastInsertId();
        echo json_encode(['success' => true, 'category' => ['id' => $newId, 'name' => $newCategory]]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Category name cannot be empty.']);
    }
    exit;
}