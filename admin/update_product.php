<?php
header('Content-Type: application/json');
require_once __DIR__ . '/database/db_connect.php';
$db = new Database();
$pdo = $db->opencon();

$product_id = $_POST['product_id'] ?? ($_POST['id'] ?? null);
$new_name = $_POST['new_name'] ?? null;
$new_price = $_POST['new_price'] ?? null; // optional now; we derive from size prices when provided
$new_grande_price = isset($_POST['new_grande_price']) && $_POST['new_grande_price'] !== '' ? floatval($_POST['new_grande_price']) : null;
$new_supreme_price = isset($_POST['new_supreme_price']) && $_POST['new_supreme_price'] !== '' ? floatval($_POST['new_supreme_price']) : null;
$new_category = $_POST['new_category'] ?? null; // can be id or name
$new_status = $_POST['new_status'] ?? null;

if (!$product_id || !$new_name || $new_category === null) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
    exit();
}

try {
    // normalize types
    $product_id = trim((string)$product_id); // product_id is VARCHAR, keep as string
    // Determine base display price: prefer provided grand/supreme; else fallback to new_price; else 0
    $price = 0.0;
    if ($new_grande_price !== null) {
        $price = $new_grande_price;
    } elseif ($new_supreme_price !== null) {
        $price = $new_supreme_price;
    } elseif ($new_price !== null) {
        $price = floatval($new_price);
    }

    // Resolve category -> category_id if necessary
    if (is_numeric($new_category)) {
        $category_id = (int)$new_category;
    } else {
        $stmtCat = $pdo->prepare("SELECT category_id FROM categories WHERE name = ? LIMIT 1");
        $stmtCat->execute([trim($new_category)]);
        $catRow = $stmtCat->fetch(PDO::FETCH_ASSOC);
        if (!$catRow) {
            echo json_encode(['success' => false, 'message' => 'Invalid category.']);
            exit();
        }
        $category_id = (int)$catRow['category_id'];
    }

    // Load current row (static products table)
    $stmtCur = $pdo->prepare("SELECT * FROM products WHERE product_id = ? LIMIT 1");
    $stmtCur->execute([$product_id]);
    $current = $stmtCur->fetch(PDO::FETCH_ASSOC);
    if (!$current) {
        echo json_encode(['success' => false, 'message' => 'Product not found.']);
        exit();
    }

    // Update product fields in place
    if ($new_status !== null && $new_status !== '') {
        $stmt = $pdo->prepare("UPDATE products SET name = ?, category_id = ?, status = ? WHERE product_id = ?");
        $stmt->execute([trim($new_name), $category_id, trim($new_status), $product_id]);
    } else {
        $stmt = $pdo->prepare("UPDATE products SET name = ?, category_id = ? WHERE product_id = ?");
        $stmt->execute([trim($new_name), $category_id, $product_id]);
    }

    // Size price changes (history kept in product_size_prices)
    if ($new_grande_price !== null || $new_supreme_price !== null) {
        try {
            $currPkStmt = $pdo->prepare("SELECT products_pk FROM products WHERE product_id = ? LIMIT 1");
            $currPkStmt->execute([$product_id]);
            $currPk = (int)$currPkStmt->fetchColumn();
            if ($currPk) {
                $tbl = method_exists($db, 'getSizePriceTable') ? $db->getSizePriceTable($pdo) : 'product_size_prices';
                $pdo->beginTransaction();
                if ($new_grande_price !== null) {
                    $pdo->prepare("UPDATE `{$tbl}` SET effective_to = CURRENT_DATE WHERE products_pk = ? AND size = 'grande' AND effective_to IS NULL")
                        ->execute([$currPk]);
                    $pdo->prepare("INSERT INTO `{$tbl}` (products_pk, size, price, effective_from, effective_to, created_at, updated_at) VALUES (?, 'grande', ?, CURRENT_DATE, NULL, NOW(), NOW())")
                        ->execute([$currPk, $new_grande_price]);
                }
                if ($new_supreme_price !== null) {
                    $pdo->prepare("UPDATE `{$tbl}` SET effective_to = CURRENT_DATE WHERE products_pk = ? AND size = 'supreme' AND effective_to IS NULL")
                        ->execute([$currPk]);
                    $pdo->prepare("INSERT INTO `{$tbl}` (products_pk, size, price, effective_from, effective_to, created_at, updated_at) VALUES (?, 'supreme', ?, CURRENT_DATE, NULL, NOW(), NOW())")
                        ->execute([$currPk, $new_supreme_price]);
                }
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
        }
    }

    echo json_encode(['success' => true, 'message' => 'Product updated.']);
    exit();
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
exit();
?>