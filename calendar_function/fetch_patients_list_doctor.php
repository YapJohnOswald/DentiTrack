<?php
require_once 'calendar_conn.php';
header('Content-Type: application/json');

$query_term = trim($_GET['query'] ?? '');

// --- CRITICAL: REPLACE '4' WITH YOUR SECURE DOCTOR ID LOGIC (e.g., $_SESSION['user_id']) ---
$doctor_id = 4; // Placeholder ID (REPLACE ME)
// --------------------------------------------------------

if (empty($query_term)) {
    echo json_encode(['status' => 'success', 'patients' => []]);
    exit;
}

$search_param = '%' . strtolower($query_term) . '%';

/* * Fetch distinct patients that have appointments with this doctor, 
 * matching the search query on patient name (linked or unlinked).
 */
$query = "
    SELECT DISTINCT
        COALESCE(CONCAT(p.first_name, ' ', p.last_name), a.patient_name) AS patient_name
    FROM appointments a
    LEFT JOIN patient p ON a.user_id = p.user_id
    WHERE a.doctor_id = ?
      AND (LOWER(a.patient_name) LIKE ? OR LOWER(CONCAT(p.first_name, ' ', p.last_name)) LIKE ?)
    LIMIT 10
";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute([$doctor_id, $search_param, $search_param]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'patients' => $results
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'DB Error: ' . $e->getMessage()]);
}
?>