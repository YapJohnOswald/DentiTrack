<?php
// db_conn.php - Simple PDO connection wrapper using $conn variable

include_once 'db_pdo.php'; // Prevents duplicate loading of db_pdo.php

// Create an alias so old files using $conn will still work
$conn = $pdo;
?>
