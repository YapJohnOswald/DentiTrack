<?php
// get_appointment_details_doctor.php (UNFILTERED / MASTER VIEW)
require_once 'calendar_conn.php';
header('Content-Type: application/json');

$appointment_id = (int) ($_GET['id'] ?? 0);

// Doctor/User ID filtering logic has been REMOVED (Master View).

if ($appointment_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid appointment ID.']);
    exit;
}

$query = "
    SELECT 
        a.appointment_id,
        a.appointment_date,
        a.appointment_time,
        a.comments,
        LOWER(a.status) AS status,
        
        s.service_name AS service, 
        
        -- Patient Name: Corrected to only use first/last name from the patient table (p).
        -- Replaced missing 'a.patient_name' with a static fallback for unlinked/guest appointments.
        COALESCE(CONCAT(p.first_name, ' ', p.last_name), 'Unlinked Patient') AS patient_name,
        
        -- Email: Pulled from the users table (u)
        u.email AS email,

        -- Contact/Age/Gender: Pulled from the patient table (p)
        p.contact_number AS contact_number,
        p.gender AS gender,
        TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) AS age
    FROM 
        appointments a
    LEFT JOIN
        services s ON a.service_id = s.service_id
    LEFT JOIN
        patient p ON a.user_id = p.user_id
    LEFT JOIN
        users u ON a.user_id = u.user_id
    WHERE 
        a.appointment_id = :appointment_id 
    -- Removed: AND a.doctor_id = :doctor_id
";

try {
    $stmt = $pdo->prepare($query);
    
    // Execute with ONLY the appointment_id parameter
    $stmt->execute([':appointment_id' => $appointment_id]);

    $appointment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$appointment) { 
        error_log("No appointment found for ID: $appointment_id.");
        echo json_encode([
            'status' => 'error',
            'message' => 'Appointment details not found.'
        ]);
        exit;
    }

    // Format output
    $formatted = [
        'appointment_id' => $appointment['appointment_id'],
        'patient_name'   => $appointment['patient_name'], 
        'email'          => $appointment['email'] ?? '-',
        'contact_number' => $appointment['contact_number'] ?? '-',
        'gender'         => $appointment['gender'] ?? '-',
        'age'            => $appointment['age'] ?? '-',
        'service'        => $appointment['service'] ?? 'N/A',
        'appointment_date' => $appointment['appointment_date'],
        'appointment_time' => $appointment['appointment_time'],
        'comments'       => $appointment['comments'],
        'status'         => ucfirst($appointment['status']),
    ];

    echo json_encode([
        'status' => 'success',
        'data' => $formatted
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    // Reverting to the generic, safe error message for production
    error_log('DB Error in get_appointment_details_doctor.php: ' . $e->getMessage());
    echo json_encode([
        'status' => 'error', 
        'message' => 'Database error: Unable to fetch appointment details.'
    ]);
    exit;
}
?>