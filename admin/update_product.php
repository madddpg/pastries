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

    // Load current active row (effective_to IS NULL)
    $stmtCur = $pdo->prepare("SELECT * FROM products WHERE product_id = ? AND effective_to IS NULL LIMIT 1");
    $stmtCur->execute([$product_id]);
    $current = $stmtCur->fetch(PDO::FETCH_ASSOC);
    $hadActive = (bool)$current;
    if (!$current) {
        // Fallback: use most recent version (even if closed)
        $stmtLast = $pdo->prepare("SELECT * FROM products WHERE product_id = ? ORDER BY (effective_to IS NULL) DESC, effective_from DESC, created_at DESC LIMIT 1");
        $stmtLast->execute([$product_id]);
        $current = $stmtLast->fetch(PDO::FETCH_ASSOC);
        if (!$current) {
            echo json_encode(['success' => false, 'message' => 'Product not found.']);
            exit();
        }
    }

    // Always update active product fields in place when present; otherwise create a new active row without price column
    if ($hadActive) {
        if ($new_status !== null && $new_status !== '') {
            $stmt = $pdo->prepare("UPDATE products SET name = ?, category_id = ?, status = ? WHERE product_id = ? AND effective_to IS NULL");
            $stmt->execute([trim($new_name), $category_id, trim($new_status), $product_id]);
        } else {
            $stmt = $pdo->prepare("UPDATE products SET name = ?, category_id = ? WHERE product_id = ? AND effective_to IS NULL");
            $stmt->execute([trim($new_name), $category_id, $product_id]);
        }

        // Size price changes
        if ($new_grande_price !== null || $new_supreme_price !== null) {
            try {
                $currPkStmt = $pdo->prepare("SELECT products_pk FROM products WHERE product_id = ? AND effective_to IS NULL LIMIT 1");
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
    }

    // No active row: create a new active version (without price column)
    $pdo->beginTransaction();
    try {
        $stmtIns = $pdo->prepare("INSERT INTO products (product_id, name, description, category_id, image, status, data_type, effective_from, effective_to) VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_DATE, NULL)");
        $stmtIns->execute([
            $product_id,
            trim($new_name),
            $current['description'],
            $category_id,
            $current['image'],
            $new_status !== null && $new_status !== '' ? trim($new_status) : ($current['status'] ?? 'active'),
            $current['data_type'] ?? null
        ]);

        $new_products_pk = (int)$pdo->lastInsertId();
        if ($new_products_pk > 0) {
            try {
                // Copy any currently active size prices from the most recent version
                $prevStmt = $pdo->prepare("SELECT products_pk FROM products WHERE product_id = ? ORDER BY (effective_to IS NULL) DESC, effective_from DESC, created_at DESC LIMIT 1 OFFSET 1");
                $prevStmt->execute([$product_id]);
                $prevPk = $prevStmt->fetchColumn();
                if ($prevPk) {
                    $tbl = method_exists($db, 'getSizePriceTable') ? $db->getSizePriceTable($pdo) : 'product_size_prices';
                    $copySql = "INSERT INTO `{$tbl}` (products_pk, size, price, effective_from, effective_to, created_at, updated_at)
                                    SELECT ?, size, price, CURRENT_DATE, NULL, NOW(), NOW()
                                      FROM `{$tbl}`
                                     WHERE products_pk = ? AND effective_to IS NULL";
                    $pdo->prepare($copySql)->execute([$new_products_pk, $prevPk]);
                }
            } catch (Throwable $e) { /* ignore */ }

            // Apply provided size prices on the new active version
            try {
                $tbl = method_exists($db, 'getSizePriceTable') ? $db->getSizePriceTable($pdo) : 'product_size_prices';
                if ($new_grande_price !== null || $new_supreme_price !== null) {
                    if ($new_grande_price !== null) {
                        $pdo->prepare("INSERT INTO `{$tbl}` (products_pk, size, price, effective_from, effective_to, created_at, updated_at) VALUES (?, 'grande', ?, CURRENT_DATE, NULL, NOW(), NOW())")
                            ->execute([$new_products_pk, $new_grande_price]);
                    }
                    if ($new_supreme_price !== null) {
                        $pdo->prepare("INSERT INTO `{$tbl}` (products_pk, size, price, effective_from, effective_to, created_at, updated_at) VALUES (?, 'supreme', ?, CURRENT_DATE, NULL, NOW(), NOW())")
                            ->execute([$new_products_pk, $new_supreme_price]);
                    }
                }
            } catch (Throwable $e) { /* ignore */ }
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Product updated (new active version created).']);
    } catch (PDOException $e) {
        $pdo->rollBack();
        $sqlState = $e->getCode();
        $driverCode = method_exists($e, 'errorInfo') && isset($e->errorInfo[1]) ? $e->errorInfo[1] : null;
        if ($sqlState === '23000' || $driverCode === 1062) {
            echo json_encode([
                'success' => false,
                'message' => 'Schema limitation: products.product_id is PRIMARY KEY. Versioning cannot insert another row. Please alter schema to support multiple versions per product_id.'
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
exit();
?>