<?php
header('Content-Type: application/json');
require_once __DIR__ . '/database/db_connect.php';
$db = new Database();
$pdo = $db->opencon();

$product_id = $_POST['product_id'] ?? null;
$new_name = $_POST['new_name'] ?? null;
$new_price = $_POST['new_price'] ?? null;
$new_category = $_POST['new_category'] ?? null;
$new_status = $_POST['new_status'] ?? null;

if (!$product_id || !$new_name || !$new_price || !$new_category) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
    exit();
}

try {
    if ($new_status !== null) {
        $stmt = $pdo->prepare("UPDATE products SET name = ?, price = ?, category = ?, status = ? WHERE id = ?");
        $stmt->execute([$new_name, $new_price, $new_category, $new_status, $product_id]);
    } else {
        $stmt = $pdo->prepare("UPDATE products SET name = ?, price = ?, category = ? WHERE id = ?");
        $stmt->execute([$new_name, $new_price, $new_category, $product_id]);
    }
    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Product updated successfully.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'No changes made or product not found.']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
exit();
?>
