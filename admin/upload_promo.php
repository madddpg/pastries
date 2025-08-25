<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/database/db_connect.php';
$db = new Database();
$con = $db->opencon();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin.php');
    exit;
}

$title = trim($_POST['title'] ?? '');
if (!isset($_FILES['promoImage']) || $_FILES['promoImage']['error'] !== UPLOAD_ERR_OK) {
    header('Location: admin.php?promo_error=NoFile');
    exit;
}

$allowed = ['jpg','jpeg','png','webp','gif'];
$orig = $_FILES['promoImage']['name'];
$ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
if (!in_array($ext, $allowed)) {
    header('Location: admin.php?promo_error=BadType');
    exit;
}

$dir = __DIR__ . '/../img/promos';
if (!is_dir($dir)) mkdir($dir, 0755, true);

$basename = time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
$targetPath = $dir . '/' . $basename;

if (!move_uploaded_file($_FILES['promoImage']['tmp_name'], $targetPath)) {
    header('Location: admin.php?promo_error=MoveFailed');
    exit;
}

// insert DB record (image path relative to project root)
$imagePath = 'img/promos/' . $basename;
$stmt = $con->prepare("INSERT INTO promos (title, image, active, created_at) VALUES (?, ?, 1, NOW())");
$stmt->execute([$title, $imagePath]);

header('Location: admin.php?promo_success=1');
exit;
?>