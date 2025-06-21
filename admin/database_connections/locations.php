<?php
require_once __DIR__ . '/db_connect.php';
$db = new Database();
$pdo = $db->opencon();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add Location
    if (isset($_POST['name']) && !isset($_POST['action'])) {
        $name = $_POST['name'];
        $status = isset($_POST['status']) ? $_POST['status'] : 'open';
        $imagePath = '';
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = '../../img/';
            $filename = uniqid() . '_' . basename($_FILES['image']['name']);
            $targetFile = $uploadDir . $filename;
            if (move_uploaded_file($_FILES['image']['tmp_name'], $targetFile)) {
                $imagePath = 'img/' . $filename;
            }
        }
        $stmt = $pdo->prepare("INSERT INTO locations (name, status, image) VALUES (?, ?, ?)");
        if ($stmt->execute([$name, $status, $imagePath])) {
            echo "Location added successfully.";
        } else {
            echo "Error: Could not add location.";
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
            $uploadDir = '../../img/';
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
        if ($success) {
            echo "Location updated successfully.";
        } else {
            echo "Error: Could not update location.";
        }
        exit;
    }

    // Delete Location
    if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['id'])) {
        $id = intval($_POST['id']);
        $stmt = $pdo->prepare("DELETE FROM locations WHERE id=?");
        if ($stmt->execute([$id])) {
            echo "Location deleted successfully.";
        } else {
            echo "Error: Could not delete location.";
        }
        exit;
    }

    // Toggle Status
    if (isset($_POST['action']) && $_POST['action'] === 'toggle_status' && isset($_POST['id'], $_POST['status'])) {
        $id = intval($_POST['id']);
        $status = $_POST['status'];
        $stmt = $pdo->prepare("UPDATE locations SET status=? WHERE id=?");
        if ($stmt->execute([$status, $id])) {
            echo "Location status updated.";
        } else {
            echo "Error: Could not update location status.";
        }
        exit;
    }
}
echo "Invalid request.";
?>
