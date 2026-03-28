<?php
// File: calendar_function/fetch_timeslots_patient.php
require_once 'calendar_conn.php';
header('Content-Type: application/json');

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_GET['date']) || empty($_GET['date'])) {
    echo json_encode(['error' => 'Missing date parameter']);
    exit;
}

$date = $_GET['date'];

$durationMinutes = isset($_GET['duration']) ? (int)$_GET['duration'] : 60;
if ($durationMinutes <= 0) $durationMinutes = 60;
$requiredSeconds = $durationMinutes * 60; 

try {
    /* --------------------------------------------------------
       1. CHECK FOR DOCTOR REST DAY 
    -------------------------------------------------------- */
    $restCheckStmt = $pdo->prepare("
        SELECT reason 
        FROM doctor_availability 
        WHERE available_date = ? AND is_available = 0
    ");
    $restCheckStmt->execute([$date]);
    $restDayInfo = $restCheckStmt->fetch(PDO::FETCH_ASSOC);

    if ($restDayInfo) {
        echo json_encode(['status' => 'restday', 'reason' => $restDayInfo['reason']]);
        exit;
    }

    /* --------------------------------------------------------
       2. FETCH ALREADY BOOKED APPOINTMENTS
    -------------------------------------------------------- */
    $stmt = $pdo->prepare("
        SELECT start_time, end_time, status 
        FROM appointments 
        WHERE appointment_date = ? 
        AND LOWER(status) IN ('booked', 'completed', 'pending_payment')
    ");
    $stmt->execute([$date]);
    $bookedAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Define the clinic's operating hours in seconds from midnight
    $START_TIME = 5 * 3600;  // 5:00 AM
    $END_TIME   = 20 * 3600; // 8:00 PM (end time is exclusive)
    
    // Convert DB times (HH:MM:SS) to seconds from midnight for calculation
    $occupiedBlocks = [];
    foreach ($bookedAppointments as $appt) {
        list($h_start, $m_start, $s_start) = explode(':', $appt['start_time']);
        list($h_end, $m_end, $s_end) = explode(':', $appt['end_time']);

        $startSeconds = ($h_start * 3600) + ($m_start * 60) + $s_start;
        $endSeconds   = ($h_end * 3600) + ($m_end * 60) + $s_end;
        
        $occupiedBlocks[] = [
            'start' => $startSeconds,
            'end'   => $endSeconds,
            'status' => strtolower($appt['status'])
        ];
    }
    
    /* --------------------------------------------------------
       3. IMPLEMENT DOCTOR'S LUNCH BREAK (12:00 PM - 1:00 PM)
    -------------------------------------------------------- */
    $LUNCH_START = 12 * 3600; // 12:00 PM in seconds from midnight
    $LUNCH_END   = 13 * 3600; // 1:00 PM in seconds from midnight

    // Add the lunch break to the occupied blocks list. 
    $occupiedBlocks[] = [
        'start' => $LUNCH_START, 
        'end'   => $LUNCH_END, 
        'status' => 'lunchbreak' // Custom status for the modal display
    ];

    /* --------------------------------------------------------
       4. CHECK DAILY CAPACITY
    -------------------------------------------------------- */
    define('DAILY_CAPACITY', 10); 
    if (count($bookedAppointments) >= DAILY_CAPACITY) {
        echo json_encode(['status' => 'fully_booked']);
        exit;
    }
    
    /* --------------------------------------------------------
       5. GENERATE AVAILABLE TIME SLOTS
    -------------------------------------------------------- */
    $slots = [];
    $slotStart = $START_TIME;
    $dateAsTimestamp = strtotime($date); 

    while ($slotStart + $requiredSeconds <= $END_TIME) {
        $slotEnd = $slotStart + $requiredSeconds;
        $isAvailable = true;

        // Check if the current slot overlaps with any occupied block
        foreach ($occupiedBlocks as $block) {
            // Overlap check: [Start1, End1) and [Start2, End2) overlap if Start1 < End2 and Start2 < End1
            if ($slotStart < $block['end'] && $block['start'] < $slotEnd) {
                $isAvailable = false;
                break;
            }
        }

        if ($isAvailable) {
            $timeStartFormatted = date('g:i A', $dateAsTimestamp + $slotStart);
            $timeEndFormatted   = date('g:i A', $dateAsTimestamp + $slotEnd);
            $label = $timeStartFormatted . ' - ' . $timeEndFormatted;

            $slots[] = [
                'time'   => $label,
                'status' => 'available',
                'label'  => $label . ' • Available',
            ];

            $slotStart += $requiredSeconds; 
        } else {
             $slotStart += $requiredSeconds; 
        }
    }
    
    /* --------------------------------------------------------
       6. ADD OCCUPIED BLOCKS back for display (including Lunch Break)
    -------------------------------------------------------- */
    foreach ($occupiedBlocks as $block) {
        $timeStartFormatted = date('g:i A', $dateAsTimestamp + $block['start']);
        $timeEndFormatted   = date('g:i A', $dateAsTimestamp + $block['end']);
        $label = $timeStartFormatted . ' - ' . $timeEndFormatted;

        $slots[] = [
            'time'   => $label,
            'status' => $block['status'],
            'label'  => $label . ' • ' . ucfirst($block['status'])
        ];
    }
    
    // Sort all slots (available and occupied) by start time
    usort($slots, function($a, $b) use ($dateAsTimestamp) {
        $aTime = strtotime(explode(' - ', $a['time'])[0], $dateAsTimestamp);
        $bTime = strtotime(explode(' - ', $b['time'])[0], $dateAsTimestamp);
        return $aTime <=> $bTime;
    });

    // Remove duplicates caused by the occupied blocks being added back
    $finalSlots = [];
    $seen = [];
    foreach ($slots as $slot) {
        $key = $slot['time'] . $slot['status'];
        if (!isset($seen[$key])) {
            $finalSlots[] = $slot;
            $seen[$key] = true;
        }
    }

    echo json_encode($finalSlots);

} catch (PDOException $e) {
    error_log("Timeslot Fetch Failed: " . $e->getMessage());
    echo json_encode(['error' => 'Database error.']);
} catch (Exception $e) {
    error_log("Timeslot Fetch Failed (General): " . $e->getMessage());
    echo json_encode(['error' => 'Server error.']);
}
?>