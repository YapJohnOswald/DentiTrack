<?php
require_once 'calendar_conn.php';
header('Content-Type: application/json');

$date = trim($_GET['date'] ?? '');
$search = trim($_GET['search'] ?? '');

if (empty($search)) {
    echo json_encode(['status'=>'error','message'=>'No patient search provided.']);
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
    WHERE LOWER(CONCAT(p.first_name, ' ', p.last_name)) LIKE ?
";

$params = ['%' . strtolower($search) . '%'];

if (!empty($date)) {
    $query .= " AND a.appointment_date = ?";
    $params[] = $date;
}

$query .= " ORDER BY a.appointment_date DESC, a.appointment_time ASC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($results)) {
        echo json_encode(['status'=>'empty','message'=>'No appointments found.']);
        exit;
    }

    $formatted = [];
    foreach ($results as $a) {
        $formatted[] = [
            'appointment_id' => $a['appointment_id'],
            'patient_name'   => $a['patient_name'],
            'email'          => $a['email'], // Email is back!
            'contact_number' => $a['contact_number'],
            'gender'         => $a['gender'],
            'age'            => $a['age'],
            'service'        => $a['service'],
            'appointment_date' => $a['appointment_date'],
            'appointment_time' => $a['appointment_time'],
            'start_time'     => $a['start_time'],
            'end_time'       => $a['end_time'],
            'comments'       => $a['comments'],
            'status'         => ucfirst(strtolower($a['status'])),
            'created_at'     => $a['created_at'],
            'updated_at'     => $a['updated_at']
        ];
    }

    echo json_encode(['status'=>'success','appointments'=>$formatted]);

} catch (PDOException $e) {
    echo json_encode(['status'=>'error','message'=>'Database error: '.$e->getMessage()]);
}
?>