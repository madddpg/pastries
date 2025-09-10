
<?php
require_once __DIR__ . '/database/db_connect.php';

// Detect AJAX (XHR, Accept: json, or ?ajax=1)
$isAjax = (
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
    || (isset($_GET['ajax']) && $_GET['ajax'] === '1')
);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'], $_POST['status'])) {
    $id = (int)$_POST['id'];
    $status = trim($_POST['status']);

    try {
        $db = new Database();
        $con = $db->opencon();

        // Keep your existing behavior (reset notified, keep created_at update as in current file)
        $stmt = $con->prepare("UPDATE transaction SET status = ?, notified = 0, created_at = NOW() WHERE transac_id = ?");
        $stmt->execute([$status, $id]);

        error_log("Updated transaction ID $id to status: $status, notified set to 0");

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'id' => $id, 'status' => $status]);
            exit;
        }

        // Non-AJAX: keep legacy redirect
        header("Location: ./admin.php");
        exit;

    } catch (Throwable $e) {
        error_log('update_order_status error: ' . $e->getMessage());
        if ($isAjax) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Server error']);
            exit;
        }
        header("Location: ./admin.php");
        exit;
    }
}

// Fallback for bad requests
if ($isAjax) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Missing id or status']);
    exit;
}

header("Location: ./admin.php");
exit;