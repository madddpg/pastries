<?php
require_once '../database_connections/db_connect.php';
if (isset($_POST['id'], $_POST['status'])) {
    $id = intval($_POST['id']);
    $status = $_POST['status'];
    $db = new Database();
    $con = $db->opencon();
    $stmt = $con->prepare("UPDATE transaction SET status=? WHERE transac_id=?");
    $stmt->execute([$status, $id]);
}
header("Location: ../admin.php");
exit();
?>
