<?php
require_once __DIR__ . '/database/db_connect.php';
if (isset($_POST['id'], $_POST['status'])) {
    $id = intval($_POST['id']);
    $status = $_POST['status'];
    $db = new Database();
    $con = $db->opencon();
    
    // Update this query to reset notified=0 when status changes
    $stmt = $con->prepare("UPDATE transaction SET status=?, notified=0, created_at=NOW() WHERE transac_id=?");
    $stmt->execute([$status, $id]);
    
    // Log for debugging
    error_log("Updated transaction ID $id to status: $status, notified set to 0");
}
header("Location: ./admin.php");
exit();
?>