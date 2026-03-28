<?php
require_once 'calendar_conn.php';
header('Content-Type: application/json');

$date = $_GET['date'] ?? '';
$search = trim($_GET['search'] ?? ''); 

if (empty($date)) {
    echo json_encode(['status' => 'error', 'message' => 'No date provided.']);
    exit;
}

/*
 * FIXED LOGIC: Joins 'appointments' -> 'patient' -> 'users' to get the email from 'users'.
 */
$query = "
    SELECT 
        a.appointment_id,
        a.appointment_date,
        a.appointment_time,
        a.start_time,
        a.end_time,
        a.comments,
        a.status,
        a.created_at,
        a.updated_at,
        a.user_id,
        a.service_id,
        s.service_name AS service, 
        -- Patient Details from 'patient' table (p)
        CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
        p.contact_number,
        p.gender,
        TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) AS age,
        -- Get email from the 'users' table (u)
        u.email AS email
    FROM appointments a
    JOIN services s ON a.service_id = s.service_id
    JOIN patient p ON a.user_id = p.user_id
    -- NEW JOIN to get email from the 'users' table
    JOIN users u ON a.user_id = u.user_id
    WHERE a.appointment_date = ?
      -- *** FIX: Added 'pending' and 'approved' to the list ***
      AND LOWER(a.status) IN ('pending', 'approved', 'booked', 'completed', 'cancelled', 'declined') 
";

$params = [$date];

// Add search filter if provided
if (!empty($search)) {
    $query .= " AND LOWER(CONCAT(p.first_name, ' ', p.last_name)) LIKE ?";
    $params[] = '%' . strtolower($search) . '%';
}

$query .= " 
    ORDER BY 
        -- Updated FIELD order to include 'pending' and 'approved'
        FIELD(LOWER(a.status), 'pending', 'approved', 'booked', 'completed', 'cancelled', 'declined'), 
        a.appointment_time ASC
";

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
        $status = strtolower($a['status']);
        $displayStatus = ucfirst($status);

        $formatted[] = [
            'appointment_id' => $a['appointment_id'],
            'patient_name'   => $a['patient_name'] ?? 'N/A', 
            'email'          => $a['email'] ?? '-', // Email is back!
            'contact_number' => $a['contact_number'] ?? '-',
            'gender'         => $a['gender'] ?? '-',
            'age'            => $a['age'] ?? '-',
            'service'        => $a['service'] ?? 'N/A', 
            'appointment_date' => $a['appointment_date'],
            'appointment_time' => $a['appointment_time'],
            'start_time'     => $a['start_time'],
            'end_time'       => $a['end_time'],
            'comments'       => $a['comments'],
            'status'         => $displayStatus,
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
    echo json_encode([
        'status' => 'error', 
        'message' => 'DB error: ' . $e->getMessage()
    ]);
}
?>