<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'secretary') {
    header('Location: secretary_login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['result_file'])) {
    $patient_id = $_POST['patient_id'];
    $file = $_FILES['result_file'];
    $target_dir = "../uploads/results/";
    $filename = basename($file["name"]);
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $allowed = ['pdf', 'jpg', 'png'];

    if (!in_array($ext, $allowed)) {
        die("Invalid file type.");
    }

    $new_filename = "result_{$patient_id}_" . time() . ".$ext";
    $target_file = $target_dir . $new_filename;

    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }

    if (move_uploaded_file($file["tmp_name"], $target_file)) {
        include '../config/db_pdo.php';
        $stmt = $conn->prepare("INSERT INTO patient_results (patient_id, file_path) VALUES (:pid, :path)");
        $stmt->execute(['pid' => $patient_id, 'path' => $new_filename]);
        header("Location: view_patient_details.php?id=" . $patient_id);
    } else {
        echo "Upload failed.";
    }
}
?>
