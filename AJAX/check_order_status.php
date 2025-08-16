<?php
session_start();
header('Content-Type: application/json');
// Prevent any output before the JSON response
ob_clean(); // Clear any previous output buffers

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

require_once __DIR__ . '/../admin/database/db_connect.php';

$user_id = $_SESSION['user_id'];
$db = new Database();
$pdo = $db->opencon();

try {
    // 1. Fetch new unnotified status updates - CORRECTED TABLE NAME
    $stmt = $pdo->prepare("
        SELECT reference_number, status, created_at as timestamp
        FROM transaction
        WHERE user_id = ?
        AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        AND (notified = 0 OR notified IS NULL)
        ORDER BY created_at DESC
    ");
    
    $stmt->execute([$user_id]);
    $updates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 2. Also fetch ALL recent orders to track status changes client-side
    $stmt = $pdo->prepare("
        SELECT reference_number, status, created_at as timestamp
        FROM transaction
        WHERE user_id = ?
        AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)  
        ORDER BY created_at DESC
        LIMIT 10
    ");
    
    $stmt->execute([$user_id]);
    $allRecentOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Mark unnotified updates as notified
    if (count($updates) > 0) {
        $referenceNumbers = array_map(function($update) {
            return $update['reference_number'];
        }, $updates);
        
        $placeholders = str_repeat('?,', count($referenceNumbers) - 1) . '?';
        
        $stmt = $pdo->prepare("
            UPDATE transaction
            SET notified = 1
            WHERE reference_number IN ($placeholders)
            AND user_id = ?
        ");
        
        $params = array_merge($referenceNumbers, [$user_id]);
        $stmt->execute($params);
    }
    
    // Create a clean response array and encode it properly
    $response = [
        'success' => true,
        'status_updates' => $updates,
        'all_recent_orders' => $allRecentOrders
    ];
    
    // Make sure we're sending valid JSON
    echo json_encode($response);
    
} catch (Exception $e) {
    // Log error and send clean error response
    error_log("Order status check error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error checking order status',
        'error' => $e->getMessage()
    ]);
}

$db->closecon(); // Just set PDO to null instead of calling a non-existent method
exit; // Make sure nothing else is output after the JSON
?>