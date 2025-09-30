<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../database/db_connect.php';

try {
    session_start();
    if (!(Database::isAdmin() || Database::isSuperAdmin() || (isset($_SESSION['admin_id']) && $_SESSION['admin_id']))) {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'Forbidden']);
        exit;
    }
    $db = new Database();
    $pdo = $db->opencon();
    $stmt = $pdo->prepare("SELECT location_id, name, status, image FROM locations ORDER BY name ASC");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    echo json_encode(['success'=>true,'locations'=>$rows]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error','error'=>$e->getMessage()]);
}
