<?php
header('Content-Type: application/json');
require_once __DIR__ . '/database/db_connect.php';

$db = new Database();
$pdo = $db->opencon();

try {
    if (isset($_POST['id'], $_POST['status']) || isset($_POST['product_id'], $_POST['status'])) {
        $id = trim($_POST['product_id'] ?? $_POST['id']); // accept either, prefer product_id
        $status = $_POST['status'] === 'active' ? 'active' : 'inactive';

    $stmt = $pdo->prepare("UPDATE products SET status = ? WHERE product_id = ?");
        $stmt->execute([$status, $id]);

        $rows = $stmt->rowCount();

        if ($rows === 0) {
            // No rows updated, check if product exists
            $check = $pdo->prepare("SELECT COUNT(*) FROM products WHERE product_id = ?");
            $check->execute([$id]);
            if ($check->fetchColumn() > 0) {
                // Product exists, status was already the same
                $rows = 1;
            }
        }

        echo json_encode([
            'success' => true,
            'rows' => $rows
        ]);
        exit();
    }

    echo json_encode([
        'success' => false,
        'message' => 'Invalid request.'
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
exit();
