<?php
require_once __DIR__ . '/database/db_connect.php';

$isAjax = (
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
    || (isset($_GET['ajax']) && $_GET['ajax'] === '1')
);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'], $_POST['status'])) {
    $id = (int)$_POST['id'];
    $status = trim($_POST['status']);

    try {
        $db  = new Database();
        $con = $db->opencon();

        // UPDATE (no created_at change)
        $stmt = $con->prepare("UPDATE `transaction` SET status = ?, notified = 0 WHERE transac_id = ?");
        $stmt->execute([$status, $id]);

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success'=>true,'message'=>'Status updated']);
            exit;
        }
        header("Location: ./admin.php");
        exit;
    } catch (Throwable $e) {
        if ($isAjax) {
            header('Content-Type: application/json', true, 500);
            echo json_encode(['success'=>false,'message'=>'Error updating status']);
            exit;
        }
    }
}

if ($isAjax) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success'=>false,'message'=>'Missing id or status']);
    exit;
}

header("Location: ./admin.php");
exit;