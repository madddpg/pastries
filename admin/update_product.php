<?php
header('Content-Type: application/json');
require_once __DIR__ . '/database/db_connect.php';
$db = new Database();
$pdo = $db->opencon();

$product_id = $_POST['product_id'] ?? ($_POST['id'] ?? null);
$new_name = $_POST['new_name'] ?? null;
$new_price = $_POST['new_price'] ?? null;
$new_category = $_POST['new_category'] ?? null; // can be id or name
$new_status = $_POST['new_status'] ?? null;

if (!$product_id || !$new_name || $new_price === null || $new_category === null) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
    exit();
}

try {
    // normalize types
    $product_id = (int)$product_id;
    $price = floatval($new_price);

    // Resolve category -> category_id if necessary
    if (is_numeric($new_category)) {
        $category_id = (int)$new_category;
    } else {
        $stmtCat = $pdo->prepare("SELECT id FROM categories WHERE name = ? LIMIT 1");
        $stmtCat->execute([trim($new_category)]);
        $catRow = $stmtCat->fetch(PDO::FETCH_ASSOC);
        if (!$catRow) {
            echo json_encode(['success' => false, 'message' => 'Invalid category.']);
            exit();
        }
        $category_id = (int)$catRow['id'];
    }

    // Load current active row (effective_to IS NULL)
    $stmtCur = $pdo->prepare("SELECT * FROM products WHERE product_id = ? AND effective_to IS NULL LIMIT 1");
    $stmtCur->execute([$product_id]);
    $current = $stmtCur->fetch(PDO::FETCH_ASSOC);

    if (!$current) {
        echo json_encode(['success' => false, 'message' => 'Active product row not found.']);
        exit();
    }

    $currentPrice = (float)$current['price'];
    $priceChanged = ($currentPrice !== $price);

    // If price unchanged, update in-place (name/category/status) on active row
    if (!$priceChanged) {
        if ($new_status !== null && $new_status !== '') {
            $stmt = $pdo->prepare("UPDATE products SET name = ?, category_id = ?, status = ? WHERE product_id = ? AND effective_to IS NULL");
            $stmt->execute([trim($new_name), $category_id, trim($new_status), $product_id]);
        } else {
            $stmt = $pdo->prepare("UPDATE products SET name = ?, category_id = ? WHERE product_id = ? AND effective_to IS NULL");
            $stmt->execute([trim($new_name), $category_id, $product_id]);
        }
        echo json_encode(['success' => true, 'message' => 'Product updated.']);
        exit();
    }

    // Price changed: version the row
    $pdo->beginTransaction();
    try {
        // Close current
    $stmtClose = $pdo->prepare("UPDATE products SET effective_to = CURRENT_DATE WHERE product_id = ? AND effective_to IS NULL");
        $stmtClose->execute([$product_id]);

        // Insert new version (copy most fields from current, override changed fields)
    $stmtIns = $pdo->prepare("INSERT INTO products (product_id, name, description, price, category_id, image, status, data_type, effective_from, effective_to) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_DATE, NULL)");
        $stmtIns->execute([
            $product_id,
            trim($new_name),
            $current['description'],
            $price,
            $category_id,
            $current['image'],
            $new_status !== null && $new_status !== '' ? trim($new_status) : $current['status'],
            $current['data_type'] ?? null
        ]);

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Product price versioned successfully.']);
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
exit();
?>