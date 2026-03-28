<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'secretary') {
    header('Location: login.php');
    exit();
}

$id = $_GET['id'];
$stmt = $pdo->prepare("SELECT * FROM patients WHERE patient_id = ?");
$stmt->execute([$id]);
$patient = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = $_POST['full_name'];
    $dob = $_POST['dob'];
    $contact_info = $_POST['contact_info'];
    $medical_history = $_POST['medical_history'];
    $treatment_records = $_POST['treatment_records'];
    $prescriptions = $_POST['prescriptions'];

    $stmt = $pdo->prepare("UPDATE patients SET full_name = ?, dob = ?, contact_info = ?, medical_history = ?, treatment_records = ?, prescriptions = ? WHERE patient_id = ?");
    $stmt->execute([$full_name, $dob, $contact_info, $medical_history, $treatment_records, $prescriptions, $id]);

    header('Location: view_patients.php');
}
?>
