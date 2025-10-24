<?php
header('Content-Type: application/json');
require_once __DIR__ . '/database/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = new Database();
    $pdo = $db->opencon();

    // Get form data with validation
    $id = trim($_POST['product_id'] ?? ($_POST['id'] ?? ''));
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    // Price provided from modal; treat as Grande for drinks
    $price = isset($_POST['price']) && $_POST['price'] !== '' ? round((float)$_POST['price'], 2) : null;
    $category = trim($_POST['category'] ?? '');
    $status = $_POST['status'] ?? 'active';
    $data_type = $_POST['data_type'] ?? '';

    // Validate required fields
    $errors = [];
    if (empty($id)) $errors[] = 'Product ID is required';
    if (empty($name)) $errors[] = 'Product name is required';
    if (empty($description)) $errors[] = 'Description is required';
    // price field is no longer required on products table
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
    $stmt = $pdo->prepare("SELECT category_id FROM categories WHERE name = ?");
    $stmt->execute([$category]);
    $categoryResult = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$categoryResult) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid category selected'
        ]);
        exit();
    }

    $category_id = $categoryResult['category_id'];

     $imagePath = '';
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        // Save to /cupscuddles/img (not two levels up)
        $uploadDir = dirname(__DIR__) . '/img/'; // was '../../img/'

        $filename = uniqid() . '_' . basename($_FILES['image']['name']);
        $targetFile = $uploadDir . $filename;

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        if (move_uploaded_file($_FILES['image']['tmp_name'], $targetFile)) {
            // Store relative path used by the site
            $imagePath = 'img/' . $filename;
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to upload image']);
            exit();
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Image is required']);
        exit();
    }

    // Ensure pastries get a proper data_type by default
    if (empty($data_type)) {
        if (strtolower($category) === 'pastries' || (isset($category_id) && (int)$category_id === 7)) {
            $data_type = 'pastries';
        } else {
            $data_type = 'cold';
        }
    }

    try {
        // Check if product ID already exists
        $checkStmt = $pdo->prepare("SELECT product_id FROM products WHERE product_id = ?");
        $checkStmt->execute([$id]);
        if ($checkStmt->fetch()) {
            echo json_encode([
                'success' => false,
                'message' => 'Product ID already exists'
            ]);
            exit();
        }

    // Insert the new product (no base price column)
    $stmt = $pdo->prepare("INSERT INTO products (product_id, name, description, category_id, image, status, data_type) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $result = $stmt->execute([$id, $name, $description, $category_id, $imagePath, $status, $data_type]);

        // Optionally store explicit size prices if provided (Grande only). For drinks, map 'price' to Grande.
        if ($result) {
            $products_pk = (int)$pdo->lastInsertId();
            // Prefer explicit price_grande; otherwise use 'price' field
            $price_grande  = isset($_POST['price_grande']) && $_POST['price_grande'] !== '' ? round((float)$_POST['price_grande'], 2) : ($price !== null ? $price : null);
            $is_pastry = strtolower($data_type) === 'pastries';
            if ($products_pk > 0 && $price_grande !== null && !$is_pastry) {
                $tbl = method_exists($db, 'getSizePriceTable') ? $db->getSizePriceTable($pdo) : 'product_size_prices';
                $pdo->prepare("INSERT INTO `{$tbl}` (products_pk, size, price, effective_from, effective_to, created_at, updated_at) VALUES (?, 'grande', ?, CURRENT_DATE, NULL, NOW(), NOW())")
                    ->execute([$products_pk, $price_grande]);
            }
        }


        // In the success part of your try-catch block
        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Product added successfully!',
                'details' => [
                    'name' => $name,
                    'id' => $id,
                    'category' => $category,
                    'price' => isset($price_grande) && $price_grande !== null ? number_format($price_grande, 2) : (isset($price) && $price !== null ? number_format($price, 2) : '0.00')
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
