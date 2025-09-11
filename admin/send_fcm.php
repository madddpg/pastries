
<?php
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['admin_id'])) {
    echo json_encode(['success'=>false,'message'=>'Unauthorized']); 
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'message'=>'Method not allowed']);
    exit;
}

require_once __DIR__ . '/database/db_connect.php';

$ref   = trim($_REQUEST['reference'] ?? '');
$title = trim($_REQUEST['title'] ?? 'Test');
$body  = trim($_REQUEST['body']  ?? ($ref ? "Reference $ref" : 'Ping'));

try {
    $db = new Database();
    $refClass = new ReflectionClass($db);
    if (!$refClass->hasMethod('sendDirectFcm')) {
        throw new RuntimeException('sendDirectFcm not available');
    }
    $m = $refClass->getMethod('sendDirectFcm');
    $m->setAccessible(true);
    $m->invoke(
        $db,
        $title,
        $body,
        [
            'reference'    => $ref,
            'click_action' => 'https://cupsandcuddles.online/admin/admin.php'
        ]
    );
    echo json_encode(['success'=>true,'message'=>'Push dispatched (check error_log)']);
} catch (Throwable $e) {
    error_log('send_fcm.php error: '.$e->getMessage());
    echo json_encode(['success'=>false,'message'=>'Send failed']);
}