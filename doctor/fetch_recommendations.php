<?php
// fetch_recommendations.php

// Ensure this file has access to your database connection logic ($pdo or similar)
require_once '../config/db_pdo.php'; 

header('Content-Type: application/json');

if (!isset($_GET['patient_id']) || !is_numeric($_GET['patient_id'])) {
    // Return an empty array if patient_id is missing or invalid
    echo json_encode([]); 
    exit;
}

$patient_id = $_GET['patient_id'];

try {
    // CRITICAL CHANGE: Select the 'id' column for Edit/Delete functionality, 
    // and format the timestamp for clean display.
    $stmt = $pdo->prepare("SELECT 
        id, 
        doctor_username, 
        recommendation, 
        DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') AS created_at 
        FROM patient_recommendations 
        WHERE patient_id = ? 
        ORDER BY created_at DESC");
    
    $stmt->execute([$patient_id]);

    $recs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($recs);

} catch (PDOException $e) {
    // Log the error for debugging but return an empty array to the client
    error_log("Error fetching recommendations: " . $e->getMessage());
    echo json_encode([]);
}
?>