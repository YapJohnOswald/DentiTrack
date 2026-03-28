<?php
// graph_conn.php
// Centralized Database Connection File

$servername = "localhost";
$username = "root"; // *** REPLACE WITH YOUR USERNAME ***
$password = ""; // *** REPLACE WITH YOUR PASSWORD ***
$dbname = "dentitrack_main";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    // If connection fails, stop execution and display the error
    die("Connection failed: " . $conn->connect_error);
}

// Optional: Set character set
$conn->set_charset("utf8mb4");

// NOTE: The connection object is now available as $conn
?>