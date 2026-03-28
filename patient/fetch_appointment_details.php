<?php
session_start();
header('Content-Type: application/json');

// 1. Check if user is logged in AND is a patient
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

// 2. Check for required POST data
if (!isset($_POST['appointment_id']) || !is_numeric($_POST['appointment_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid appointment ID.']);
    exit();
}

// Ensure the path to your database connection file is correct
include '../config/db.php'; 

$appointment_id = (int)$_POST['appointment_id'];
$user_id = $_SESSION['user_id'];

// 3. Fetch appointment details. Join with 'services' table if available for a friendly service name.
$sql_fetch = "SELECT 
                a.appointment_id, 
                a.appointment_date,
                a.appointment_time,
                a.comments,
                a.status,
                a.service_id,
                a.created_at,
                s.service_name 
              FROM appointments a
              LEFT JOIN services s ON a.service_id = s.service_id /* Assuming a 'services' table for service name */
              WHERE a.appointment_id = ? 
              AND a.user_id = ?"; /* Security: Must belong to the user */

$stmt = $conn->prepare($sql_fetch);
$stmt->bind_param("ii", $appointment_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $details = $result->fetch_assoc();
    // Success
    echo json_encode(['success' => true, 'details' => $details]);
} else {
    // Appointment not found or does not belong to the user
    echo json_encode(['success' => false, 'message' => 'Appointment details not found or access denied.']);
}

$stmt->close();
$conn->close();
?>