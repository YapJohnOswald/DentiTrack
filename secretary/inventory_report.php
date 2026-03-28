<?php
// Include database connection
include 'config.php';

$result = $conn->query("SELECT * FROM inventory");

// Generate report as needed
?>
