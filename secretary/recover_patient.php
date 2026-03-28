<?php
session_start();

// Restrict access to secretaries only
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'secretary') {
    header('Location: secretary_login.php');
    exit();
}

/**
 * Validate and sanitize patient ID from GET parameter.
 * Returns int patient ID if valid, else redirects with error.
 */
function validatePatientId() {
    if (!isset($_GET['id'])) {
        $_SESSION['error'] = "Patient ID is missing.";
        header('Location: view_archived.php');
        exit();
    }

    // Use filter_var to validate positive integer
    $id = filter_var($_GET['id'], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
    if ($id === false) {
        $_SESSION['error'] = "Invalid patient ID.";
        header('Location: view_archived.php');
        exit();
    }
    return $id;
}

/**
 * Recover archived patient by ID.
 * Performs fetching from archive, insertion into active patient,
 * and deletion from archive within a transaction.
 */
function recoverPatient(PDO $conn, int $patient_id): bool {
    try {
        $conn->beginTransaction();

        // Fetch patient data from archive
        $sqlSelect = "SELECT * FROM patient_archive WHERE patient_id = :patient_id";
        $stmtSelect = $conn->prepare($sqlSelect);
        $stmtSelect->bindValue(':patient_id', $patient_id, PDO::PARAM_INT);
        $stmtSelect->execute();
        $patientData = $stmtSelect->fetch(PDO::FETCH_ASSOC);

        if (!$patientData) {
            throw new Exception("Archived patient not found.");
        }

        // Columns only in archive, not in active patient table
        $archiveColumns = ['archive_id', 'archived_at', 'archived_by'];
        foreach ($archiveColumns as $col) {
            unset($patientData[$col]);
        }

        // Define allowed columns for active patient table - adjust to your schema
        $allowedCols = ['patient_id', 'full_name', 'dob', 'contact_info', 'address', 'gender', 'email', 'phone', 'notes'];

        // Filter patient data to allowed columns only
        $insertData = array_intersect_key($patientData, array_flip($allowedCols));

        if (empty($insertData)) {
            throw new Exception("No valid patient data found to recover.");
        }

        // Check if patient already exists in active table
        $sqlCheck = "SELECT COUNT(*) FROM patient WHERE patient_id = :patient_id";
        $stmtCheck = $conn->prepare($sqlCheck);
        $stmtCheck->bindValue(':patient_id', $patient_id, PDO::PARAM_INT);
        $stmtCheck->execute();

        if ($stmtCheck->fetchColumn() > 0) {
            throw new Exception("Patient already exists in active records.");
        }

        // Build dynamic insert statement
        $columns = array_keys($insertData);
        $placeholders = array_map(fn($col) => ':' . $col, $columns);
        $sqlInsert = "INSERT INTO patient (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmtInsert = $conn->prepare($sqlInsert);

        // Bind all values dynamically
        foreach ($insertData as $col => $val) {
            $stmtInsert->bindValue(':' . $col, $val);
        }

        $stmtInsert->execute();

        // Delete patient from archive after successful insertion
        $sqlDelete = "DELETE FROM patient_archive WHERE patient_id = :patient_id";
        $stmtDelete = $conn->prepare($sqlDelete);
        $stmtDelete->bindValue(':patient_id', $patient_id, PDO::PARAM_INT);
        $stmtDelete->execute();

        $conn->commit();

        return true;
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        // Log error details for debugging
        error_log("Error recovering patient ID $patient_id: " . $e->getMessage());
        $_SESSION['error'] = "Failed to recover patient: " . $e->getMessage();
        return false;
    }
}

// Main execution starts here
$patient_id = validatePatientId();

// Include your PDO database connection
include '../config/db_pdo.php';

// Attempt recovery
if (recoverPatient($conn, $patient_id)) {
    $_SESSION['message'] = "Patient recovered successfully.";
}

header('Location: view_archived.php');
exit();
