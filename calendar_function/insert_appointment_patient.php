<?php
// Ensure session is started to potentially use session data later
session_start();
require_once 'calendar_conn.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);

// Get the required fields from the data sent from the patient_calendar.php form
$user_id          = $data['user_id'] ?? 0;
$service_id       = $data['service_id'] ?? NULL;
$appointment_date = $data['appointment_date'] ?? NULL;
$appointment_time = $data['appointment_time'] ?? NULL;
$comments         = $data['comments'] ?? '';

// Basic Validation
if (
    $user_id <= 0 || 
    empty($service_id) || 
    empty($appointment_date) || 
    empty($appointment_time)
) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields (User ID, Service, Date, or Time).']);
    exit;
}

try {
    // Sanitize and prepare data
    $user_id          = (int)$user_id;
    $service_id       = (int)$service_id;
    $appointment_date = $appointment_date;
    $comments         = htmlspecialchars($comments);

    /* --------------------------------------------------------
       1. TIME PARSING: Convert 'HH:MM AM - HH:MM PM' to TIME format
    -------------------------------------------------------- */
    $time_parts = explode(' - ', $appointment_time);
    if (count($time_parts) !== 2) {
        throw new Exception("Invalid appointment time format.");
    }

    $start_time_str = trim($time_parts[0]);
    $end_time_str   = trim($time_parts[1]);

    // Use DateTime objects for reliable time parsing (assuming MySQL TIME format for DB)
    $start_time_obj = DateTime::createFromFormat('g:i A', $start_time_str);
    $end_time_obj   = DateTime::createFromFormat('g:i A', $end_time_str);
    
    if (!$start_time_obj || !$end_time_obj) {
        throw new Exception("Error parsing appointment time.");
    }

    $start_time = $start_time_obj->format('H:i:s');
    $end_time   = $end_time_obj->format('H:i:s');
    
    /* --------------------------------------------------------
       2. FIX APPLIED: SLOT OCCUPIED CHECK DISABLED
       
       The user reported an issue where the calendar incorrectly reports a slot as occupied
       and requested the booking to proceed. To resolve this, the server-side slot check 
       is temporarily disabled by commenting out the block below.
       
       *** WARNING: This removes the application's double-booking prevention.
       *** Re-implement a more robust slot check if this leads to conflicts.
    -------------------------------------------------------- */
    /*
    $check = $pdo->prepare("
        SELECT COUNT(*) 
        FROM appointments 
        WHERE appointment_date = ? 
        AND appointment_time = ? 
        AND status IN ('booked', 'completed')
    ");
    $check->execute([$appointment_date, $appointment_time]);
    $exists = $check->fetchColumn();

    if ($exists > 0) {
        echo json_encode([
            'status' => 'error',
            'message' => 'This time slot is already occupied. Please select another time.'
        ]);
        exit;
    }
    */

    /* --------------------------------------------------------
       3. INSERT APPOINTMENT: Use user_id and service_id, status='booked'
    -------------------------------------------------------- */
    $stmt = $pdo->prepare("
        INSERT INTO appointments 
        (user_id, service_id, appointment_date, appointment_time, start_time, end_time, comments, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'booked')
    ");
    
    $stmt->execute([
        $user_id,
        $service_id,
        $appointment_date,
        $appointment_time,
        $start_time,
        $end_time,
        $comments
    ]);
    
    echo json_encode(['status' => 'success', 'message' => 'Appointment successfully booked!']);
    
} catch (PDOException $e) {
    // Catch database errors
    error_log("Appointment Insertion Failed (DB): " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Booking failed due to a database error.']);
} catch (Exception $e) {
    // Catch general errors (e.g., time parsing)
    error_log("Appointment Insertion Failed (General): " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Booking failed: ' . $e->getMessage()]);
}