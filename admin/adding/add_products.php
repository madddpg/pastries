<?php
// ...existing code for DB connection...

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id']; // unique product id (string)
    $name = $_POST['name'];
    $description = $_POST['description'];
    $price = floatval($_POST['price']);
    $category = $_POST['category'];
    $status = isset($_POST['status']) ? $_POST['status'] : 'active';

    // Handle image upload
    $imagePath = '';
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = '../../img/';
        $filename = uniqid() . '_' . basename($_FILES['image']['name']);
        $targetFile = $uploadDir . $filename;
        if (move_uploaded_file($_FILES['image']['tmp_name'], $targetFile)) {
            $imagePath = 'img/' . $filename;
        }
    }

    $conn = new mysqli("localhost", "root", "", "ordering");
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    $stmt = $conn->prepare("INSERT INTO products (id, name, description, price, category, image, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssss", $id, $name, $description, $price, $category, $imagePath, $status);
    if ($stmt->execute()) {
        echo "Product added successfully.";
    } else {
        echo "Error: " . $stmt->error;
    }
    $stmt->close();

    // If category is not in the list, treat as new
    if (!empty($category)) {
        $catStmt = $conn->prepare("SELECT 1 FROM products WHERE category = ? LIMIT 1");
        $catStmt->bind_param("s", $category);
        $catStmt->execute();
        $catStmt->store_result();
        if ($catStmt->num_rows === 0) {
            // Optionally, you could insert a dummy product or just allow the new category
            // For now, do nothing (category will be added with the new product)
        }
        $catStmt->close();
    }

    $conn->close();
} else {
    echo "Invalid request.";
}
?>
