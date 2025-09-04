<?php
header('Content-Type: application/json');

session_start();
require_once __DIR__ . '/database/db_connect.php';
$db = new Database();
$pdo = $db->opencon();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            echo json_encode([
                'success'    => true,
                'title'      => 'Location Added',
                'message'    => 'Success — a new Cups & Cuddles location is now brewing! ☕🏠',
                'toast_type' => 'success'
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                'success'    => false,
                'title'      => 'Oops — beans spilled',
                'message'    => 'Could not add location. The beans got spilled — please try again or contact support. ☕💧',
                'toast_type' => 'error'
            ]);
        }
        exit;
    }


    if (isset($_POST['action']) && $_POST['action'] === 'toggle_status' && isset($_POST['id'], $_POST['status'])) {
        $admin_id = isset($_SESSION['admin_id']) ? intval($_SESSION['admin_id']) : null;
        if ($admin_id === null) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'title'   => 'Not Signed In',
                'message' => 'You need to be signed in as a Barista Boss to manage locations. ☕🔑'
            ]);
            exit;
        }

        $id = intval($_POST['id']);
        $status = $_POST['status'];
        $allowed = ['open', 'closed'];

        if ($id <= 0 || !in_array($status, $allowed)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'title'   => 'Bad Request',
                'message' => 'Invalid parameters for updating location status. Please try again. ☕⚠️'
            ]);
            exit;
        }


        try {
            // remove updated_at (column doesn't exist in your table)
            $stmt = $pdo->prepare("UPDATE locations SET status = ?, admin_id = ? WHERE id = ?");
            $ok = $stmt->execute([$status, $admin_id, $id]);

            if ($ok && $stmt->rowCount() > 0) {
                echo json_encode([
                    'success' => true,
                    'title'   => 'Status Updated',
                    'message' => 'Location status updated successfully. Doors have been adjusted. ☕🔔',
                    'toast_type' => 'success'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'title'   => 'No Changes',
                    'message' => 'No location updated (not found or same status). Nothing to brew. ☕'
                ]);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'title'   => 'Database Error',
                'message' => 'Could not update status — the beans got stuck: ' . $e->getMessage(),
                'toast_type' => 'error'
            ]);
        }
        exit;
    }



    if (isset($_POST['action']) && $_POST['action'] === 'edit' && isset($_POST['id'], $_POST['name'], $_POST['status'])) {
        $admin_id = isset($_SESSION['admin_id']) ? intval($_SESSION['admin_id']) : null;
        if ($admin_id === null) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Admin not logged in.']);
            exit;
        }

        $id = intval($_POST['id']);
        $name = trim($_POST['name']);
        $status = $_POST['status'];

      if ($name === '' || strlen($name) > 191) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'title'   => 'Invalid Name',
                'message' => 'Location name must be provided and be under 191 characters. Keep it short and cozy. ☕✏️'
            ]);
            exit;
        }
        $imagePath = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            $origName = basename($_FILES['image']['name']);
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'title'   => 'Invalid Image',
                    'message' => 'Invalid image type. Please upload JPG, PNG or GIF so the shop looks its best. ☕🖼️'
                ]);
                exit;
            }
            $uploadDir = realpath(__DIR__ . '/../img');
            if ($uploadDir === false) $uploadDir = __DIR__ . '/../img';
            $uploadDir = rtrim($uploadDir, '/\\') . '/';
            $filename = uniqid() . '_' . preg_replace('/[^a-z0-9_\.-]/i', '_', $origName);
            $targetFile = $uploadDir . $filename;
            if (move_uploaded_file($_FILES['image']['tmp_name'], $targetFile)) {
                $imagePath = 'img/' . $filename;
            }
        }
 if ($imagePath) {
            $stmt = $pdo->prepare("UPDATE locations SET name=?, status=?, image=?, admin_id=? WHERE id=?");
            $success = $stmt->execute([$name, $status, $imagePath, $admin_id, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE locations SET name=?, status=?, admin_id=? WHERE id=?");
            $success = $stmt->execute([$name, $status, $admin_id, $id]);
        }

        echo json_encode([
            'success' => (bool)$success,
            'title'   => $success ? 'Location Updated' : 'Update Failed',
            'message' => $success ? 'Location updated — changes percolated successfully! ☕✅' : 'Could not update location. The beans got restless. ☕💧',
            'toast_type' => $success ? 'success' : 'error'
        ]);
        exit;
    }

    if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['id'])) {
        $id = intval($_POST['id']);

      if (!Database::isSuperAdmin()) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'title'   => 'Forbidden',
                'message' => 'Only the Owner or (super-admin) can delete locations. ☕🔒'
            ]);
            exit;
        }

        // check for references in candidate tables (only if they exist)
        $refs = [];
        try {
            // products.location_id
            $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'products'");
            $check->execute();
            if ((int)$check->fetchColumn() > 0) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE location_id = ?");
                $stmt->execute([$id]);
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
                'title'   => 'Referenced',
                'message' => 'Cannot delete: referenced in ' . implode(', ', $refs) . '. Dissociate affected items or use force_delete. ☕🔗'
            ]);
            exit;
        }
        // safe to delete
        $stmt = $pdo->prepare("DELETE FROM locations WHERE id=?");
        $success = $stmt->execute([$id]);

       echo json_encode([
            'success' => $success,
            'title'   => $success ? 'Location Deleted' : 'Delete Failed',
            'message' => $success ? 'Location deleted successfully — that corner has been cleared. ☕🧹' : 'Error: Could not delete location. The beans got stuck. ☕💧',
            'toast_type' => $success ? 'success' : 'error'
        ]);
        exit;
    }

    // Force delete (super-admin): dissociate references then delete
    if (isset($_POST['action']) && $_POST['action'] === 'force_delete' && isset($_POST['id'])) {
        $id = intval($_POST['id']);

        if (!Database::isSuperAdmin()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Forbidden: super-admin required to force delete locations.']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            // best-effort: dissociate products by nulling location_id
            try {
                $u = $pdo->prepare("UPDATE products SET location_id = NULL WHERE location_id = ?");
                $u->execute([$id]);
            } catch (PDOException $e) {
                // ignore if products table/column missing
            }

            $del = $pdo->prepare("DELETE FROM locations WHERE id = ?");
            $del->execute([$id]);

           if ($del->rowCount() > 0) {
                $pdo->commit();
                echo json_encode([
                    'success' => true,
                    'title'   => 'Force Delete Complete',
                    'message' => 'Force-deleted location and dissociated references — beans relocated. ☕🚚',
                    'toast_type' => 'success'
                ]);
            } else {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'title'   => 'Not Found',
                    'message' => 'Location not found. Nothing brewed here. ☕❌'
                ]);
            }
            } catch (PDOException $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'title'   => 'Database Error',
                'message' => 'Could not complete force delete — database error: ' . $e->getMessage(),
                'toast_type' => 'error'
            ]);
        }
        exit;
    }
}
echo json_encode([
    'success' => false,
    'title'   => 'Invalid Request',
    'message' => 'Invalid request. Nothing brewed — check your input and try again. ☕'
]);
exit;
