<?php
header('Content-Type: application/json');

session_start();
require_once __DIR__ . '/database/db_connect.php';
$db = new Database();
$pdo = $db->opencon();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Backward compatibility: accept 'id' as an alias for 'location_id'
    if (isset($_POST['id']) && !isset($_POST['location_id'])) {
        $_POST['location_id'] = $_POST['id'];
    }
    // Add Location
    if (isset($_POST['name']) && !isset($_POST['action'])) {
        $name = $_POST['name'];
        $status = isset($_POST['status']) ? $_POST['status'] : 'open';
        $imagePath = '';
        $admin_id = isset($_SESSION['admin_id']) ? intval($_SESSION['admin_id']) : null;

        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = realpath(__DIR__ . '/../img') . '/'; // Absolute path to /img/
            $filename = uniqid() . '_' . basename($_FILES['image']['name']);
            $targetFile = $uploadDir . $filename;

            if (move_uploaded_file($_FILES['image']['tmp_name'], $targetFile)) {
                $imagePath = 'img/' . $filename; // Store relative path
            }
        }

        if ($admin_id === null) {
            echo json_encode(['success' => false, 'message' => 'Admin not logged in.']);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO locations (name, status, image, admin_id) VALUES (?, ?, ?, ?)");
        if ($stmt->execute([$name, $status, $imagePath, $admin_id])) {
            echo json_encode(['success' => true, 'message' => 'Location added successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error: Could not add location.']);
        }
        exit;
    }

    // Toggle status
    if (isset($_POST['action']) && $_POST['action'] === 'toggle_status' && isset($_POST['location_id'], $_POST['status'])) {
        $admin_id = isset($_SESSION['admin_id']) ? intval($_SESSION['admin_id']) : null;
        if ($admin_id === null) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Admin not logged in.']);
            exit;
        }

        $location_id = intval($_POST['location_id']);
        $status = $_POST['status'];
        $allowed = ['open', 'closed'];

        if ($location_id <= 0 || !in_array($status, $allowed)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("UPDATE locations SET status = ?, admin_id = ? WHERE location_id = ?");
            $ok = $stmt->execute([$status, $admin_id, $location_id]);

            if ($ok && $stmt->rowCount() > 0) {
                echo json_encode(['success' => true, 'message' => 'Status updated.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'No location updated (not found or same status).']);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // Edit location
    if (isset($_POST['action']) && $_POST['action'] === 'edit' && isset($_POST['location_id'], $_POST['name'], $_POST['status'])) {
        $admin_id = isset($_SESSION['admin_id']) ? intval($_SESSION['admin_id']) : null;
        if ($admin_id === null) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Admin not logged in.']);
            exit;
        }

        $location_id = intval($_POST['location_id']);
        $name = trim($_POST['name']);
        $status = $_POST['status'];

        if ($name === '' || strlen($name) > 191) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid name']);
            exit;
        }

        $imagePath = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['jpg','jpeg','png','gif'];
            $origName = basename($_FILES['image']['name']);
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid image type']);
                exit;
            }
            $uploadDir = realpath(__DIR__ . '/../img');
            if ($uploadDir === false) $uploadDir = __DIR__ . '/../img';
            $uploadDir = rtrim($uploadDir, '/\\') . '/';
            $filename = uniqid() . '_' . preg_replace('/[^a-z0-9_\.-]/i','_', $origName);
            $targetFile = $uploadDir . $filename;
            if (move_uploaded_file($_FILES['image']['tmp_name'], $targetFile)) {
                $imagePath = 'img/' . $filename;
            }
        }

        if ($imagePath) {
            $stmt = $pdo->prepare("UPDATE locations SET name=?, status=?, image=?, admin_id=? WHERE location_id=?");
            $success = $stmt->execute([$name, $status, $imagePath, $admin_id, $location_id]);
        } else {
            $stmt = $pdo->prepare("UPDATE locations SET name=?, status=?, admin_id=? WHERE location_id=?");
            $success = $stmt->execute([$name, $status, $admin_id, $location_id]);
        }

        echo json_encode([
            'success' => (bool)$success,
            'message' => $success ? 'Location updated successfully.' : 'Error: Could not update location.'
        ]);
        exit;
    }

    // Delete location
    if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['location_id'])) {
        $location_id = intval($_POST['location_id']);

        if (!Database::isSuperAdmin()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Forbidden: super-admin required to delete locations.']);
            exit;
        }

        $refs = [];
        try {
            $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'products'");
            $check->execute();
            if ((int)$check->fetchColumn() > 0) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE location_id = ?");
                $stmt->execute([$location_id]);
                $cnt = (int)$stmt->fetchColumn();
                if ($cnt > 0) $refs[] = "products ({$cnt})";
            }
        } catch (PDOException $e) {
            // ignore info_schema failures
        }

        if (!empty($refs)) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => 'Cannot delete: referenced in ' . implode(', ', $refs) . '. Use force_delete to dissociate references or mark affected items accordingly.'
            ]);
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM locations WHERE location_id=?");
        $success = $stmt->execute([$location_id]);

        echo json_encode([
            'success' => $success,
            'message' => $success ? 'Location deleted successfully.' : 'Error: Could not delete location.'
        ]);
        exit;
    }

    // Force delete
    if (isset($_POST['action']) && $_POST['action'] === 'force_delete' && isset($_POST['location_id'])) {
        $location_id = intval($_POST['location_id']);

        if (!Database::isSuperAdmin()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Forbidden: super-admin required to force delete locations.']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            try {
                $u = $pdo->prepare("UPDATE products SET location_id = NULL WHERE location_id = ?");
                $u->execute([$location_id]);
            } catch (PDOException $e) {
                // ignore if products table/column missing
            }

            $del = $pdo->prepare("DELETE FROM locations WHERE location_id = ?");
            $del->execute([$location_id]);

            if ($del->rowCount() > 0) {
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Force-deleted location and dissociated references (products updated).']);
            } else {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Location not found']);
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
}

echo json_encode([
    'success' => false,
    'message' => 'Unsupported action or missing parameters. Please check your request and try again.'
]);
exit;
?>
