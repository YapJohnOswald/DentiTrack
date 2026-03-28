<?php
require_once 'calendar_conn.php';
header('Content-Type: application/json');

// Get query parameter
$query = trim($_GET['query'] ?? '');

if ($query === '') {
    echo json_encode(['status' => 'empty', 'patients' => []]);
    exit;
}

try {
    $query_sql = "
        SELECT DISTINCT CONCAT(first_name, ' ', last_name) AS patient_name
        FROM patient
        WHERE LOWER(CONCAT(first_name, ' ', last_name)) LIKE :search_term
        ORDER BY patient_name 
        LIMIT 10
    ";
    
    $stmt = $pdo->prepare($query_sql);
    $search_param = '%' . strtolower($query) . '%';
    
    $stmt->execute([':search_term' => $search_param]);

    $patients = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($row['patient_name'])) {
            $patients[] = ['patient_name' => $row['patient_name']];
        }
    }

    if (count($patients) === 0) {
        echo json_encode(['status' => 'empty', 'patients' => []]);
    } else {
        echo json_encode(['status' => 'success', 'patients' => $patients]);
    }

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'DB error: ' . $e->getMessage()]);
}
?>