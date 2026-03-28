<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'secretary') {
    header('Location: ../public/login.php');
    exit();
}

include '../config/db_pdo.php'; // Ensure this provides the $conn PDO instance

$patient_id = (int)($_GET['id'] ?? 0);

if ($patient_id === 0) {
    header('Location: view_archived_patients.php?msg=invalid_id');
    exit();
}

try {
    // Set the is_archived flag back to 0 (unarchive/restore)
    $sql_restore = "UPDATE patient SET is_archived = 0 WHERE patient_id = :id";
    
    $stmt_restore = $conn->prepare($sql_restore);
    $stmt_restore->bindParam(':id', $patient_id, PDO::PARAM_INT);
    
    if ($stmt_restore->execute()) {
        header('Location: view_archived_patients.php?msg=restore_success');
        exit();
    } else {
        header('Location: view_archived_patients.php?msg=restore_failed');
        exit();
    }

} catch (PDOException $e) {
    error_log("Restore Error for ID $patient_id: " . $e->getMessage());
    header('Location: view_archived_patients.php?msg=restore_error');
    exit();
}