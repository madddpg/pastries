<?php
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/database/db_connect.php';
$db = new Database();
$con = $db->opencon();
// ...existing code...

// determine AJAX
$ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

try {
    if ($requested === null) {
        // ...existing code...
    } else {
        $new = (int)$requested;
    }

    $u = $con->prepare("UPDATE promos SET active = ? WHERE id = ?");
    $u->execute([$new, $id]);

    $message = $new ? 'Promo activated' : 'Promo set inactive';

    if ($ajax) {
        echo json_encode(['success' => true, 'id' => $id, 'active' => $new, 'message' => $message, 'redirect' => 'admin.php']);
        exit;
    } else {
        // set flash and redirect back to admin page
        $_SESSION['flash'] = ['type' => 'success', 'message' => $message];
        header('Location: admin.php');
        exit;
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
?>