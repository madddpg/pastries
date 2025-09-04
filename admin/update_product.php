<?php
header('Content-Type: application/json');
require_once __DIR__ . '/database/db_connect.php';
$db = new Database();
$pdo = $db->opencon();

$product_id = $_POST['product_id'] ?? null;
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

    // Build SQL depending on whether status is provided
    if ($new_status !== null && $new_status !== '') {
        $stmt = $pdo->prepare("UPDATE products SET name = ?, price = ?, category_id = ?, status = ? WHERE id = ?");
        $stmt->execute([trim($new_name), $price, $category_id, trim($new_status), $product_id]);
    } else {
        $stmt = $pdo->prepare("UPDATE products SET name = ?, price = ?, category_id = ? WHERE id = ?");
        $stmt->execute([trim($new_name), $price, $category_id, $product_id]);
    }

    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Product updated successfully.']);
    } else {
        // rowCount == 0 might mean no change or wrong id
        echo json_encode(['success' => false, 'message' => 'No changes made or product not found.']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
exit();
?>