<?php
header('Content-Type: application/json');
require_once __DIR__ . '/database/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = new Database();
    $pdo = $db->opencon();

    // Get form data with validation
    $id = trim($_POST['id'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price = isset($_POST['price']) ? floatval($_POST['price']) : 0;
    $category = trim($_POST['category'] ?? '');
    $status = $_POST['status'] ?? 'active';
    $data_type = $_POST['data_type'] ?? '';

    // Validate required fields
    $errors = [];
    if (empty($id)) $errors[] = 'Product ID is required';
    if (empty($name)) $errors[] = 'Product name is required';
    if (empty($description)) $errors[] = 'Description is required';
    if ($price <= 0) $errors[] = 'Valid price is required';
    if (empty($category)) $errors[] = 'Category is required';

    // If there are validation errors, return them
    if (!empty($errors)) {
        echo json_encode([
            'success' => false,
            'message' => 'Missing required fields: ' . implode(', ', $errors)
        ]);
        exit();
    }

    // Look up category ID from name
    $stmt = $pdo->prepare("SELECT id FROM categories WHERE name = ?");
    $stmt->execute([$category]);
    $categoryResult = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$categoryResult) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid category selected'
        ]);
        exit();
    }

    $category_id = $categoryResult['id'];

    // Handle image upload
    $imagePath = '';
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = '../../img/';
        $filename = uniqid() . '_' . basename($_FILES['image']['name']);
        $targetFile = $uploadDir . $filename;

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        if (move_uploaded_file($_FILES['image']['tmp_name'], $targetFile)) {
            $imagePath = 'img/' . $filename;
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to upload image'
            ]);
            exit();
        }
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Image is required'
        ]);
        exit();
    }

    try {
        // Check if product ID already exists
        $checkStmt = $pdo->prepare("SELECT id FROM products WHERE id = ?");
        $checkStmt->execute([$id]);
        if ($checkStmt->fetch()) {
            echo json_encode([
                'success' => false,
                'message' => 'Product ID already exists'
            ]);
            exit();
        }

        // Insert the new product
        $stmt = $pdo->prepare("INSERT INTO products (id, name, description, price, category_id, image, status, data_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $result = $stmt->execute([$id, $name, $description, $price, $category_id, $imagePath, $status, $data_type]);


        // In the success part of your try-catch block
        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Product added successfully!',
                'details' => [
                    'name' => $name,
                    'id' => $id,
                    'category' => $category,
                    'price' => number_format($price, 2)
                ]
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to add product'
            ]);
        }
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
}
