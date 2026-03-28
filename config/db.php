<?php
// db.php - MySQLi database connection file

$servername = "localhost";
$username = "root";           // Default XAMPP username
$password = "";               // Default XAMPP password (empty)
$dbname = "dentitrack_main";    // Your database name

// Create MySQLi connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check for connection error
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}
?>
