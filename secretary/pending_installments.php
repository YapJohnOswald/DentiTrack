<?php
session_start();
require_once '../config/db_pdo.php';
require_once '../config/db_conn.php'; 

// --- PHPMailer Includes ---
require '../PHPMailer-master/src/PHPMailer.php';
require '../PHPMailer-master/src/SMTP.php';
require '../PHPMailer-master/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// =======================================================
// SMTP CONFIGURATION (Must be defined for email functionality)
// =======================================================
if (!defined('CONF_SMTP_HOST')) define('CONF_SMTP_HOST', 'smtp.gmail.com');
if (!defined('CONF_SMTP_PORT')) define('CONF_SMTP_PORT', 465); 
if (!defined('CONF_SMTP_USER')) define('CONF_SMTP_USER', 'dentitrack2025@gmail.com');       
if (!defined('CONF_SMTP_PASS')) define('CONF_SMTP_PASS', 'gpmennmjrynhujzq');           
// =======================================================

// Check authentication and role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'secretary') {
    header('Location: ../public/login.php');
    exit;
}

date_default_timezone_set('Asia/Manila');

// Ensure PDO throws exceptions
if (isset($pdo)) { 
    $conn = $pdo; 
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} else {
    $error = "Database connection failed.";
}

$message = '';
$error = '';
$recorded_by_user_id = $_SESSION['user_id'] ?? null;

// ===============================================
// 1. Handle Installment Payment Submission (POST)
// ===============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_installment_payment'])) {
    
    $payment_id = intval($_POST['payment_id'] ?? 0);
    $patient_id = intval($_POST['patient_id'] ?? 0);
    $amount_received = floatval($_POST['amount_received'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? 'Cash'; // Payment method is still kept
    
    // Validation
    if ($payment_id <= 0 || $patient_id <= 0 || $amount_received <= 0) {
        $error = "Invalid transaction details or amount. Please check the inputs.";
    } elseif ($recorded_by_user_id === null) {
        $error = "Session error: Could not identify the recording user.";
    }

    if (empty($error)) {
        try {
            // --- Fetch patient and payment details before transaction ---
            $stmt_info = $conn->prepare("
                SELECT 
                    pat.email AS patient_email, 
                    CONCAT(pat.first_name, ' ', pat.last_name) AS patient_name,
                    p.total_amount,
                    p.monthly_payment,
                    s.service_name,
                    p.appointment_id
                FROM payments p
                JOIN patient pat ON p.patient_id = pat.patient_id
                LEFT JOIN services s ON p.service_id = s.service_id
                WHERE p.payment_id = :payment_id
            ");
            $stmt_info->execute([':payment_id' => $payment_id]);
            $payment_info = $stmt_info->fetch(PDO::FETCH_ASSOC);

            if (!$payment_info) {
                $error = "Payment record or patient details not found.";
                throw new Exception($error); 
            }

            $patient_email = $payment_info['patient_email'];
            $patient_name = $payment_info['patient_name'];
            $net_billed = floatval($payment_info['total_amount']);
            $monthly_payment = floatval($payment_info['monthly_payment']);
            $service_name = $payment_info['service_name']; // Moved for scope

            $conn->beginTransaction();

            // --- A. Record Transaction in payment_transactions ---
            // payment_proof_path is now always NULL since the upload functionality is removed.
            $payment_proof_path = NULL; 

            $transaction_stmt = $conn->prepare("
                INSERT INTO payment_transactions (
                    payment_id, patient_id, amount_received, transaction_date, 
                    payment_method, payment_proof_path, recorded_by_user_id
                ) VALUES (
                    :payment_id, :patient_id, :amount_received, NOW(), 
                    :payment_method, :payment_proof_path, :recorded_by_user_id
                )
            ");
            
            $transaction_stmt->execute([
                ':payment_id' => $payment_id,
                ':patient_id' => $patient_id,
                ':amount_received' => $amount_received,
                ':payment_method' => $payment_method,
                ':payment_proof_path' => $payment_proof_path, // NULL value inserted
                ':recorded_by_user_id' => $recorded_by_user_id
            ]);

            // --- B. Deduct amount from Patient's Outstanding Balance ---
            $update_balance_subtract = $conn->prepare("
                UPDATE patient SET outstanding_balance = outstanding_balance - :new_payment 
                WHERE patient_id = :id
            ");
            $update_balance_subtract->execute([
                ':new_payment' => $amount_received,
                ':id' => $patient_id
            ]);

            // --- C. Check if bill is fully paid and update status ---
            $stmt_check_total = $conn->prepare("
                SELECT 
                    p.total_amount, 
                    (SELECT SUM(amount_received) FROM payment_transactions WHERE payment_id = :payment_id) AS total_paid_sum,
                    p.appointment_id
                FROM payments p WHERE p.payment_id = :payment_id
            ");
            $stmt_check_total->execute([':payment_id' => $payment_id]);
            $payment_data = $stmt_check_total->fetch(PDO::FETCH_ASSOC);
            
            $total_paid = floatval($payment_data['total_paid_sum'] ?? 0);
            $appointment_id = $payment_data['appointment_id'];
            $net_billed_float = floatval($net_billed); // Ensure consistency
            $current_remaining = max(0, $net_billed_float - $total_paid);

            if ($total_paid >= $net_billed_float) {
                // Bill fully settled
                $update_payment_status = $conn->prepare("UPDATE payments SET status = 'paid' WHERE payment_id = :id");
                $update_payment_status->execute([':id' => $payment_id]);

                if ($appointment_id) {
                    $update_appt_status = $conn->prepare("UPDATE appointments SET status = 'completed' WHERE appointment_id = :id");
                    $update_appt_status->execute([':id' => $appointment_id]);
                }
            }
            
            $conn->commit();

            // --- D. Send Email Confirmation ---
            if ($patient_email) {
                try {
                    $mail = new PHPMailer(true);
                    $mail->isSMTP();
                    $mail->Host = CONF_SMTP_HOST; 
                    $mail->SMTPAuth = true;
                    $mail->Username = CONF_SMTP_USER; 
                    $mail->Password = CONF_SMTP_PASS; 
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // Use SMTPS for port 465
                    $mail->Port = CONF_SMTP_PORT; 
                    $mail->setFrom(CONF_SMTP_USER, 'DentiTrack - Secretary'); 
                    $mail->addAddress($patient_email, $patient_name);
                    $mail->isHTML(true);
                    $mail->Subject = 'Payment Confirmation for Installment';

                    $status_message = $current_remaining > 0 
                                            ? "Your next installment payment is due soon." 
                                            : "Your installment plan for this service is now **fully paid**! All related balances have been settled.";

                    $body = "
                        <h2>Installment Payment Confirmation</h2>
                        <p>Dear {$patient_name},</p>
                        <p>This confirms the receipt of your payment for the installment plan related to the service: <strong>" . htmlspecialchars($service_name) . "</strong>.</p>
                        <ul>
                            <li>**Payment Amount Received:** ₱" . number_format($amount_received, 2) . "</li>
                            <li>**Payment Method:** " . htmlspecialchars($payment_method) . "</li>
                            <li>**Total Paid to Date (for this bill):** ₱" . number_format($total_paid, 2) . "</li>
                            <li>**Current Remaining Balance (for this bill):** ₱" . number_format($current_remaining, 2) . "</li>
                        </ul>
                        <p><strong>Status Update:</strong> {$status_message}</p>
                        <p>Thank you for your prompt payment.</p>
                        <p>DentiTrack Clinic Management</p>
                    ";
                    $mail->Body = $body;
                    $mail->send();
                    $message = "Payment recorded, and confirmation email sent to " . htmlspecialchars($patient_email) . ".";
                } catch (Exception $e) {
                    $message = "Payment recorded, but failed to send email: " . $e->getMessage();
                }
            } else {
                 $message = "Installment payment of ₱" . number_format($amount_received, 2) . " successfully recorded!";
            }
            
            // Redirect to clear POST data
            header("Location: pending_installments.php?message=" . urlencode($message));
            exit();

        } catch (Exception $e) {
            // Error was already captured inside the try block
            $error = $e->getMessage();
            // Fall through to display error message on the current page
        }
    }
}

// ===============================================
// 2. Fetch Pending Installment Records
// ===============================================
$search = $_GET['search'] ?? '';
$payments = [];

try {
    // Only show records that are installment plans AND still have an outstanding balance associated with the patient
    $where_clauses = ["p.payment_option = 'installment'", "pat.outstanding_balance > 0"]; 
    $params = [];
    
    if ($search) {
        $search_pattern = '%' . $search . '%';
        $where_clauses[] = "(CONCAT(pat.first_name, ' ', pat.last_name) LIKE :search OR s.service_name LIKE :search)";
        $params[':search'] = $search_pattern;
    }
    
    $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
    
    $sql = "
        SELECT 
            p.payment_id, 
            pat.patient_id,
            CONCAT(pat.first_name, ' ', pat.last_name) AS patient_name,
            pat.outstanding_balance,
            s.service_name,
            p.total_amount,
            p.monthly_payment,
            p.installment_term,
            p.payment_date,
            (SELECT SUM(amount_received) FROM payment_transactions WHERE payment_id = p.payment_id) AS total_paid
        FROM payments p
        JOIN patient pat ON p.patient_id = pat.patient_id
        LEFT JOIN services s ON p.service_id = s.service_id
        {$where_sql}
        ORDER BY pat.outstanding_balance DESC, p.payment_date ASC
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate remaining balance per bill and outstanding patient balance in PHP
    foreach ($payments as &$record) {
        $total_paid = floatval($record['total_paid'] ?? 0);
        $record['total_paid'] = $total_paid;
        
        // This is the actual remaining balance *for this specific bill* (used for max input limit in JS)
        $record['bill_remaining'] = max(0, floatval($record['total_amount']) - $total_paid); 
        
        // This is the overall patient balance
        $record['patient_outstanding_balance'] = floatval($record['outstanding_balance']);
    }
    unset($record); // Break the reference

} catch (Exception $e) {
    $error = "Database error: " . $e->getMessage();
    $payments = [];
}

// Set messages from GET
if (isset($_GET['message'])) { $message = htmlspecialchars($_GET['message']); }
if (isset($_GET['error'])) { $error = htmlspecialchars($_GET['error']); }

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pending Installments - DentiTrack</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous">
    <style>
        /* CSS Variables */
        :root {
            --sidebar-width: 240px;
            --primary-blue: #007bff;
            --secondary-blue: #0056b3;
            --text-dark: #343a40;
            --text-light: #6c757d;
            --bg-page: #eef2f8; 
            --widget-bg: #ffffff;
            --alert-red: #dc3545;
            --success-green: #28a745;
            --accent-orange: #ff851b; /* Custom orange for payments/installments */
        }

        /* Base Styles */
        body { 
            margin: 0; 
            font-family: 'Inter', sans-serif; 
            background: var(--bg-page); 
            color: var(--text-dark); 
            min-height: 100vh; 
            display: flex; 
            overflow-x: hidden; 
        }

        /* --- Sidebar Styling (Consistent Layout) --- */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--widget-bg);
            padding: 0;
            color: var(--text-dark);
            box-shadow: 2px 0 10px rgba(0,0,0,0.05);
            display: flex;
            flex-direction: column;
            position: fixed;
            height: 100vh;
            z-index: 1000;
        }
        .sidebar-header {
            padding: 25px;
            text-align: center;
            margin-bottom: 20px;
            font-size: 22px;
            font-weight: 700;
            color: var(--primary-blue);
            border-bottom: 1px solid #e9ecef;
        }
        
        .sidebar-nav {
            flex-grow: 1; 
            padding: 0 15px;
            overflow-y: auto; 
            
            /* --- HIDDEN SCROLLBAR CSS --- */
            -ms-overflow-style: none;   
            scrollbar-width: none;      
        }
        .sidebar-nav::-webkit-scrollbar {
            display: none;
        }
        
        .sidebar a { 
            display: flex; align-items: center; gap: 12px; 
            padding: 12px 15px; margin: 8px 0; 
            color: var(--text-dark); font-weight: 500; 
            text-decoration: none; border-radius: 8px;
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        .sidebar a:hover { 
            background-color: rgba(0, 123, 255, 0.08); color: var(--primary-blue);
        }
        .sidebar a.active { 
            background-color: var(--primary-blue); color: white; font-weight: 600;
            box-shadow: 0 4px 8px rgba(0, 123, 255, 0.2);
        }
        .sidebar a i { font-size: 18px; color: var(--text-light); transition: color 0.3s ease; }
        .sidebar a.active i { color: white; }
        .sidebar a:hover i { color: var(--primary-blue); }

        /* Logout button style */
        .sidebar .logout {
            margin-top: auto; 
            border-top: 1px solid #e9ecef;
            padding: 12px 15px; 
            margin: 8px 15px; 
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: var(--text-dark);
            font-weight: 500;
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        .sidebar .logout:hover { 
            background-color: #fce8e8; 
            color: var(--alert-red); 
        }
        .sidebar .logout i {
            color: var(--text-light);
            transition: color 0.3s ease;
        }
        .sidebar .logout:hover i {
            color: var(--alert-red);
        }

        /* --- Main Content --- */
        .main-content { 
            flex: 1; margin-left: var(--sidebar-width); 
            padding: 40px; background: var(--bg-page); 
            overflow-y: auto; 
        }
        header h1 { 
            font-size: 2.2rem; font-weight: 900; 
            color: var(--primary-blue); margin-bottom: 30px;
            display: flex; align-items: center; gap: 15px;
        }

        /* Messages */
        .flash-message {
            padding: 15px 20px; border-radius: 8px;
            font-weight: 600; font-size: 1rem;
            display: flex; align-items: center; gap: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .flash-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .flash-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        /* Filter Section */
        .filter-section { 
            display: flex; gap: 15px; margin-bottom: 30px;
            background: var(--widget-bg); padding: 15px; border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.05);
        }
        .filter-section input[type="text"] {
            padding: 10px 15px; border: 1px solid #ced4da; border-radius: 8px; 
            font-size: 14px; flex-grow: 1;
        }
        .btn-primary { 
            background-color: var(--primary-blue); color: white; border: none; 
            padding: 10px 15px; border-radius: 8px; font-weight: 600; cursor: pointer;
            transition: background-color 0.3s;
        }
        .btn-primary:hover { background-color: var(--secondary-blue); }

        /* Table Styles (Clean Alignment) */
        .data-table { 
            width: 100%; border-collapse: separate; border-spacing: 0; 
            background-color: white; 
            border-radius: 12px; overflow: hidden; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.1); 
        }
        .data-table th, .data-table td { 
            padding: 15px; 
            border-bottom: 1px solid #eee; 
            font-size: 0.9rem;
        }
        .data-table th { 
            background-color: var(--primary-blue); color: white; font-weight: 600; 
            text-transform: uppercase; font-size: 0.8rem;
        }
        .data-table tr:hover { background-color: #f5f8fc; }

        /* Column Alignment */
        .data-table td:nth-child(3), /* Monthly Due */
        .data-table td:nth-child(4)  /* Patient Outstanding Balance */
        {
            text-align: right;
        }
        .data-table th:nth-child(3), .data-table th:nth-child(4) {
            text-align: right;
        }
        .data-table td:last-child {
            text-align: center;
        }
        
        .btn-action { 
            padding: 8px 15px; background-color: var(--accent-orange); color: white; 
            border: none; border-radius: 6px; cursor: pointer; font-size: 0.85rem;
            transition: background-color 0.3s; display: inline-flex; align-items: center; gap: 5px;
        }
        .btn-action:hover { background-color: #e5730a; }

        .balance-due { font-weight: bold; color: var(--alert-red); }
        .highlight { font-weight: bold; color: var(--primary-blue); }

        /* MODAL Styles */
        .modal-overlay {
            display: none; position: fixed; z-index: 1000; left: 0; top: 0;
            width: 100%; height: 100%; background-color: rgba(0,0,0,0.6);
            justify-content: center; align-items: center;
        }
        .modal-content {
            background-color: var(--widget-bg); padding: 30px; border-radius: 12px;
            width: 450px; box-shadow: 0 10px 30px rgba(0,0,0,0.4); position: relative;
            animation: fadeIn 0.3s ease-out;
        }
        .close-btn { 
            position: absolute; top: 15px; right: 20px; font-size: 30px; 
            cursor: pointer; color: var(--text-light); 
        }
        .form-group input, .form-group select {
            width: 100%; padding: 10px 12px; border: 1px solid #ced4da; 
            border-radius: 6px; box-sizing: border-box;
        }

        /* --- NEW: Loading/Success Popup Styles --- */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.8);
            display: none; /* Hidden by default */
            justify-content: center;
            align-items: center;
            z-index: 2000;
        }
        .loading-box {
            background: var(--widget-bg);
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.2);
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }
        .loading-spinner, .success-checkmark {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            margin-bottom: 15px;
        }
        .loading-spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid var(--primary-blue);
            animation: spin 1s linear infinite;
        }
        .success-checkmark {
            background: var(--success-green);
            color: white;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 30px;
            animation: scaleIn 0.3s ease-out;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        @keyframes scaleIn {
            0% { transform: scale(0); }
            100% { transform: scale(1); }
        }

    </style>
</head>
<body>
<div class="sidebar">
    <div class="sidebar-header">
        <i class="fas fa-tooth"></i> DentiTrack
    </div>
    <nav class="sidebar-nav">
        <a href="secretary_dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
        <a href="find_patient.php"><i class="fas fa-search"></i> Find Patient</a>
        <a href="view_patients.php"><i class="fas fa-users"></i> Patients List</a>
        <a href="online_bookings.php"><i class="fas fa-calendar-check"></i> Booking Mgmt</a>
        <a href="appointments.php"><i class="fas fa-calendar-alt"></i> Consultations</a>
        <a href="services_list.php"><i class="fas fa-briefcase-medical"></i> Services</a>
        <a href="inventory.php"><i class="fas fa-boxes"></i> Inventory Stock</a>
        <a href="payments.php"><i class="fas fa-money-bill-wave"></i> Process Payments</a>
        <a href="pending_installments.php" class="active"><i class="fas fa-credit-card"></i> Pending Installments</a>
        <a href="pending_payments.php"><i class="fas fa-credit-card"></i> Pending Payments</a>
        <a href="payment_logs.php"><i class="fas fa-file-invoice-dollar"></i> Payments Log</a>
        <a href="create_announcements.php"><i class="fas fa-bullhorn"></i> Announcements</a>
    </nav>
    <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Log Out</a>
</div>

<main class="main-content">
    <header><h1><i class="fas fa-credit-card"></i> Pending Installment Payments</h1></header>
    <hr style="border: 0; border-top: 1px solid #ddd; margin-bottom: 30px;">
    
    <?php if ($message): ?><div class="flash-message flash-success"><i class="fas fa-check-circle"></i> <?= $message ?></div><?php endif; ?>
    <?php if ($error): ?><div class="flash-message flash-error"><i class="fas fa-exclamation-triangle"></i> <?= $error ?></div><?php endif; ?>

    <div class="filter-section">
        <form method="GET" style="display: flex; flex-grow: 1; gap: 15px;">
            <input type="text" name="search" placeholder="Search Patient Name or Service..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="btn-primary"><i class="fas fa-search"></i> Search</button>
            <a href="pending_installments.php" class="btn-primary" style="background-color: #6c757d; text-decoration: none;"><i class="fas fa-undo"></i> Reset</a>
        </form>
    </div>

    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 25%;">Patient Name</th>
                    <th style="width: 30%;">Service Billed</th>
                    <th style="width: 10%; text-align: right;">Monthly Due</th>
                    <th style="width: 15%; text-align: right;">Outstanding Balance</th>
                    <th style="width: 10%;">Original Date</th>
                    <th style="width: 10%; text-align: center;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($payments)): ?>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td class="highlight"><?= htmlspecialchars($p['patient_name']) ?></td>
                            <td><?= htmlspecialchars($p['service_name']) ?> (₱<?= number_format($p['total_amount'], 2) ?>)</td>
                            <td style="text-align: right;" class="highlight">₱<?= number_format($p['monthly_payment'], 2) ?></td>
                            <td style="text-align: right;" class="balance-due">₱<?= number_format($p['patient_outstanding_balance'], 2) ?></td>
                            <td><?= date('M d, Y', strtotime($p['payment_date'])) ?></td>
                            <td style="text-align: center;">
                                <button class="btn-action"
                                    onclick="openPaymentModal(
                                            <?= $p['payment_id'] ?>,
                                            <?= $p['patient_id'] ?>,
                                            '<?= htmlspecialchars($p['patient_name']) ?>',
                                            <?= $p['monthly_payment'] ?>,
                                            <?= $p['patient_outstanding_balance'] ?>,
                                            <?= $p['bill_remaining'] ?>
                                        )">
                                    <i class="fas fa-hand-holding-usd"></i> Record Payment
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6" style="text-align:center; padding: 20px; color:var(--text-light);">No patients with pending installments found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>

<div id="loadingOverlay" class="loading-overlay">
    <div class="loading-box">
        <div id="loadingIcon" class="loading-spinner"></div>
        <div id="successIcon" class="success-checkmark" style="display: none;"><i class="fas fa-check"></i></div>
        <p id="loadingMessage" style="font-weight: 600; color: var(--text-dark);">Processing payment...</p>
    </div>
</div>

<div id="paymentModal" class="modal-overlay">
    <div class="modal-content">
        <span class="close-btn" onclick="closeModal()">&times;</span>
        <h3>Record Installment Payment</h3>
        <p id="modal_patient_info" style="font-weight: 600;"></p>
        <p>Outstanding Patient Balance: <span id="modal_patient_balance" class="balance-due"></span></p>
        
        <hr>

        <form method="POST" onsubmit="return showLoading(event)"> 
            <input type="hidden" name="payment_id" id="modal_payment_id">
            <input type="hidden" name="patient_id" id="modal_patient_id">
            <input type="hidden" name="record_installment_payment" value="1">

            <div class="form-group">
                <label for="amount_received">Amount Received</label>
                <input type="number" step="0.01" min="0.01" name="amount_received" id="modal_amount_received" required>
                <small style="color:var(--text-light);">*Recommended monthly amount will be set as default.</small>
            </div>
            
            <div class="form-group">
                <label for="payment_method">Payment Method</label>
                <select name="payment_method" id="modal_payment_method"> 
                    <option value="Cash">Cash</option>
                    <option value="Online">Online Transfer</option>
                    <option value="Card">Card</option>
                </select>
            </div>

            <div class="payment-modal-actions">
                <button type="submit" class="btn-primary" style="background-color: var(--success-green);"><i class="fas fa-save"></i> Process Payment</button>
            </div>
        </form>
    </div>
</div>

<script>
    const paymentModal = document.getElementById('paymentModal');

    function formatCurrency(amount) {
        const num = parseFloat(amount);
        if (isNaN(num)) return '₱0.00';
        return '₱' + num.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    }

    // --- NEW: Loading/Success Functions ---
    function showLoading(event) {
        // This function runs on form submit.
        const form = event.target;
        if (!form.checkValidity()) {
            return true; // Let browser show validation errors
        }
        
        document.getElementById('loadingOverlay').style.display = 'flex';
        document.getElementById('loadingIcon').style.display = 'block';
        document.getElementById('successIcon').style.display = 'none';
        document.getElementById('loadingMessage').innerText = 'Processing payment...';
        
        // Close the payment modal immediately underneath the overlay
        closeModal();
        
        // Allow the form to submit normally after showing the overlay
        return true; 
    }

    function showSuccessAndRefresh(message) {
        const overlay = document.getElementById('loadingOverlay');
        document.getElementById('loadingIcon').style.display = 'none';
        document.getElementById('successIcon').style.display = 'flex';
        document.getElementById('loadingMessage').innerText = message;
        overlay.style.display = 'flex';
        
        // Wait 1.5 seconds, then refresh the page to clear the state/show message banner
        setTimeout(() => {
            // Remove the URL parameters before reloading for a clean UI
            const newUrl = window.location.pathname;
            window.location.href = newUrl;
        }, 1500);
    }
    
    function closeModal() {
        paymentModal.style.display = 'none';
    }
    
    // UPDATED JS FUNCTION - Removed references to upload/proof logic
    function openPaymentModal(paymentId, patientId, patientName, monthlyPayment, outstandingBalance, billRemaining) {
        document.getElementById('modal_payment_id').value = paymentId;
        document.getElementById('modal_patient_id').value = patientId;
        document.getElementById('modal_patient_info').innerText = 'Patient: ' + patientName;
        
        // Set recommended monthly payment amount as default value
        const amountInput = document.getElementById('modal_amount_received');
        amountInput.value = monthlyPayment.toFixed(2);
        
        // Use billRemaining to set max input limit (important for transaction integrity)
        amountInput.max = billRemaining.toFixed(2); 

        document.getElementById('modal_patient_balance').innerText = formatCurrency(outstandingBalance);
        
        // Ensure payment method is reset to Cash as default
        document.getElementById('modal_payment_method').value = 'Cash'; 
        
        paymentModal.style.display = 'flex';
    }

    
    // Close modal on outside click
    window.onclick = function(event) {
        if (event.target == paymentModal) {
            closeModal();
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        // --- Check for success message in URL (for redirect handling) ---
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('message') && !urlParams.has('error')) {
            const successMessage = urlParams.get('message');
            // Remove the URL message/error params for a cleaner history state
            const newUrl = window.location.pathname;
            history.replaceState(null, null, newUrl);
            
            // Trigger success animation and refresh
            showSuccessAndRefresh(successMessage);
        }
    });
</script>
</body>
</html>