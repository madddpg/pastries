
<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/database/db_connect.php';
$db = new Database();
$con = $db->opencon();

// Require admin
if (!Database::isAdmin() && !Database::isSuperAdmin()) {
    // If AJAX request return JSON; otherwise redirect
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json', true, 403);
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
    } else {
        header('Location: admin.php?promo_error=Forbidden');
    }
    exit;
}

$ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
     || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($ajax) {
        header('Content-Type: application/json', true, 405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    } else {
        header('Location: admin.php');
    }
    exit;
}

$title = trim($_POST['title'] ?? '');
if (!isset($_FILES['promoImage']) || $_FILES['promoImage']['error'] !== UPLOAD_ERR_OK) {
    if ($ajax) {
        header('Content-Type: application/json', true, 400);
        echo json_encode(['success' => false, 'message' => 'No file uploaded']);
    } else {
        header('Location: admin.php?promo_error=NoFile');
    }
    exit;
}

// Validate extension + mime
$allowed_ext = ['jpg','jpeg','png','webp','gif'];
$orig = $_FILES['promoImage']['name'];
$ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $_FILES['promoImage']['tmp_name']);
finfo_close($finfo);

$allowed_mimes = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'gif'  => 'image/gif'
];

if (!in_array($ext, $allowed_ext) || (isset($allowed_mimes[$ext]) && stripos($mime, explode('/', $allowed_mimes[$ext])[0]) === false && $mime !== $allowed_mimes[$ext])) {
    if ($ajax) {
        header('Content-Type: application/json', true, 400);
        echo json_encode(['success' => false, 'message' => 'Invalid file type']);
    } else {
        header('Location: admin.php?promo_error=BadType');
    }
    exit;
}

// Ensure directory exists and writable
$dir = __DIR__ . '/../img/promos';
if (!is_dir($dir)) {
    if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
        if ($ajax) {
            header('Content-Type: application/json', true, 500);
            echo json_encode(['success' => false, 'message' => 'Failed to create directory']);
        } else {
            header('Location: admin.php?promo_error=NoDir');
        }
        exit;
    }
}

// Safe unique filename
$basename = time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
$targetPath = $dir . '/' . $basename;

if (!move_uploaded_file($_FILES['promoImage']['tmp_name'], $targetPath)) {
    if ($ajax) {
        header('Content-Type: application/json', true, 500);
        echo json_encode(['success' => false, 'message' => 'Failed to move uploaded file']);
    } else {
        header('Location: admin.php?promo_error=MoveFailed');
    }
    exit;
}

// set safe permissions
@chmod($targetPath, 0644);

// store DB path relative to webroot
$imagePath = '/img/promos/' . $basename;
$title_db = !empty($title) ? $title : null;

try {
    $stmt = $con->prepare("INSERT INTO promos (title, image, active, created_at) VALUES (?, ?, 1, NOW())");
    $stmt->execute([$title_db, $imagePath]);
    $insertId = $con->lastInsertId();

    if ($ajax) {
        header('Content-Type: application/json', true, 201);
        echo json_encode(['success' => true, 'id' => $insertId, 'image' => $imagePath, 'title' => $title_db]);
    } else {
        header('Location: admin.php?promo_success=1');
    }
    exit;
} catch (PDOException $e) {
    // cleanup file on error
    if (file_exists($targetPath)) @unlink($targetPath);
    if ($ajax) {
        header('Content-Type: application/json', true, 500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    } else {
        header('Location: admin.php?promo_error=DBError');
    }
    exit;
}
?>