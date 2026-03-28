<?php
require_once 'calendar_conn.php';
header('Content-Type: application/json');

$events = [];

/* ------------------------------------------
   STATUS COLORS FOR CALENDAR LEGEND (UPDATED)
------------------------------------------- */
$statusColors = [
    'pending'   => '#FFD966',  // Yellow - NEW
    'approved'  => '#B19CD9',  // Light Purple - NEW
    'booked'    => '#4A90E2',  // Blue
    'completed' => '#28a745',  // Green
    'cancelled' => '#FF6961',  // Reddish (Changed cancelled color to red to free up yellow for pending)
    'declined'  => '#FF6961',  // Reddish
];

/* ------------------------------------------
   FETCH GROUPED APPOINTMENTS BY DATE & STATUS (UPDATED)
   - Now includes 'pending' and 'approved'
------------------------------------------- */
$query = "
    SELECT appointment_date, LOWER(status) AS status, COUNT(*) AS total
    FROM appointments
    WHERE LOWER(status) IN ('pending', 'approved', 'booked', 'completed', 'cancelled', 'declined') 
    GROUP BY appointment_date, status
";
try {
    $stmt = $pdo->query($query);
} catch (PDOException $e) {
    // Return error safely
    http_response_code(500);
    echo json_encode([
        'status' => 'error', 
        'message' => 'DB error: ' . $e->getMessage()
    ]);
    exit;
}

/* ------------------------------------------
   COMBINE COUNTS FOR SAME DATE & STATUS
------------------------------------------- */
$grouped = [];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $date = $row['appointment_date'];
    $status = $row['status']; // Already lowercased by query
    $count = (int)$row['total'];

    // Map status to Title case for display
    $displayStatus = ucfirst($status);

    if (!isset($grouped[$date])) {
        $grouped[$date] = [];
    }
    $grouped[$date][$displayStatus] = $count;
}

/* ------------------------------------------
   BUILD EVENTS ARRAY FOR CALENDAR DISPLAY
------------------------------------------- */
foreach ($grouped as $date => $statuses) {
    foreach ($statuses as $status => $count) {
        if ($count <= 0) continue;

        // Use the mapped $displayStatus for color lookup
        $color = $statusColors[strtolower($status)] ?? '#CCCCCC'; 

        // Text color logic updated: pending, cancelled, and rest day use black text for visibility on light backgrounds
        $textColor = (strtolower($status) === 'pending' || strtolower($status) === 'cancelled') ? 'black' : 'white'; 

        $events[] = [
            'title' => "$status: $count",
            'start' => $date,
            'color' => $color,
            'textColor' => $textColor, 
            'display' => 'block',
            'extendedProps' => [
                'status' => $status,
                'count'  => $count
            ]
        ];
    }
}

/* ------------------------------------------
   FETCH DOCTOR REST DAYS (Green: #8FD19E)
------------------------------------------- */
$queryRest = "
    SELECT available_date, reason
    FROM doctor_availability
    WHERE is_available = 0
";

try {
    $stmtRest = $pdo->query($queryRest);
    while ($row = $stmtRest->fetch(PDO::FETCH_ASSOC)) {
        $events[] = [
            'title' => 'Rest Day',
            'start' => $row['available_date'],
            'color' => '#8FD19E', // Light Green for rest day
            'textColor' => 'black',
            'display' => 'block',
            'extendedProps' => [
                'status' => 'Rest Day', 
            ]
        ];
    }
} catch (PDOException $e) {
    // Log rest day error but don't stop the whole calendar
    // In a production environment, you would log this properly.
}


echo json_encode($events);
?>