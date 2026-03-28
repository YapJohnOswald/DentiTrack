<?php
// db_pdo.php - Database connection file using PDO

$host = 'localhost';
$user = 'root';
$pass = '';
$mainDb = 'dentitrack_main'; // main DB where users & clinics tables exist

// DSN for main DB
$dsn = "mysql:host=$host;dbname=$mainDb;charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,      // Throw exceptions on errors
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, // Fetch results as associative arrays
    ]);
} catch (PDOException $e) {
    die("Main database connection failed: " . $e->getMessage());
}

/**
 * Function to connect to a clinic-specific DB dynamically
 */
function connectClinicDB($dbName, $host = 'localhost', $user = 'root', $pass = '') {
    try {
        $dsn = "mysql:host=$host;dbname=$dbName;charset=utf8mb4";
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        die("Clinic database connection failed: " . $e->getMessage());
    }
}
