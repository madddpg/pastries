
<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user']) || !isset($_SESSION['user']['user_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Not logged in'
    ]);
    exit;
}

require_once __DIR__ . '/../admin/database/db_connect.php';
$db = new Database();
$pdo = $db->opencon();

try {
    $user_id = $_SESSION['user']['user_id'];
    
    // Get unread status updates (where notified=0)
    $stmt = $pdo->prepare("
        SELECT 
            t.transac_id AS transaction_id,
            t.reference_number,
            t.status
        FROM transaction t
        WHERE t.user_id = :user_id
          AND t.notified = 0
          AND t.created_at >= NOW() - INTERVAL 48 HOUR
        ORDER BY t.created_at DESC
    ");
                $stmt = $pdo->prepare("
                        SELECT 
                                t.transac_id AS transaction_id,
                                t.reference_number,
                                t.status,
                                p.pickup_location,
                                p.pickup_time
                        FROM transaction t
                        LEFT JOIN pickup_detail p ON p.transaction_id = t.transac_id
                        WHERE t.user_id = :user_id
                            AND t.notified = 0
                            AND t.created_at >= NOW() - INTERVAL 48 HOUR
                        ORDER BY t.created_at DESC
                ");
    
    $stmt->execute([':user_id' => $user_id]);
    $status_updates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Also get all recent orders for tracking
    $stmt = $pdo->prepare("
        SELECT 
            t.transac_id AS transaction_id,
            t.reference_number,
            t.status
        FROM transaction t
        WHERE t.user_id = :user_id
          AND t.created_at >= NOW() - INTERVAL 48 HOUR
        ORDER BY t.created_at DESC
    ");
                $stmt = $pdo->prepare("
                        SELECT 
                                t.transac_id AS transaction_id,
                                t.reference_number,
                                t.status,
                                p.pickup_location,
                                p.pickup_time
                        FROM transaction t
                        LEFT JOIN pickup_detail p ON p.transaction_id = t.transac_id
                        WHERE t.user_id = :user_id
                            AND t.created_at >= NOW() - INTERVAL 48 HOUR
                        ORDER BY t.created_at DESC
                ");
    
    $stmt->execute([':user_id' => $user_id]);
    $all_recent_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Add custom messages to status updates
    foreach ($status_updates as &$update) {
        $pickupTimeFormatted = '';
        if (!empty($update['pickup_time'])) {
            $ts = strtotime($update['pickup_time']);
            if ($ts !== false) { $pickupTimeFormatted = date('g:i A', $ts); }
        }
        switch(strtolower($update['status'])) {
            case 'confirmed':
                $update['message'] = 'Your order has been confirmed and will be prepared soon.';
                break;
            case 'preparing':
                $update['message'] = 'We are currently preparing your order.';
                break;
            case 'ready':
                $loc = isset($update['pickup_location']) && $update['pickup_location'] !== '' ? $update['pickup_location'] : 'our store';
                $timeStr = $pickupTimeFormatted !== '' ? (' at ' . $pickupTimeFormatted) : '';
                $update['message'] = 'Your order is ready for pickup at ' . $loc . $timeStr . '.';
                break;
            case 'completed':
                $update['message'] = 'Thank you for your order! We hope you enjoyed your drinks.';
                break;
            case 'cancelled':
                $update['message'] = 'Your order has been cancelled. Please contact us for assistance.';
                break;
            default:
                $update['message'] = 'Your order status has been updated to: ' . $update['status'];
        }
    }

    // Mark these notifications as seen
    if (!empty($status_updates)) {
        $notified_ids = array_column($status_updates, 'transaction_id');
        $placeholders = implode(',', array_fill(0, count($notified_ids), '?'));
        
        $update_stmt = $pdo->prepare("UPDATE transaction SET notified = 1 WHERE transac_id IN ($placeholders)");
        $update_stmt->execute($notified_ids);
    }
    
    echo json_encode([
        'success' => true,
        'status_updates' => $status_updates,
        'all_recent_orders' => $all_recent_orders
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}

$db->closecon();
?>