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
    $product_id = trim((string)$product_id); // product_id is VARCHAR, keep as string
    $price = floatval($new_price);

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

    $currentPrice = (float)$current['price'];
    $priceChanged = ($currentPrice !== $price);

    // If price unchanged, update in-place (name/category/status) on active row
    if (!$priceChanged) {
        if ($hadActive) {
            // Update active row in place
            if ($new_status !== null && $new_status !== '') {
                $stmt = $pdo->prepare("UPDATE products SET name = ?, category_id = ?, status = ? WHERE product_id = ? AND effective_to IS NULL");
                $stmt->execute([trim($new_name), $category_id, trim($new_status), $product_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE products SET name = ?, category_id = ? WHERE product_id = ? AND effective_to IS NULL");
                $stmt->execute([trim($new_name), $category_id, $product_id]);
            }
            echo json_encode(['success' => true, 'message' => 'Product updated.']);
            exit();
        } else {
            // No active row: create a new active version (same price, updated fields)
            $pdo->beginTransaction();
            try {
                $stmtIns = $pdo->prepare("INSERT INTO products (product_id, name, description, price, category_id, image, status, data_type, effective_from, effective_to) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_DATE, NULL)");
                $stmtIns->execute([
                    $product_id,
                    trim($new_name),
                    $current['description'],
                    $price,
                    $category_id,
                    $current['image'],
                    $new_status !== null && $new_status !== '' ? trim($new_status) : ($current['status'] ?? 'active'),
                    $current['data_type'] ?? null
                ]);
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Product updated (new active version created).']);
            } catch (PDOException $e) {
                $pdo->rollBack();
                // If duplicate key occurs due to PRIMARY KEY(product_id), surface a clearer message
                $sqlState = $e->getCode();
                $driverCode = method_exists($e, 'errorInfo') && isset($e->errorInfo[1]) ? $e->errorInfo[1] : null;
                if ($sqlState === '23000' || $driverCode === 1062) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Schema limitation: products.product_id is PRIMARY KEY. Price versioning requires multiple rows per product_id. Please alter schema to add a surrogate primary key or composite key.'
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
                }
            }
            exit();
        }
    }

    // Price changed: version the row
    $pdo->beginTransaction();
    try {
        // Close current active row if present
        if ($hadActive) {
            $stmtClose = $pdo->prepare("UPDATE products SET effective_to = CURRENT_DATE WHERE product_id = ? AND effective_to IS NULL");
            $stmtClose->execute([$product_id]);
        }

        // Insert new version (copy most fields from current, override changed fields)
        // If new_status is not explicitly provided, default to 'active' so the new price is visible on the storefront.
        $statusToInsert = ($new_status !== null && $new_status !== '') ? trim($new_status) : 'active';
        $stmtIns = $pdo->prepare("INSERT INTO products (product_id, name, description, price, category_id, image, status, data_type, effective_from, effective_to) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_DATE, NULL)");
        $stmtIns->execute([
            $product_id,
            trim($new_name),
            $current['description'],
            $price,
            $category_id,
            $current['image'],
            $statusToInsert,
            $current['data_type'] ?? null
        ]);

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Product price versioned successfully.']);
    } catch (PDOException $e) {
        $pdo->rollBack();
        // Duplicate key due to PRIMARY KEY(product_id)
        $sqlState = $e->getCode();
        $driverCode = method_exists($e, 'errorInfo') && isset($e->errorInfo[1]) ? $e->errorInfo[1] : null;
        if ($sqlState === '23000' || $driverCode === 1062) {
            echo json_encode([
                'success' => false,
                'message' => 'Schema limitation: products.product_id is PRIMARY KEY. Price versioning cannot insert another row. Please alter schema to support versioning.'
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