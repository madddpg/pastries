<?php
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/database/db_connect.php';
$db = new Database();
$con = $db->opencon();

// Require admin
if (!Database::isAdmin() && !Database::isSuperAdmin()) {
    $ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    if ($ajax) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
    } else {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Forbidden'];
        header('Location: admin.php');
    }
    exit;
}

// determine AJAX
$ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// accept POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($ajax) {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    } else {
        header('Location: admin.php');
    }
    exit;
}

// parse inputs
$promo_id = isset($_POST['promo_id']) ? intval($_POST['promo_id']) : 0;
if ($promo_id <= 0) {
    if ($ajax) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid promo_id']);
    } else {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Invalid promo ID'];
        header('Location: admin.php');
    }
    exit;
}

// explicit requested active state if provided, otherwise toggle
$requested = null;
if (isset($_POST['active'])) {
    $requested = ($_POST['active'] === '0' || $_POST['active'] === 0) ? 0 : 1;
}

try {
    if ($requested === null) {
        $s = $con->prepare("SELECT active FROM promos WHERE promo_id = ? LIMIT 1");
        $s->execute([$promo_id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            if ($ajax) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Promo not found']);
            } else {
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Promo not found'];
                header('Location: admin.php');
            }
            exit;
        }
        $new = $row['active'] ? 0 : 1;
    } else {
        $new = (int)$requested;
    }

    $u = $con->prepare("UPDATE promos SET active = ? WHERE promo_id = ?");
    $u->execute([$new, $promo_id]);

    $message = $new ? "Promo activated" : "Promo deactivated";

    if ($ajax) {
        echo json_encode([
            'success' => true,
            'promo_id' => $promo_id,
            'active' => $new,
            'message' => $message,
            'redirect' => 'admin.php'
        ]);
        exit;
    } else {
        $_SESSION['flash'] = ['type' => 'success', 'message' => $message];
        header('Location: admin.php');
        exit;
    }
} catch (PDOException $e) {
    if ($ajax) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error']);
    } else {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Database error'];
        header('Location: admin.php');
    }
    exit;
}
?>
