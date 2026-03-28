<?php
// File: fetch_appointments_patient.php
require_once 'calendar_conn.php';
header('Content-Type: application/json');

$events = [];

/* ------------------------------------------
   CONSTANT: Define the maximum number of appointments per day 
------------------------------------------- */
define('DAILY_CAPACITY', 10); 

/* ------------------------------------------
   STATUS COLORS FOR CALENDAR LEGEND
   Using Dark Green (#008000) for Fully Booked/Rest Day for a strong design.
------------------------------------------- */
$statusColors = [
    'booked'            => '#4A90E2',  // Blue
    'completed'         => '#32CD32',  // Lime Green
    'cancelled'         => '#FF6F61', // Red/Orange
    'pending_payment'   => '#FFB74D', // Orange
    'fully_booked'      => '#008000', // Dark Green (Matching the old, desired design)
];

/* ------------------------------------------
   FETCH GROUPED APPOINTMENTS BY DATE
------------------------------------------- */
$query = "
    SELECT 
        appointment_date, 
        CASE 
            WHEN LOWER(status) = 'booked' THEN 'booked' 
            WHEN LOWER(status) = 'completed' THEN 'completed'
            WHEN LOWER(status) = 'cancelled' THEN 'cancelled'
            WHEN LOWER(status) = 'pending_payment' THEN 'pending_payment'
            ELSE 'processing'
        END as status_group,
        COUNT(appointment_id) as count
    FROM appointments
    GROUP BY appointment_date, status_group
";

$stmt = $pdo->query($query);
$dailyCounts = [];
$statusEvents = [];

// 1. Collect all counts and events grouped by status
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $date = $row['appointment_date'];
    $status = $row['status_group'];
    $count = (int)$row['count'];

    // Track total daily appointments
    if (!isset($dailyCounts[$date])) {
        $dailyCounts[$date] = 0;
    }
    $dailyCounts[$date] += $count;

    // Store status-specific events for later
    $statusEvents[] = [
        'date' => $date,
        'status' => $status,
        'count' => $count
    ];
}

// 2. Process daily capacity and add status events
foreach ($dailyCounts as $date => $totalCount) {
    if ($totalCount >= DAILY_CAPACITY) {
        // Add Fully Booked block event (colors the cell strongly)
        $color = $statusColors['fully_booked'] ?? '#008000';
        
        $events[] = [
            'title' => 'Fully Booked',
            'start' => $date,
            'color' => $color,
            'textColor' => 'white', 
            'display' => 'block', // CRITICAL for coloring the entire date cell
            'extendedProps' => [
                'status' => 'fully_booked', // Status for JS click handler
                'count'  => $totalCount
            ]
        ];
    } else {
        // Only add status events if the day is NOT fully booked
        foreach ($statusEvents as $eventData) {
            if ($eventData['date'] === $date) {
                $status = $eventData['status'];
                $count = $eventData['count'];

                $color = $statusColors[strtolower($status)] ?? '#CCCCCC';
                
                // Use white text for dark backgrounds, black for light backgrounds.
                $textColor = (in_array(strtolower($status), ['booked', 'cancelled', 'fully_booked'])) ? 'white' : 'black'; 
                
                // Format the title using the built-in PHP ucwords function
                $title = ucwords(str_replace('_', ' ', $status)) . ": $count";

                $events[] = [
                    'title' => $title,
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
    }
}

/* ------------------------------------------
   FETCH DOCTOR REST DAYS
------------------------------------------- */
$queryRest = "
    SELECT available_date, reason
    FROM doctor_availability
    WHERE is_available = 0
";
$stmtRest = $pdo->query($queryRest);

// Use the dark green color for consistency
$restDayColor = $statusColors['fully_booked'] ?? '#008000'; 
$restDayTextColor = 'white'; 

while ($row = $stmtRest->fetch(PDO::FETCH_ASSOC)) {
    $date = $row['available_date'];
    
    // Only add a rest day event if it's not already covered by a fully booked day
    if (!isset($dailyCounts[$date]) || $dailyCounts[$date] < DAILY_CAPACITY) {
        $events[] = [
            'title' => 'Rest Day',
            'start' => $date,
            'color' => $restDayColor,     
            'textColor' => $restDayTextColor,
            'display' => 'block',         // CRITICAL for coloring the entire date cell
            'extendedProps' => [
                'status' => 'restday',    // Status for the JS click handler
                'reason' => $row['reason'] 
            ]
        ];
    }
}

// ------------------------------------------
// OUTPUT JSON
// ------------------------------------------
echo json_encode($events);
?>