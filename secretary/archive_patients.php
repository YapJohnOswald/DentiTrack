<?php
session_start();
// Security check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'secretary') {
    header('Location: ../public/login.php');
    exit();
}

// ==========================================================
// 🚀 FIXED: DATABASE CONNECTION SETUP
// Remove 'include ../config/db_pdo.php'; and replace with this:
// ==========================================================

$host = 'localhost'; 
$db   = 'dentitrack_main'; // <--- CHANGE THIS TO YOUR DB NAME
$user = 'root';   // <--- CHANGE THIS TO YOUR DB USER
$pass = '';    // <--- CHANGE THIS TO YOUR DB PASSWORD (e.g., '' for XAMPP root)
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     // This defines the $conn variable globally for the script
     $conn = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
     // If connection fails, stop execution and show error
     die("CRITICAL DB ERROR: Connection failed. Check credentials. Error: " . $e->getMessage()); 
}
// ==========================================================
// 🚀 END OF DATABASE CONNECTION SETUP
// ==========================================================


$patient_id = (int)($_GET['id'] ?? 0);
$mode = $_GET['mode'] ?? 'archive'; // Default action is 'archive'

if ($patient_id === 0) {
    // Redirect to the appropriate list based on current mode if ID is invalid
    $redirect_list = ($mode === 'restore') ? 'view_patients.php?mode=archived' : 'view_patients.php?mode=active';
    header('Location: ' . $redirect_list . '&msg=invalid_id');
    exit();
}

try {
    if ($mode === 'restore') {
        // --- 1. RESTORE ACTION ---
        // Set is_archived = 0 and remove the archived_date
        $sql = "UPDATE patient 
                SET is_archived = 0, archived_date = NULL 
                WHERE patient_id = :id";
        $success_msg = 'restore_success';
        $failure_msg = 'restore_failed';
        $redirect_mode = 'archived'; // Stay on the archived list after restoring a patient
        
    } else {
        // --- 2. ARCHIVE ACTION ---
        // Set is_archived = 1 and set the archived_date
        $sql = "UPDATE patient 
                SET is_archived = 1, archived_date = NOW() 
                WHERE patient_id = :id";
        $success_msg = 'archive_success';
        $failure_msg = 'archive_failed';
        $redirect_mode = 'active'; // Go back to the active list after archiving a patient
    }
    
    // Line 44 is now SAFE because $conn is guaranteed to be defined or the script would have died.
    $stmt = $conn->prepare($sql); 
    $stmt->bindParam(':id', $patient_id, PDO::PARAM_INT);
    
    // Execute and redirect
    if ($stmt->execute()) {
        header('Location: view_patients.php?mode=' . $redirect_mode . '&msg=' . $success_msg);
        exit();
    } else {
        header('Location: view_patients.php?mode=' . $redirect_mode . '&msg=' . $failure_msg);
        exit();
    }

} catch (PDOException $e) {
    // Log the error for debugging
    error_log("Patient Action Error ($mode) for ID $patient_id: " . $e->getMessage());
    
    // Redirect with a generic error message
    $redirect_list = ($mode === 'restore') ? 'view_patients.php?mode=archived' : 'view_patients.php?mode=active';
    header('Location: ' . $redirect_list . '&msg=db_error');
    exit();
}