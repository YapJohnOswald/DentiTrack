<?php
session_start();
include '../config/db_pdo.php';
include '../config/db_conn.php';

// Set content type to JSON
header('Content-Type: application/json');
$response = ['found' => false, 'error' => ''];

// Ensure PDO throws exceptions
if (isset($conn)) {
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} else {
    $response['error'] = "Database connection failed.";
    echo json_encode($response);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['error'] = "Invalid request method.";
    echo json_encode($response);
    exit();
}

$patient_id = intval($_POST['patient_id'] ?? 0);
$service_id = intval($_POST['service_id'] ?? 0);

if ($patient_id <= 0 || $service_id <= 0) {
    // If the patient or service selection is empty, it's not an error, just means no data to fetch
    echo json_encode($response);
    exit();
}

try {
    // 1. Fetch the patient's current total outstanding balance (from the patient table)
    $stmtPatient = $conn->prepare("SELECT outstanding_balance FROM patient WHERE patient_id = :patient_id");
    $stmtPatient->execute([':patient_id' => $patient_id]);
    $patientData = $stmtPatient->fetch(PDO::FETCH_ASSOC);

    $current_total_balance = floatval($patientData['outstanding_balance'] ?? 0);

    // 2. Fetch the MOST RECENT service that initiated an installment plan for this patient/service combination
    $stmtInstallment = $conn->prepare("
        SELECT total_amount, downpayment, monthly_payment 
        FROM payments 
        WHERE patient_id = :patient_id 
        AND service_id = :service_id 
        AND payment_option = 'installment'
        ORDER BY payment_id DESC
        LIMIT 1
    ");
    $stmtInstallment->execute([
        ':patient_id' => $patient_id,
        ':service_id' => $service_id
    ]);
    $lastInstallment = $stmtInstallment->fetch(PDO::FETCH_ASSOC);

    if ($lastInstallment) {
        // Calculate the original debt for this service (Total Cost - Initial Downpayment)
        $initial_total_cost = floatval($lastInstallment['total_amount']);
        $initial_downpayment = floatval($lastInstallment['downpayment']);
        $original_debt = $initial_total_cost - $initial_downpayment;
        
        // 3. Get the sum of all subsequent installment_payment records for this service
        $stmtSettlements = $conn->prepare("
            SELECT SUM(downpayment) as total_settled 
            FROM payments 
            WHERE patient_id = :patient_id 
            AND service_id = :service_id 
            AND payment_option = 'installment_payment'
        ");
        $stmtSettlements->execute([
            ':patient_id' => $patient_id,
            ':service_id' => $service_id
        ]);
        $settledData = $stmtSettlements->fetch(PDO::FETCH_ASSOC);
        $total_settled = floatval($settledData['total_settled'] ?? 0);

        $remaining_balance_service = max(0, $original_debt - $total_settled);

        if ($remaining_balance_service > 0) {
            $response['found'] = true;
            $response['current_total_balance'] = $current_total_balance; // Patient's total balance
            $response['remaining_balance_service'] = $remaining_balance_service; // Balance for this specific service
            $response['monthly_due'] = floatval($lastInstallment['monthly_payment']);
        }
    }

} catch (Exception $e) {
    $response['error'] = "Database error: " . $e->getMessage();
}

echo json_encode($response);
?>