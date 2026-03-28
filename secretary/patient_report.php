<?php
// Include database connection
include 'db.php';

$result = $conn->query("SELECT * FROM patients");

// Generate report as needed
?>
