<?php
require_once 'calendar_conn.php';
header('Content-Type: application/json');

$date = $_GET['date'] ?? '';

// --- CRITICAL: REPLACE '4' WITH YOUR SECURE DOCTOR ID LOGIC (e.g., $_SESSION['user_id']) ---
$doctor_id = 4; // Placeholder ID (REPLACE ME)
// --------------------------------------------------------

if (empty($date)) {
    echo json_encode(['status' => 'error', 'message' => 'No date provided.']);
    exit;
}

$query = "
    SELECT reason 
    FROM doctor_availability
    WHERE doctor_id = ? 
      AND available_date = ? 
      AND is_available = 0
";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute([$doctor_id, $date]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result) {
        echo json_encode([
            'isRestDay' => true,
            'reason' => $result['reason'] ?? ''
        ]);
    } else {
        echo json_encode([
            'isRestDay' => false,
            'reason' => ''
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'DB Error: ' . $e->getMessage()]);
}
?>