<?php
// payments_api.php
// API endpoint to fetch detailed payment/receipt data by ID for the modal viewer.

define('DEBUG', true);
if (DEBUG) { ini_set('display_errors','1'); error_reporting(E_ALL); }

header('Content-Type: application/json');
session_start();

// Security Check: Only logged-in admins can access this data
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied. Administrator session required.']);
    exit();
}

// NOTE: Ensure your database connection file path is correct.
include '../config/db.php'; // expects $conn (mysqli)

// Function to send a standardized error response and exit
function api_error($message, $code = 400) {
    global $conn;
    if ($conn) @$conn->close(); // Use @ to suppress potential errors if connection failed
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit();
}

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!isset($data['action']) || $data['action'] !== 'detail') {
    api_error('Invalid action specified.');
}

$payment_id = (int)($data['id'] ?? 0);

if ($payment_id <= 0) {
    api_error('Invalid or missing payment ID.', 400);
}


// --- CRITICAL JOIN LOGIC & ALIASING FIX ---

// FIX: Alias patient name to `first_name` (as expected by JS receipt viewer) 
// FIX: Alias service name to `service_name` (as expected by JS receipt viewer)
// NOTE: Assuming patient's display name is stored in the 'fullname' column of the 'patient' table
$selectSQL = "
    p.*, 
    pat.fullname AS first_name, 
    s.service_name AS service_name_display 
";

// Join 1: payments (p) to patient (pat) on patient_id
// Join 2: patient (pat) to users (u) on user_id (REMOVED - JOINING DIRECTLY TO PATIENT TABLE IS SAFER)
// Join 3: payments (p) to services (s) on service_id
$joinClauses = "
    LEFT JOIN `patient` AS `pat` ON p.`patient_id` = pat.`patient_id`
    LEFT JOIN `services` AS `s` ON p.`service_id` = s.`service_id`
";
// ---------------------------------------

// --- Main Query Execution ---

$sql = "SELECT {$selectSQL} FROM `payments` AS `p` {$joinClauses} WHERE p.`payment_id` = ?";

if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param('i', $payment_id);
    
    if (!$stmt->execute()) {
        api_error('Database query failed: ' . $stmt->error, 500);
    }
    
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // --- Clean up data for JS receipt viewer ---
        $row['receipt_title'] = 'OFFICIAL PAYMENT RECEIPT';
        // Remap service name to the expected generic key if the joined name exists
        $row['service_name'] = $row['service_name_display'] ?? $row['service_name']; 
        unset($row['service_name_display']);
        
        $row['payment_option'] = $row['payment_option'] ?? 'Full Payment';
        // Note: The JS logic calculates cash_received based on DB data or total, so ensure it's present.
        $row['cash_received'] = $row['cash_received'] ?? $row['total_amount'] ?? $row['amount'] ?? 0;
        // ---------------------------------------------------------------------
        
        // Success: Return the row as JSON
        http_response_code(200);
        echo json_encode(['error' => false, 'data' => $row]);
        
    } else {
        api_error('Payment record not found.', 404);
    }
    
    @$stmt->close();
    
} else {
    api_error('Failed to prepare query: ' . $conn->error, 500);
}

@$conn->close();

?>