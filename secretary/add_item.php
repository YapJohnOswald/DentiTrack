<?php
// Include database connection
include 'db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $item_name = $_POST['item_name'];
    $quantity = $_POST['quantity'];
    $expiry_date = $_POST['expiry_date'];

    $stmt = $conn->prepare("INSERT INTO inventory (item_name, quantity, expiry_date) VALUES (?, ?, ?)");
    $stmt->bind_param("sis", $item_name, $quantity, $expiry_date);
    $stmt->execute();

    echo "Inventory item added successfully.";
}
?>
