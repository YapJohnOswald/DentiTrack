<?php
require_once 'calendar_conn.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$appointment_id = (int) ($_POST['appointment_id'] ?? 0);
$new_status = trim($_POST['status'] ?? '');
$doctor_id = (int) ($_POST['doctor_id'] ?? 4); // Placeholder ID

// Validate input
if ($appointment_id <= 0 || empty($new_status)) {
    echo json_encode(['status' => 'error', 'message' => 'Missing appointment ID or status.']);
    exit;
}

// Doctor is restricted to these statuses (completed, approved, pending)
$allowed_statuses = ['completed', 'approved', 'pending']; 
if (!in_array(strtolower($new_status), $allowed_statuses)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid status provided for doctor role.']);
    exit;
}

// Convert to Title Case for DB
$db_status = ucfirst(strtolower($new_status));

try {
    // Crucially, verify the appointment belongs to the doctor before updating.
    $query = "UPDATE appointments SET status = ?, updated_at = NOW() WHERE appointment_id = ? AND doctor_id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$db_status, $appointment_id, $doctor_id]);

    if ($stmt->rowCount() > 0) {
        echo json_encode([
            'status' => 'success',
            'message' => "Appointment ID {$appointment_id} status updated to '{$db_status}'."
        ]);
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'No rows updated. Appointment not found or does not belong to this doctor.'
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'DB Error: ' . $e->getMessage()]);
}
?>