<?php
// fetch_payment_data.php

// -----------------------------------------------------------------------------
// ACTION REQUIRED: Confirm this path to your database config is correct
require_once '../config/db_pdo.php'; 
// -----------------------------------------------------------------------------

header('Content-Type: application/json');

if (!isset($_GET['appointment_id']) || empty($_GET['appointment_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Appointment ID is required.']);
    exit;
}

$appointment_id = $_GET['appointment_id'];

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // --- 1. Fetch main appointment, patient, service, payment, and Supplies (JSON from payments table) ---
    $stmt = $pdo->prepare("
        SELECT 
            p.total_amount, p.discount_type, p.downpayment, p.installment_term, p.amount, p.monthly_payment,
            p.supplies_used, -- <<< FETCHING SUPPLIES AS JSON STRING
            pt.first_name, pt.last_name,
            s.service_name, s.price as service_price
        FROM payments p
        JOIN appointments a ON p.appointment_id = a.appointment_id
        JOIN patient pt ON a.patient_id = pt.patient_id
        LEFT JOIN services s ON a.service_id = s.service_id 
        WHERE p.appointment_id = :appointment_id
    ");
    $stmt->execute([':appointment_id' => $appointment_id]);
    $payment_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment_data) {
        echo json_encode(['status' => 'error', 'message' => 'No payment record found for this appointment.']);
        exit;
    }
    
    // --- 2. Decode Supplies Used JSON ---
    $supplies_json = $payment_data['supplies_used'] ?? '[]';
    $supplies = json_decode($supplies_json, true);
    
    // Format supplies for display (assuming the JSON format is like: [{"id":1,"name":"Rubber","qty":2}])
    if (is_array($supplies)) {
        $formatted_supplies = array_map(function($s) {
            // Rename 'name' to 'supply_name' and 'qty' to 'quantity' to match front-end expectation
            return [
                'supply_name' => $s['name'] ?? 'Unknown Supply',
                'quantity' => $s['qty'] ?? 0
            ];
        }, $supplies);
    } else {
        $formatted_supplies = [];
    }
    
    // Prepare final output, formatting currency with commas
    echo json_encode([
        'status' => 'success',
        'details' => [
            'patient_name' => $payment_data['first_name'] . ' ' . $payment_data['last_name'],
            'service_name' => $payment_data['service_name'] ?? 'N/A',
            'service_price' => number_format($payment_data['service_price'] ?? 0, 2)
        ],
        'payment_data' => [
            'total_amount' => number_format($payment_data['total_amount'], 2),
            'discount_type' => $payment_data['discount_type'],
            'downpayment' => number_format($payment_data['downpayment'], 2),
            'installment_term' => $payment_data['installment_term'],
            'amount' => number_format($payment_data['amount'], 2), // Remaining Balance
            'monthly_payment' => number_format($payment_data['monthly_payment'], 2)
        ],
        'supplies' => $formatted_supplies // Use the JSON decoded data
    ]);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>