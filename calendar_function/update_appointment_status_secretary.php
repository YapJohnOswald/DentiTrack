<?php
require_once 'calendar_conn.php';
header('Content-Type: application/json');

// 1. Get POST data
// The front-end is expected to send these via POST
$appointment_id = $_POST['appointment_id'] ?? null;
$new_status = $_POST['status'] ?? null;
$updated_at = date('Y-m-d H:i:s'); // Timestamp the update

// 2. Validate essential parameters (ID and Status are mandatory for an update)
if (empty($appointment_id) || empty($new_status)) {
    echo json_encode([
        'status' => 'error', 
        'message' => 'Missing appointment ID or new status. Cannot update status.'
    ]);
    exit;
}

// Sanitize and validate the status value
// ADDED 'pending' and 'approved'
$valid_statuses = ['pending', 'approved', 'booked', 'completed', 'cancelled', 'declined'];
$new_status = strtolower($new_status);

if (!in_array($new_status, $valid_statuses)) {
    echo json_encode([
        'status' => 'error', 
        'message' => 'Invalid status provided.'
    ]);
    exit;
}

// 3. Prepare the SQL Update Statement
$query = "
    UPDATE appointments
    SET 
        status = ?, 
        updated_at = ?
    WHERE appointment_id = ?
";

try {
    $stmt = $pdo->prepare($query);
    // Execute the update with the new status, timestamp, and appointment ID
    $stmt->execute([$new_status, $updated_at, $appointment_id]);

    // Check if any row was actually updated
    if ($stmt->rowCount() > 0) {
        echo json_encode([
            'status' => 'success', 
            'message' => "Appointment ID {$appointment_id} successfully marked as " . ucfirst($new_status) . "."
        ]);
    } else {
        echo json_encode([
            'status' => 'error', 
            'message' => "Appointment ID {$appointment_id} not found or status is already " . ucfirst($new_status) . "."
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error', 
        'message' => 'DB error: Unable to update status. ' . $e->getMessage()
    ]);
}
?>