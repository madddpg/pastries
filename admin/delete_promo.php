
<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/database/db_connect.php';
$db = new Database();
$con = $db->opencon();

$action = $_POST['action'] ?? '';
$id = intval($_POST['id'] ?? 0);
if ($id <= 0) { header('Location: admin.php?promo_error=Invalid'); exit; }

if ($action === 'delete') {
    $stmt = $con->prepare("SELECT image FROM promos WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['image'])) {
        $file = __DIR__ . '/../' . ltrim($row['image'], '/');
        if (file_exists($file)) @unlink($file);
    }
    $del = $con->prepare("DELETE FROM promos WHERE id = ?");
    $del->execute([$id]);
    header('Location: admin.php?promo_deleted=1');
    exit;
}

if ($action === 'toggle') {
    $new = isset($_POST['active']) && $_POST['active'] == '1' ? 1 : 0;
    $upd = $con->prepare("UPDATE promos SET active = ? WHERE id = ?");
    $upd->execute([$new, $id]);
    header('Location: admin.php?promo_toggled=1');
    exit;
}

header('Location: admin.php');
exit;
?>