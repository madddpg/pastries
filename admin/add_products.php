<?php
header('Content-Type: application/json');
require_once __DIR__ . '/database/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = new Database();
    $pdo = $db->opencon();
    $id = $_POST['id'] ?? null;
    $name = $_POST['name'] ?? null;
    $description = $_POST['description'] ?? null;
    $price = isset($_POST['price']) ? floatval($_POST['price']) : null;
    $category = $_POST['category'] ?? null;
    $status = $_POST['status'] ?? 'active';
    $data_type = $_POST['data_type'] ?? '';

    // Handle image upload
    $imagePath = '';
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = '../../img/';
        $filename = uniqid() . '_' . basename($_FILES['image']['name']);
        $targetFile = $uploadDir . $filename;
        if (move_uploaded_file($_FILES['image']['tmp_name'], $targetFile)) {
            $imagePath = 'img/' . $filename;
        }
    }

    if (!$id || !$name || !$description || !$price || !$category) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
        exit();
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO products (id, name, description, price, category, image, status, data_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $result = $stmt->execute([$id, $name, $description, $price, $category, $imagePath, $status, $data_type]);
        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Product added successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to add product.']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit();
}
?>
