<?php
require_once '../database_connections/db_connect.php'; // <-- fixed path

$product_id = $_POST['product_id'];
$new_name = $_POST['new_name'];
$new_price = $_POST['new_price'];
$new_category = $_POST['new_category'];
$new_status = isset($_POST['new_status']) ? $_POST['new_status'] : null;

$conn = new mysqli("localhost", "root", "", "ordering");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if ($new_status !== null) {
    $stmt = $conn->prepare("UPDATE products SET name = ?, price = ?, category = ?, status = ? WHERE id = ?");
    $stmt->bind_param("sdsss", $new_name, $new_price, $new_category, $new_status, $product_id);
} else {
    $stmt = $conn->prepare("UPDATE products SET name = ?, price = ?, category = ? WHERE id = ?");
    $stmt->bind_param("sdss", $new_name, $new_price, $new_category, $product_id);
}
$stmt->execute();

if ($stmt->affected_rows > 0) {
    echo "Product updated successfully.";
} else {
    echo "No changes made or product not found.";
}

$stmt->close();
$conn->close();
?>
