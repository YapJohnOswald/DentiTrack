<?php
session_start();
// Include the database connections
include '../config/db_pdo.php';

// Set timezone for consistency
date_default_timezone_set('Asia/Manila');

// Ensure PDO connection is available
if (isset($pdo)) { 
    $conn = $pdo;
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} else {
    die("Database connection failed.");
}

// Check if payment ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: payments.php?error=' . urlencode('Invalid Payment ID provided.'));
    exit();
}

$payment_id = intval($_GET['id']);
$receipt_data = null;
$transaction_data = null;
$error = '';

try {
    // 1. Fetch the main BILLING record (from 'payments' table)
    $stmt = $conn->prepare("
        SELECT 
            p.*, 
            CONCAT(pat.first_name, ' ', pat.last_name) AS patient_name,
            pat.outstanding_balance AS patient_current_balance,
            s.service_name
        FROM payments p
        JOIN patient pat ON p.patient_id = pat.patient_id
        JOIN services s ON p.service_id = s.service_id
        WHERE p.payment_id = :payment_id
    ");
    $stmt->execute([':payment_id' => $payment_id]);
    $receipt_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$receipt_data) {
        $error = "Receipt not found for ID: " . $payment_id;
    } else {
        // Decode supplies
        $receipt_data['supplies_used'] = json_decode($receipt_data['supplies_used'] ?? '[]', true);

        // 2. Fetch the monetary TRANSACTION record (from 'payment_transactions' table)
        // This confirms the cash received for this specific payment/bill
        $stmt_trans = $conn->prepare("
            SELECT 
                transaction_id, amount_received, transaction_date, payment_method, payment_proof_path
            FROM payment_transactions
            WHERE payment_id = :payment_id
            ORDER BY transaction_id ASC 
            LIMIT 1
        ");
        $stmt_trans->execute([':payment_id' => $payment_id]);
        $transaction_data = $stmt_trans->fetch(PDO::FETCH_ASSOC);
        
        // Calculate the remaining balance *before* this transaction was processed
        $remaining_after_bill = $receipt_data['total_amount'] - $receipt_data['downpayment'];
        
        // Determine the patient's balance *after* this payment
        $balance_before_transaction = $receipt_data['patient_current_balance'] - $remaining_after_bill;
    }
} catch (Exception $e) {
    $error = "Database error: " . $e->getMessage();
}

// Function to safely format currency
function formatC($amount) {
    return '₱' . number_format($amount, 2);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Receipt #<?= $payment_id ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f7f6; color: #333; margin: 0; padding: 20px; }
        .receipt-container { max-width: 700px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
        h1, h2 { color: #004080; text-align: center; }
        h1 { border-bottom: 3px solid #3399ff; padding-bottom: 15px; margin-bottom: 25px; }
        .details-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 20px; margin-bottom: 25px; }
        .details-grid div strong { color: #004080; }
        .total-section { margin-top: 25px; border-top: 1px dashed #ccc; padding-top: 15px; }
        .total-row { display: flex; justify-content: space-between; padding: 5px 0; font-size: 1.1rem; }
        .final-total { font-size: 1.5rem; font-weight: bold; color: #155724; background: #d4edda; padding: 10px; border-radius: 5px; }
        .supplies-table, .transactions-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .supplies-table th, .supplies-table td, .transactions-table th, .transactions-table td { border: 1px solid #eee; padding: 10px; text-align: left; }
        .supplies-table th, .transactions-table th { background-color: #f0f7ff; color: #004080; font-weight: 700; }
        .print-button { display: block; width: 200px; margin: 30px auto 0; padding: 10px; background: #3399ff; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 1.1rem; }
        .print-button:hover { background: #0066cc; }
        @media print {
            body { background: white; padding: 0; }
            .receipt-container { box-shadow: none; border: 1px solid #ccc; }
            .print-button { display: none; }
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <?php if ($error || !$receipt_data): ?>
            <h1 style="color: red;"><i class="fas fa-exclamation-triangle"></i> Error</h1>
            <p style="text-align: center;"><?= htmlspecialchars($error ?: "Payment data could not be loaded.") ?></p>
            <a href="payments.php" class="print-button" style="text-align: center; background: #dc3545;">Go Back to Payments</a>
        <?php else: ?>
            <h1><i class="fas fa-receipt"></i> Official Payment Receipt</h1>
            
            <div class="details-grid">
                <div><strong>Bill/Payment ID:</strong> #<?= $receipt_data['payment_id'] ?></div>
                <div><strong>Patient Name:</strong> <?= htmlspecialchars($receipt_data['patient_name']) ?></div>
                <div><strong>Date Processed:</strong> <?= date('F d, Y', strtotime($receipt_data['payment_date'])) ?></div>
                <div><strong>Service Rendered:</strong> <?= htmlspecialchars($receipt_data['service_name']) ?></div>
                <div><strong>Option:</strong> <?= ucfirst($receipt_data['payment_option']) ?></div>
                <div><strong>Status:</strong> <?= ucfirst(str_replace('_', ' ', $receipt_data['status'])) ?></div>
            </div>

            <h2>Billing Summary</h2>
            <div class="total-section">
                <div class="total-row">
                    <span>Base Service Cost:</span>
                    <span><?= formatC($receipt_data['amount']) ?></span>
                </div>
               
              
                <div class="total-row">
                    <span>Discount (<?= $receipt_data['discount_type'] ?>):</span>
                    <span style="color: red;">- <?= formatC($receipt_data['discount_amount']) ?></span>
                </div>
                <div class="total-row final-total">
                    <span>NET AMOUNT BILLED:</span>
                    <span><?= formatC($receipt_data['total_amount']) ?></span>
                </div>
            </div>
            
            <?php if (!empty($receipt_data['supplies_used'])): ?>
                <h2>Supplies Used</h2>
                <table class="supplies-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Qty Used</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        foreach ($receipt_data['supplies_used'] as $item): 
                        
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($item['name']) ?></td>
                            <td><?= $item['qty'] ?> <?= htmlspecialchars($item['unit'] ?? 'units') ?></td>
                           
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            
            <h2>Transaction Details</h2>
            <table class="transactions-table">
                <thead>
                    <tr>
                        <th>Transaction ID</th>
                        <th>Date</th>
                        <th>Method</th>
                        <th>Amount Received</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($transaction_data): ?>
                    <tr>
                        <td>#<?= $transaction_data['transaction_id'] ?></td>
                        <td><?= date('F d, Y', strtotime($transaction_data['transaction_date'])) ?></td>
                        <td><?= htmlspecialchars($transaction_data['payment_method']) ?></td>
                        <td style="font-weight: bold; color: green;"><?= formatC($transaction_data['amount_received']) ?></td>
                    </tr>
                    <?php else: ?>
                    <tr>
                        <td colspan="4" style="text-align: center;">No initial transaction recorded (Possible zero downpayment or error).</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="total-section">
                <div class="total-row">
                    <span>Total Billable Amount:</span>
                    <span><?= formatC($receipt_data['total_amount']) ?></span>
                </div>
                <div class="total-row">

                </div>
                <div class="total-row final-total" style="background: #fff3cd; color: #856404;">
                    <span>Remaining Balance (Due):</span>
                    <span><?= formatC($receipt_data['total_amount'] - $receipt_data['downpayment']) ?></span>
                </div>
                <div class="total-row" style="font-size: 1rem; border-top: 1px dashed #ccc; margin-top: 10px; padding-top: 10px;">
                    <span>Patient's New Outstanding Balance:</span>
                    <span style="font-weight: bold;"><?= formatC($receipt_data['patient_current_balance']) ?></span>
                </div>
            </div>
            
            <button class="print-button" onclick="window.print()"><i class="fas fa-print"></i> Print Receipt</button>
            <a href="payments.php" class="print-button" style="background: #004080; text-align: center;"><i class="fas fa-arrow-left"></i> Back to Payments</a>

        <?php endif; ?>
    </div>
</body>
</html>