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
            echo json_encode(['success' => true, 'message' => 'Location added successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error: Could not add location.']);
        }
        exit;
    }

    // Edit Location
    if (isset($_POST['action']) && $_POST['action'] === 'edit' && isset($_POST['id'], $_POST['name'], $_POST['status'])) {
        $id = intval($_POST['id']);
        $name = $_POST['name'];
        $status = $_POST['status'];
        $imagePath = null;

        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = realpath(__DIR__ . '/../img') . '/';
            $filename = uniqid() . '_' . basename($_FILES['image']['name']);
            $targetFile = $uploadDir . $filename;

            if (move_uploaded_file($_FILES['image']['tmp_name'], $targetFile)) {
                $imagePath = 'img/' . $filename;
            }
        }

        if ($imagePath) {
            $stmt = $pdo->prepare("UPDATE locations SET name=?, status=?, image=? WHERE id=?");
            $success = $stmt->execute([$name, $status, $imagePath, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE locations SET name=?, status=? WHERE id=?");
            $success = $stmt->execute([$name, $status, $id]);
        }

        echo json_encode([
            'success' => $success,
            'message' => $success ? 'Location updated successfully.' : 'Error: Could not update location.'
        ]);
        exit;
    }

    // Delete Location
    if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['id'])) {
        $id = intval($_POST['id']);
        $stmt = $pdo->prepare("DELETE FROM locations WHERE id=?");
        $success = $stmt->execute([$id]);

        echo json_encode([
            'success' => $success,
            'message' => $success ? 'Location deleted successfully.' : 'Error: Could not delete location.'
        ]);
        exit;
    }
    if (isset($_POST['action']) && $_POST['action'] === 'toggle_status' && isset($_POST['id'], $_POST['status'])) {
        $id = intval($_POST['id']);
        $status = $_POST['status'];
        $stmt = $pdo->prepare("UPDATE locations SET status=? WHERE id=?");
        $success = $stmt->execute([$status, $id]);

        echo json_encode([
            'success' => $success,
            'message' => $success ? 'Location status updated.' : 'Error: Could not update location status.'
        ]);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Invalid request.']);
exit;
?>
