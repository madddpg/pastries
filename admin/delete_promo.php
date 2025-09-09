<?php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/database/db_connect.php';
$db = new Database();
$con = $db->opencon();
// ...existing code...

// determine AJAX
$ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

try {
    // delete promo row and remove file if exists
    $stmt = $con->prepare("SELECT image FROM promos WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $del = $con->prepare("DELETE FROM promos WHERE id = ?");
    $del->execute([$id]);

    if ($del->rowCount() > 0) {
        // ...existing file removal code...
        $msg = 'Promo deleted';
        if ($ajax) {
            echo json_encode(['success' => true, 'message' => $msg, 'redirect' => 'admin.php']);
            exit;
        } else {
            $_SESSION['flash'] = ['type' => 'success', 'message' => $msg];
            header('Location: admin.php');
            exit;
        }
    } else {
        if ($ajax) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Promo not found']);
            exit;
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Promo not found'];
            header('Location: admin.php');
            exit;
        }
    }

} catch (PDOException $e) {
    if ($ajax) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error']);
        exit;
    } else {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Database error'];
        header('Location: admin.php');
        exit;
    }
}
exit;
?>