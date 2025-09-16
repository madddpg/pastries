<?php
if (!isset($_GET['id']) || !($id = intval($_GET['id'])) ) {
    http_response_code(400);
    exit;
}
require_once __DIR__ . '/database/db_connect.php';
$db = new Database();
$pdo = $db->opencon();

try {
    $stmt = $pdo->prepare("SELECT image, image_mime, image_blob, created_at FROM promos WHERE promo_id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); exit; }

    // If we have blob bytes, serve them
    if (!empty($row['image_blob'])) {
        $mime = $row['image_mime'] ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=3600');
        // optional last-modified from created_at
        if (!empty($row['created_at'])) {
            $ts = strtotime($row['created_at']);
            if ($ts) header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $ts) . ' GMT');
        }
        echo $row['image_blob'];
        exit;
    }

    // Fallback: try filesystem path stored in `image` column
    $imgPath = $row['image'] ?? '';
    if ($imgPath) {
        $projectRoot = realpath(__DIR__ . '/../');
        $candidate = $projectRoot . '/' . ltrim(parse_url($imgPath, PHP_URL_PATH) ?: $imgPath, '/');
        $real = realpath($candidate);
        if ($real && strpos($real, $projectRoot) === 0 && file_exists($real)) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $real) ?: 'image/png';
            finfo_close($finfo);
            header('Content-Type: ' . $mime);
            header('Cache-Control: public, max-age=3600');
            readfile($real);
            exit;
        }
    }

    http_response_code(404);
    exit;
} catch (PDOException $e) {
    http_response_code(500);
    exit;
}