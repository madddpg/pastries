<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../admin/database/db_connect.php';

try {
    $db = new Database();
    $pdo = $db->opencon();
    $stmt = $pdo->query("SELECT location_id, name, status, image FROM locations ORDER BY name");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        if (!empty($r['image']) && !preg_match('#^https?://#i', $r['image'])) {
            $r['image'] = rtrim('https://cupsandcuddles.online/', '/') . '/' . ltrim($r['image'], '/');
        }
    }

    echo json_encode(['success' => true, 'locations' => $rows]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}