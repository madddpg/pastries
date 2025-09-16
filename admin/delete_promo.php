
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

// only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($ajax) { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); }
    else { header('Location: admin.php'); }
    exit;
}

$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
if ($id <= 0) {
    if ($ajax) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Invalid id']); }
    else { $_SESSION['flash'] = ['type'=>'error','message'=>'Invalid promo id']; header('Location: admin.php'); }
    exit;
}

try {
    // fetch image path info
    $stmt = $con->prepare("SELECT image FROM promos WHERE promo_id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $del = $con->prepare("DELETE FROM promos WHERE promo_id = ?");
    $del->execute([$id]);

    if ($del->rowCount() > 0) {
        // remove file if exists (safe)
        if (!empty($row['image'])) {
            $imgPath = parse_url($row['image'], PHP_URL_PATH) ?: $row['image'];
            // remove accidental leading project folder name
            $projectRoot = realpath(__DIR__ . '/../');
            $candidate = $projectRoot . '/' . ltrim($imgPath, '/');
            $real = realpath($candidate);
            if ($real && strpos($real, $projectRoot) === 0 && is_file($real)) {
                @unlink($real);
            }
        }

        
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
?>