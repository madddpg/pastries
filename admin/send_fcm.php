
<?php
session_start();
header('Content-Type: application/json');
if (empty($_SESSION['admin_id'])) {
    echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit;
}
require_once __DIR__ . '/database/db_connect.php';

$ref = trim($_POST['reference'] ?? $_GET['reference'] ?? '');
$title = trim($_POST['title'] ?? $_GET['title'] ?? 'New Order');
$body  = trim($_POST['body']  ?? $_GET['body']  ?? ($ref ? "Reference {$ref}" : 'Incoming order'));

$db = new Database();

// Use the tokens saved in admin_users via saveAdminFcmToken
$reflected = new ReflectionClass($db);
$method = $reflected->getMethod('sendDirectFcm');
$method->setAccessible(true);

$data = ['click_action'=>'https://cupsandcuddles.online/admin/admin.php'];
if ($ref) $data['reference'] = $ref;

$method->invoke($db, $title, $body, $data);

echo json_encode(['success'=>true,'message'=>'Push attempted (check error_log for delivery result)']);