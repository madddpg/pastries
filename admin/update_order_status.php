<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/database/db_connect.php';

$isAjax = (
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
    || (isset($_GET['ajax']) && $_GET['ajax'] === '1')
);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'], $_POST['status'])) {
    $id = (int)$_POST['id'];
    $status = trim($_POST['status']);
    $debugHeader = isset($_SERVER['HTTP_X_DEBUG']) && strtolower($_SERVER['HTTP_X_DEBUG']) === '1';

    // Debug logger
    $logDir = __DIR__ . '/logs';
    $logFile = $logDir . '/order_status_debug.log';
    if (!is_dir($logDir)) { @mkdir($logDir, 0777, true); }
    $log = function(array $payload) use ($logFile) {
        $payload['ts'] = date('Y-m-d H:i:s');
        $payload['ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
        @file_put_contents($logFile, json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
    };

    try {
        $db  = new Database();
        $con = $db->opencon();

        // Only logged-in admins can approve/update orders
        $adminId = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : 0;
        if (!$adminId) {
            if ($isAjax) {
                header('Content-Type: application/json', true, 403);
                echo json_encode(['success'=>false,'message'=>'Forbidden: admin login required']);
                exit;
            }
            header('Location: ./admin.php');
            exit;
        }

        // Read current status (for debug)
        $before = null;
        try {
            $chk = $con->prepare("SELECT status, admin_id FROM `transaction` WHERE transac_id = ? LIMIT 1");
            $chk->execute([$id]);
            $before = $chk->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { /* ignore */ }

        // If status transitions away from 'pending', stamp the approving admin if not already set
        if (strtolower($status) !== 'pending') {
            $stmt = $con->prepare("UPDATE `transaction`
                                   SET status = ?, notified = 0,
                                       admin_id = CASE WHEN admin_id IS NULL THEN ? ELSE admin_id END
                                   WHERE transac_id = ?");
            $stmt->execute([$status, $adminId, $id]);
        } else {
            $stmt = $con->prepare("UPDATE `transaction` SET status = ?, notified = 0 WHERE transac_id = ?");
            $stmt->execute([$status, $id]);
        }

        $affected = isset($stmt) ? (int)$stmt->rowCount() : 0;
        // Read after status (for debug)
        $after = null;
        try {
            $chk2 = $con->prepare("SELECT status, admin_id FROM `transaction` WHERE transac_id = ? LIMIT 1");
            $chk2->execute([$id]);
            $after = $chk2->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { /* ignore */ }

        // Log debug always for now to trace issue
        $log([
            'event' => 'update_order_status',
            'ajax' => $isAjax,
            'admin_id' => $adminId,
            'transac_id' => $id,
            'requested_status' => $status,
            'before' => $before,
            'affected_rows' => $affected,
            'after' => $after,
            'headers' => [
                'X-Requested-With' => $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '',
                'X-Debug' => $_SERVER['HTTP_X_DEBUG'] ?? '',
                'Accept' => $_SERVER['HTTP_ACCEPT'] ?? ''
            ]
        ]);

        if ($isAjax) {
            header('Content-Type: application/json');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
            echo json_encode([
                'success'=>true,
                'message'=>'Status updated',
                'admin_id'=>$adminId,
                'affected_rows'=>$affected,
                'status_after'=>$after['status'] ?? null
            ]);
            exit;
        }
        header("Location: ./admin.php");
        exit;
    } catch (Throwable $e) {
        // Log server error
        if (!isset($log)) {
            // in case closure failed to define
            @file_put_contents(__DIR__ . '/logs/order_status_debug.log', json_encode(['ts'=>date('Y-m-d H:i:s'),'event'=>'update_order_status_error','error'=>$e->getMessage()]) . PHP_EOL, FILE_APPEND);
        } else {
            $log(['event'=>'update_order_status_error','error'=>$e->getMessage(),'transac_id'=>$id ?? null]);
        }
        if ($isAjax) {
            header('Content-Type: application/json', true, 500);
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            echo json_encode(['success'=>false,'message'=>'Error updating status']);
            exit;
        }
    }
}

if ($isAjax) {
    http_response_code(400);
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode(['success'=>false,'message'=>'Missing id or status']);
    exit;
}

header("Location: ./admin.php");
exit;