<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'secretary') {
    header('Location: secretary_login.php');
    exit();
}

include '../config/db_pdo.php';

$patient_id = $_GET['id'] ?? null;

if ($patient_id) {
    $delete = $conn->prepare("DELETE FROM patient WHERE patient_id = :id");
    $delete->execute([':id' => $patient_id]);
}

header('Location: view_patients.php');
exit();
?>
