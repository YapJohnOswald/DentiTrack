<?php
// fetch_appointments_by_date_doctor.php (FINAL FIXED Patient Name Logic and Search)
require_once 'calendar_conn.php';
header('Content-Type: application/json');

$date = $_GET['date'] ?? '';
$search = trim($_GET['search'] ?? ''); 

if (empty($date)) {
    echo json_encode(['status' => 'error', 'message' => 'No date provided.']);
    exit;
}

$query = "
    SELECT 
        a.appointment_id,
        a.appointment_date,
        a.appointment_time,
        a.start_time,
        a.end_time,
        a.comments,
        LOWER(a.status) AS status,
        a.created_at,
        a.updated_at,
        
        s.service_name AS service, 
        
        -- Patient Name: Now strictly from the linked patient table
        CONCAT(p.first_name, ' ', p.last_name) AS patient_name, /* <--- FIXED: Removed fallback column */
        
        -- Patient Contact/Age/Email (from patient table p and users table u)
        u.email AS email,
        p.contact_number AS contact_number,
        p.gender AS gender,
        TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) AS age
        
    FROM appointments a
    LEFT JOIN patient p ON a.user_id = p.user_id
    LEFT JOIN users u ON p.user_id = u.user_id
    LEFT JOIN services s ON a.service_id = s.service_id 
    
    WHERE a.appointment_date = ?
      AND LOWER(a.status) IN ('booked', 'completed', 'cancelled', 'declined', 'pending', 'approved') 
";

$params = [$date];

// Add patient search filter if provided
if (!empty($search)) {
    // Search only against the concatenated patient name from the patient table
    $query .= " AND CONCAT(p.first_name, ' ', p.last_name) LIKE ?"; /* <--- FIXED: Removed old patient_name/client_name check */
    $params[] = "%$search%";
}

// Order by time
$query .= " ORDER BY a.appointment_time ASC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($appointments)) {
        echo json_encode([
            'status' => 'empty',
            'message' => 'No appointments found for this date.'
        ]);
        exit;
    }

    $formatted = [];
    foreach ($appointments as $a) {
        $formatted[] = [
            'appointment_id' => $a['appointment_id'],
            'patient_name'   => $a['patient_name'] ?? 'N/A (Unlinked User)', 
            'email'          => $a['email'] ?? '-',
            'contact_number' => $a['contact_number'] ?? '-',
            'gender'         => $a['gender'] ?? '-',
            'age'            => $a['age'] ?? '-',
            'service'        => $a['service'] ?? 'N/A',
            'appointment_date' => $a['appointment_date'],
            'appointment_time' => $a['appointment_time'],
            'start_time'     => $a['start_time'],
            'end_time'       => $a['end_time'],
            'comments'       => $a['comments'],
            'status'         => ucfirst($a['status']),
            'created_at'     => $a['created_at'],
            'updated_at'     => $a['updated_at']
        ];
    }

    echo json_encode([
        'status' => 'success',
        'appointments' => $formatted
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'DB Query Error: ' . $e->getMessage()]);
}
?>