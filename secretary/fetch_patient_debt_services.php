<?php
// fetch_patient_debt_services.php

include '../config/db_pdo.php'; 
include '../config/db_conn.php'; 

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($conn)) {
    echo json_encode(['services' => [], 'error' => 'Invalid request or database connection failed.']);
    exit;
}

$patient_id = intval($_POST['patient_id'] ?? 0);

if ($patient_id <= 0) {
    echo json_encode(['services' => []]);
    exit;
}

try {
    // This query finds the INITIAL installment record (total_amount) for each service,
    // sums up all payments (downpayment field) made against that service,
    // and calculates the remaining debt.
    $sql = "
        SELECT 
            s.service_id,
            s.service_name,
            -- Find the total amount Billed for the original installment plan
            MAX(CASE WHEN p_all.payment_option = 'installment' THEN p_all.total_amount ELSE NULL END) AS total_billed,
            -- Sum up all payments made towards this service (using downpayment field for payment amount)
            SUM(p_all.downpayment) AS total_paid,
            -- The remaining debt for this specific service
            (MAX(CASE WHEN p_all.payment_option = 'installment' THEN p_all.total_amount ELSE NULL END) - SUM(p_all.downpayment)) AS debt_remaining
        FROM 
            payments p_all
        JOIN 
            services s ON p_all.service_id = s.service_id
        WHERE 
            p_all.patient_id = :pid 
            AND p_all.payment_option IN ('installment', 'paid_installment')
        GROUP BY 
            s.service_id, s.service_name
        HAVING 
            -- Filter out services that have been fully paid (debt_remaining > 0)
            debt_remaining > 0
        ORDER BY 
            s.service_name ASC;
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute([':pid' => $patient_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $services_list = [];
    foreach ($results as $row) {
        // Ensure total_billed is not NULL before calculating debt, although HAVING should handle it
        if ($row['total_billed'] !== NULL) {
            $services_list[] = [
                'service_id' => $row['service_id'],
                'service_name' => $row['service_name'],
                'debt' => floatval($row['debt_remaining'])
            ];
        }
    }

    echo json_encode(['services' => $services_list]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['services' => [], 'error' => 'Database error: ' . $e->getMessage()]);
}
?>