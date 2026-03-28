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
$user_id = $_SESSION['user_id']; // This is the ID we use for filtering

// 3. Update the appointment status
// The WHERE clause ensures:
// a) We only update the specified appointment ID.
// b) The appointment belongs to the logged-in user (user_id = ?). <--- SECURITY CHECK
// c) The status is currently 'booked'.
$sql_update = "UPDATE appointments 
               SET status = 'cancelled', 
                   comments = CONCAT(comments, ' [Cancelled by Patient on ', NOW(), ']')
               WHERE appointment_id = ? 
               AND user_id = ? /* <--- THIS PREVENTS CANCELLING OTHER USERS' APPOINTMENTS */
               AND status = 'booked'"; 

$stmt = $conn->prepare($sql_update);
$stmt->bind_param("ii", $appointment_id, $user_id);

if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        // Success: 1 row was updated
        echo json_encode(['success' => true]);
    } else {
        // Failure: Appointment was not booked, not found, or user mismatch
        echo json_encode(['success' => false, 'message' => 'Cancellation failed. Appointment is not booked or does not belong to your account.']);
    }
} else {
    // Database execution error
    echo json_encode(['success' => false, 'message' => 'Database update failed: ' . $conn->error]);
}

$stmt->close();
$conn->close();
?>