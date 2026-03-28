<?php
// Include database connection
include 'db.php';

$low_stock_threshold = 10;
$today = date('Y-m-d');

// Low stock items
$stmt = $conn->prepare("SELECT * FROM inventory WHERE quantity < ?");
$stmt->bind_param("i", $low_stock_threshold);
$stmt->execute();
$low_stock_items = $stmt->get_result();

// Expired items
$stmt = $conn->prepare("SELECT * FROM inventory WHERE expiry_date < ?");
$stmt->bind_param("s", $today);
$stmt->execute();
$expired_items = $stmt->get_result();

// Display alerts as needed
?>
