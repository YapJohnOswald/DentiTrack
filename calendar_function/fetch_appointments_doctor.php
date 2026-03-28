<?php
// fetch_appointments_doctor.php (UNFILTERED - SECRETARY VIEW)
require_once 'calendar_conn.php';
header('Content-Type: application/json');

$events = [];

// *** The doctor_id variable and filtering logic have been removed as requested. ***

$statusColors = [
    'booked'    => '#4A90E2',  // Blue (Used for Pending/Approved)
    'completed' => '#28a745',  // Green
    'cancelled' => '#FFD966',  // Yellow
    'declined'  => '#FF6961',  // Reddish
    'approved'  => '#B19CD9',  // Light Purple - NEW
];

/* ------------------------------------------
   FETCH GROUPED APPOINTMENTS (NOW UNFILTERED - SHOWS ALL)
------------------------------------------- */
$query = "
    SELECT 
        appointment_date, 
        LOWER(status) AS status, 
        COUNT(*) AS total
    FROM appointments
    WHERE LOWER(status) IN ('booked','approved', 'completed', 'cancelled', 'declined')
    GROUP BY appointment_date, status
";

try {
    // Using $pdo->query() as there are no prepared statements (no parameters)
    $stmt = $pdo->query($query); 
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group results and build FullCalendar events
    foreach ($results as $row) {
        $status = $row['status'];
        $count = (int)$row['total'];

        if ($count <= 0) continue; 

        $color = $statusColors[$status] ?? '#CCCCCC'; 

        $events[] = [
            'title' => ucfirst($status) . ": $count",
            'start' => $row['appointment_date'],
            'color' => $color,
            'textColor' => ($status === 'cancelled') ? 'black' : 'white', 
            'display' => 'block',
            'extendedProps' => [
                'status' => $status,
                'count'  => $count
            ]
        ];
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'DB error (Appointments): ' . $e->getMessage()]);
    exit;
}

/* ------------------------------------------
   FETCH DOCTOR REST DAYS (NOW UNFILTERED - SHOWS ALL DOCTORS' REST DAYS)
------------------------------------------- */
$queryRest = "
    SELECT available_date, reason
    FROM doctor_availability
    WHERE is_available = 0
";

try {
    // Using $pdo->query() as there are no prepared statements (no parameters)
    $stmtRest = $pdo->query($queryRest);
    while ($row = $stmtRest->fetch(PDO::FETCH_ASSOC)) {
        $events[] = [
            'title' => 'Rest Day',
            'start' => $row['available_date'],
            'color' => '#8FD19E', // Light Green
            'textColor' => 'black',
            'display' => 'block',
            'extendedProps' => [
                'status' => 'Rest Day', 
            ]
        ];
    }
} catch (PDOException $e) {
    error_log("DB error (Rest Days): " . $e->getMessage());
}

echo json_encode($events);
?>