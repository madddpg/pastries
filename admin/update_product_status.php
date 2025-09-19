<?php
header('Content-Type: application/json');
require_once __DIR__ . '/database/db_connect.php';

$db = new Database();
$pdo = $db->opencon();

try {
    if (isset($_POST['id'], $_POST['status'])) {
        $id = trim($_POST['id']); // trim spaces just in case
        $status = $_POST['status'] === 'active' ? 'active' : 'inactive';

    $stmt = $pdo->prepare("UPDATE products SET status = ? WHERE id = ? AND effective_to IS NULL");
        $stmt->execute([$status, $id]);

        $rows = $stmt->rowCount();

        if ($rows === 0) {
            // No rows updated, check if product exists
            $check = $pdo->prepare("SELECT COUNT(*) FROM products WHERE id = ?");
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
