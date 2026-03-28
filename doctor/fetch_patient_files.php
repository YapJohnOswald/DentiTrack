<?php
require_once '../config/db_pdo.php';

if (!isset($_GET['patient_id'])) {
    echo json_encode([]);
    exit;
}

$patient_id = $_GET['patient_id'];

$stmt = $pdo->prepare("SELECT file_name, file_path, uploaded_at FROM patient_files WHERE patient_id = ? ORDER BY uploaded_at DESC");
$stmt->execute([$patient_id]);

$files = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Format the uploaded_at timestamp to a more readable format
foreach ($files as &$f) {
    $f['uploaded_at'] = date('F d, Y H:i', strtotime($f['uploaded_at']));
}

echo json_encode($files);
