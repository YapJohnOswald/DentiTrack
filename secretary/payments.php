<?php
session_start();
// Include the database connections
include '../config/db_pdo.php';
include '../config/db_conn.php'; 

// --- PHPMailer Includes (Required for Email Reminders) ---
require '../PHPMailer-master/src/PHPMailer.php';
require '../PHPMailer-master/src/SMTP.php';
require '../PHPMailer-master/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// =======================================================
// FIX: SMTP CONFIGURATION 
// =======================================================
if (!defined('CONF_SMTP_HOST')) define('CONF_SMTP_HOST', 'smtp.gmail.com');
if (!defined('CONF_SMTP_PORT')) define('CONF_SMTP_PORT', 465); 
if (!defined('CONF_SMTP_USER')) define('CONF_SMTP_USER', 'dentitrack2025@gmail.com');       
if (!defined('CONF_SMTP_PASS')) define('CONF_SMTP_PASS', 'gpmennmjrynhujzq');           
// =======================================================

// Set timezone for consistency 
date_default_timezone_set('Asia/Manila');

// Ensure PDO throws exceptions and normalize connection variable to $conn
if (isset($pdo)) { 
    $conn = $pdo; 
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} elseif (isset($conn) && $conn instanceof PDO) { 
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} else {
    $error = "Database connection failed.";
}

// Check secretary role
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'secretary') {
    header('Location: ../public/login.php');
    exit();
}

// =======================================================
// --- AJAX Endpoints ---
// =======================================================
if (isset($_GET['action']) && $_GET['action'] === 'fetch_patient_services' && isset($_GET['patient_id']) && isset($conn)) {
    $patient_id = intval($_GET['patient_id']);
    $response = ['success' => false, 'data' => []];
    try {
        $stmt = $conn->prepare("
            SELECT a.appointment_id, a.service_id, a.appointment_date, s.service_name, s.price, a.status, p.payment_id, p.downpayment,
            (SELECT SUM(amount_received) FROM payment_transactions WHERE payment_id = p.payment_id) AS total_paid
            FROM appointments a
            JOIN services s ON a.service_id = s.service_id
            LEFT JOIN payments p ON a.appointment_id = p.appointment_id
            WHERE a.patient_id = :patient_id 
              AND a.status IN ('scheduled', 'rescheduled', 'approved', 'booked', 'completed', 'pending_installment') 
            ORDER BY a.appointment_date DESC
        ");
        $stmt->execute([':patient_id' => $patient_id]);
        $response['success'] = true;
        $response['data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $response['error'] = $e->getMessage(); }
    header('Content-Type: application/json');
    echo json_encode($response); exit; 
}

if (isset($_GET['action']) && $_GET['action'] === 'fetch_all_payment_status' && isset($conn)) {
    $filter = $_GET['filter'] ?? 'all';
    $search = $_GET['search'] ?? '';
    $where_clauses = ["p.total_amount > 0"];
    $params = [];
    if ($filter === 'full') { $where_clauses[] = "p.payment_option = 'full'"; $where_clauses[] = "p.status = 'paid'"; }
    elseif ($filter === 'installment') { $where_clauses[] = "p.payment_option = 'installment'"; }
    if ($search) {
        $search_pattern = '%' . $search . '%';
        $where_clauses[] = "(CONCAT(pat.first_name, ' ', pat.last_name) LIKE ? OR s.service_name LIKE ?)";
        $params[] = $search_pattern; $params[] = $search_pattern;
    }
    $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
    $sql = "SELECT p.payment_id, CONCAT(pat.first_name, ' ', pat.last_name) AS patient_name, pat.patient_id, pat.email AS patient_email, pat.outstanding_balance, s.service_name, p.total_amount, p.payment_option, p.downpayment, p.monthly_payment, p.installment_term, p.status, p.payment_date, (SELECT SUM(amount_received) FROM payment_transactions WHERE payment_id = p.payment_id) AS total_paid FROM payments p JOIN patient pat ON p.patient_id = pat.patient_id LEFT JOIN services s ON p.service_id = s.service_id {$where_sql} ORDER BY p.payment_date DESC";
    try {
        $stmt = $conn->prepare($sql); $stmt->execute($params); $payments_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($payments_data as &$record) { $record['remaining_balance'] = floatval($record['outstanding_balance'] ?? 0); }
        echo json_encode(['success' => true, 'data' => array_values($payments_data)]);
    } catch (Exception $e) { echo json_encode(['success' => false, 'error' => $e->getMessage()]); }
    exit;
}

$message = isset($_GET['message']) ? htmlspecialchars($_GET['message']) : '';
$error = isset($_GET['error']) ? htmlspecialchars($_GET['error']) : ''; 

// --- ONLINE BOOKING INITIALIZATION ---
$online_booking_mode = false;
$online_booking_patient_id = $online_booking_service_id = $online_booking_appointment_id = null;
if (isset($_GET['online_booking_process']) && $_GET['online_booking_process'] == 1) {
    $online_booking_mode = true;
    $online_booking_patient_id = intval($_GET['patient_id']);
    $online_booking_service_id = intval($_GET['service_id']);
    $online_booking_appointment_id = intval($_GET['appointment_id']);
}

// --- DATA FETCHING ---
if (isset($conn)) {
    try {
        $patients = $conn->query("SELECT patient_id, outstanding_balance, email, CONCAT(first_name, ' ', last_name) AS name FROM patient ORDER BY first_name ASC")->fetchAll(PDO::FETCH_ASSOC);
        $services = $conn->query("SELECT service_id, service_name, price FROM services ORDER BY service_name ASC")->fetchAll(PDO::FETCH_ASSOC);
        $supplies = $conn->query("SELECT supply_id, name, quantity, unit FROM dental_supplies ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $patients = $services = $supplies = []; }
}

// =======================================================
// --- MAIN FORM PROCESSING ---
// =======================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
    
    // 1. HANDLE INSTALLMENT REMINDERS
    if (isset($_POST['remind_installment_payment'])) {
        $payment_id = $_POST['payment_id'] ?? null;
        if ($payment_id && isset($conn)) {
            try {
                $stmt = $conn->prepare("SELECT p.total_amount, p.monthly_payment, pat.email AS patient_email, CONCAT(pat.first_name, ' ', pat.last_name) AS patient_name, s.service_name, p.installment_term, (SELECT SUM(amount_received) FROM payment_transactions WHERE payment_id = p.payment_id) AS total_paid FROM payments p JOIN patient pat ON p.patient_id = pat.patient_id LEFT JOIN services s ON p.service_id = s.service_id WHERE p.payment_id = :payment_id");
                $stmt->execute([':payment_id' => $payment_id]);
                $data = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($data && $data['patient_email']) {
                    $mail = new PHPMailer(true);
                    $mail->isSMTP(); $mail->Host = CONF_SMTP_HOST; $mail->SMTPAuth = true; $mail->Username = CONF_SMTP_USER; $mail->Password = CONF_SMTP_PASS; $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; $mail->Port = CONF_SMTP_PORT;
                    $mail->setFrom(CONF_SMTP_USER, 'DentiTrack'); $mail->addAddress($data['patient_email']); $mail->isHTML(true); $mail->Subject = 'Payment Reminder';
                    $mail->Body = "<h2>Reminder</h2><p>Dear {$data['patient_name']}, please settle your balance for " . htmlspecialchars($data['service_name']) . ".</p>";
                    $mail->send(); $message = 'Reminder sent.';
                }
            } catch (Exception $e) { $error = "Mail error: " . $e->getMessage(); }
            header("Location: payments.php?message=" . urlencode($message)); exit();
        }
    }
    
    // 2. CAPTURE PAYMENT DATA & TRANSACTION ID
    $transaction_id = trim($_POST['transaction_id'] ?? ''); // ADDED: Matches patient_payment.php
    $patient_id = intval($_POST['patient_id'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? 'Cash'; 
    $payment_option = $_POST['payment_option'] ?? 'full'; 
    $service_id = intval($_POST['service_id_actual'] ?? 0); 
    $appointment_id = $online_booking_mode ? $online_booking_appointment_id : (intval($_POST['appointment_id_hidden'] ?? 0) ?: null);

    $base_amount = floatval($_POST['base_amount'] ?? 0); 
    $discount_type = $_POST['discount_type'] ?? 'none';
    $downpayment = floatval($_POST['downpayment'] ?? 0); 
    $existing_credit = floatval($_POST['existing_credit_hidden'] ?? 0); 
    $installment_term = intval($_POST['installment_term'] ?? 1);
    $monthly_payment = floatval($_POST['monthly_payment'] ?? 0);
    $used_supplies = $_POST['supply_id'] ?? [];
    $used_quantities = $_POST['used_quantity'] ?? [];
    $recorded_by_user_id = $_SESSION['user_id'] ?? null; 

    $discount = ($discount_type === 'loyalty') ? $base_amount * 0.10 : (($discount_type === 'health') ? $base_amount * 0.15 : 0);
    $total_amount_billed = $base_amount - $discount; 
    $final_payment_amount = $downpayment; 
    $total_credit_applied = $existing_credit + $final_payment_amount;
    $status = ($total_amount_billed <= $total_credit_applied) ? 'paid' : 'pending';
    
    // 3. DATABASE TRANSACTION
    if ($patient_id > 0 && $service_id > 0) {
        try {
            $conn->beginTransaction();

            // FETCH PATIENT INFO
            $stmt_pat = $conn->prepare("SELECT email, CONCAT(first_name, ' ', last_name) AS name FROM patient WHERE patient_id = :id");
            $stmt_pat->execute([':id' => $patient_id]);
            $pat_data = $stmt_pat->fetch(PDO::FETCH_ASSOC);
            $patient_email = $pat_data['email'] ?? null;
            $patient_name = $pat_data['name'] ?? 'Patient';

            // PREPARE SUPPLIES JSON
            $supplies_used_arr = [];
            foreach ($used_supplies as $idx => $s_id) {
                if ($s_id > 0) $supplies_used_arr[] = ['id' => $s_id, 'qty' => $used_quantities[$idx]];
            }

            // INSERT/UPDATE BILL (PAYMENTS TABLE)
            $payment_id = null;
            $stmt_check = $conn->prepare("SELECT payment_id FROM payments WHERE appointment_id = :aid");
            $stmt_check->execute([':aid' => $appointment_id]);
            $existing_payment_id = $stmt_check->fetchColumn();

            if ($existing_payment_id) {
                $payment_id = $existing_payment_id;
                $stmt = $conn->prepare("UPDATE payments SET amount=:a, total_amount=:ta, payment_method=:pm, transaction_id=:tid, payment_option=:po, status=:st, supplies_used=:su WHERE payment_id=:pid");
                $stmt->execute([':a'=>$base_amount, ':ta'=>$total_amount_billed, ':pm'=>$payment_method, ':tid'=>$transaction_id, ':po'=>$payment_option, ':st'=>$status, ':su'=>json_encode($supplies_used_arr), ':pid'=>$payment_id]);
            } else {
                $stmt = $conn->prepare("INSERT INTO payments (patient_id, service_id, appointment_id, amount, total_amount, payment_method, transaction_id, payment_option, downpayment, installment_term, monthly_payment, status, supplies_used, payment_date, created_at, user_id) VALUES (:pid, :sid, :aid, :a, :ta, :pm, :tid, :po, 0, :it, :mp, :st, :su, NOW(), NOW(), :uid)");
                $stmt->execute([':pid'=>$patient_id, ':sid'=>$service_id, ':aid'=>$appointment_id, ':a'=>$base_amount, ':ta'=>$total_amount_billed, ':pm'=>$payment_method, ':tid'=>$transaction_id, ':po'=>$payment_option, ':it'=>($payment_option==='installment'?$installment_term:0), ':mp'=>($payment_option==='installment'?$monthly_payment:0), ':st'=>$status, ':su'=>json_encode($supplies_used_arr), ':uid'=>$recorded_by_user_id]);
                $payment_id = $conn->lastInsertId();
            }

            // LOG SPECIFIC TRANSACTION (PAYMENT_TRANSACTIONS TABLE)
            if ($final_payment_amount > 0) {
                $payment_proof_path = NULL;
                if ($payment_method !== 'Cash' && isset($_FILES['payment_proof']) && $_FILES['payment_proof']['error'] == 0) {
                    $new_name = "proof_" . uniqid() . "." . pathinfo($_FILES['payment_proof']['name'], PATHINFO_EXTENSION);
                    move_uploaded_file($_FILES['payment_proof']['tmp_name'], "../uploads/payment_proofs/" . $new_name);
                    $payment_proof_path = "../uploads/payment_proofs/" . $new_name;
                }
                $stmt = $conn->prepare("INSERT INTO payment_transactions (payment_id, patient_id, amount_received, transaction_date, payment_method, transaction_id, payment_proof_path, recorded_by_user_id) VALUES (:pay_id, :pat_id, :amt, NOW(), :meth, :tid, :proof, :uid)");
                $stmt->execute([':pay_id'=>$payment_id, ':pat_id'=>$patient_id, ':amt'=>$final_payment_amount, ':meth'=>$payment_method, ':tid'=>$transaction_id, ':proof'=>$payment_proof_path, ':uid'=>$recorded_by_user_id]);
            }

            // UPDATE INVENTORY & PATIENT BALANCE
            if (!$existing_payment_id) {
                foreach ($supplies_used_arr as $s) {
                    $conn->prepare("UPDATE dental_supplies SET quantity = quantity - :q WHERE supply_id = :id")->execute([':q'=>$s['qty'], ':id'=>$s['id']]);
                    $conn->prepare("INSERT INTO supply_usage (supply_id, patient_id, payment_id, quantity_used, used_at, user_id) VALUES (?,?,?,?,NOW(),?)")->execute([$s['id'], $patient_id, $payment_id, $s['qty'], $recorded_by_user_id]);
                }
            }
            $conn->prepare("UPDATE patient SET outstanding_balance = outstanding_balance - :amt WHERE patient_id = :id")->execute([':amt'=>$final_payment_amount, ':id'=>$patient_id]);
            if ($appointment_id) $conn->prepare("UPDATE appointments SET status = ? WHERE appointment_id = ?")->execute([($status === 'paid' ? 'completed' : 'pending_installment'), $appointment_id]);

            $conn->commit();
            header("Location: receipt.php?id=" . $payment_id);
            exit();
        } catch (Exception $e) { $conn->rollBack(); $error = "Error: " . $e->getMessage(); }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Payments - DentiTrack</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous">

<style>
    /* CSS Variables for a clean, consistent color scheme */
    :root {
        --sidebar-width: 240px;
        --primary-blue: #007bff;
        --secondary-blue: #0056b3;
        --text-dark: #343a40;
        --text-light: #6c757d;
        --bg-light: #f8f9fa;
        --bg-page: #eef2f8; /* Light blue/gray background */
        --widget-bg: #ffffff;
        --alert-red: #dc3545;
        --success-green: #28a745;
        --accent-orange: #ffc107;
    }

    /* Base Styles */
    html { scroll-behavior: smooth; }
    body { 
        margin: 0; 
        font-family: 'Inter', sans-serif; 
        background: var(--bg-page); 
        color: var(--text-dark); 
        min-height: 100vh; 
        display: flex; 
        overflow-x: hidden; 
    }

    /* --- Sidebar Styling (Stable & Fixed) --- */
    .sidebar { 
        width: var(--sidebar-width); 
        background: var(--widget-bg); 
        padding: 0; 
        color: var(--text-dark); 
        box-shadow: 2px 0 10px rgba(0,0,0,0.05); 
        display: flex; 
        flex-direction: column; 
        position: fixed; /* Makes the sidebar stable/fixed */
        height: 100vh;
        z-index: 1000;
        transition: transform 0.3s ease;
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
        flex-grow: 1; /* Allows navigation links to fill space */
        padding: 0 15px;
        overflow-y: auto; /* Enables automatic scrolling */
        
        /* --- HIDDEN SCROLLBAR CSS --- */
        -ms-overflow-style: none;  /* IE and Edge */
        scrollbar-width: none;     /* Firefox */
    }
    /* Chrome, Safari, and Opera scrollbar hiding */
    .sidebar-nav::-webkit-scrollbar {
        display: none;
    }
    /* --- End HIDDEN SCROLLBAR CSS --- */

    .sidebar a { 
        display: flex; 
        align-items: center; 
        gap: 12px; 
        padding: 12px 15px; 
        margin: 8px 0; 
        color: var(--text-dark); 
        text-decoration: none; 
        font-weight: 500; 
        border-radius: 8px;
        transition: background-color 0.3s ease, color 0.3s ease;
    }
    .sidebar a:hover { 
        background-color: rgba(0, 123, 255, 0.08); /* Light hover effect */
        color: var(--primary-blue);
    }
    .sidebar a.active { 
        background-color: var(--primary-blue);
        color: white; 
        font-weight: 600;
        box-shadow: 0 4px 8px rgba(0, 123, 255, 0.2);
    }
    .sidebar a.active i {
        color: white; /* Ensures icon is white when link is active */
    }
    .sidebar a i {
        font-size: 18px;
        color: var(--text-light);
        transition: color 0.3s ease;
    }
    .sidebar a:hover i {
        color: var(--primary-blue);
    }
    .logout {
        margin-top: auto; /* Push logout to the bottom */
        border-top: 1px solid #e9ecef;
        padding: 15px;
    }


    /* --- Main Content Styling --- */
    .main-content { 
        flex: 1; 
        margin-left: var(--sidebar-width); /* Compensate for the fixed sidebar */
        padding: 40px 50px; 
        background: var(--bg-page); 
        overflow-y: auto; 
    }
    header h1{font-size:2.3rem;font-weight:900;color:#004080;display:flex;align-items:center;gap:15px;}
    .flash-message{max-width:700px;margin:15px auto;padding:16px 20px;border-radius:15px;font-weight:700;text-align:center;}
    .flash-success{background:#d4edda;color:#155724;}
    .flash-error{background:#f8d7da;color:#721c24;}
    form#payment-form{background:var(--widget-bg);padding:30px;border-radius:20px;box-shadow:0 10px 25px rgba(0,0,0,0.1);max-width:700px;margin:auto;}
    form#payment-form h2{text-align:center;color:var(--primary-blue);margin-bottom:20px;}
    .form-row{display:flex;flex-wrap:wrap;gap:20px;margin-bottom:15px;align-items:center;}
    .form-row label{flex:0 0 150px;font-weight:600;color:var(--text-dark);text-align:right;}
    .form-row select,.form-row input[type=number],.form-row input[type=text],.form-row input[type=file]{flex:1 1 250px;padding:8px 12px;border-radius:8px;border:1.5px solid #ced4da;font-weight:500;color:var(--text-dark);}
    .form-row input:focus, .form-row select:focus {border-color: var(--primary-blue); outline: none;}

    .supply-row-group {
        flex: 1 1 250px; 
        display: flex;
        gap: 10px;
        align-items: center;
    }
    .supply-row-group .supply-select {
        flex: 3; 
    }
    .supply-row-group .qty-input {
        flex: 1; 
    }
    .supply-row-group .remove-btn {
        flex: 0 0 40px; 
        background:var(--alert-red); 
        color:white; 
        border:none; 
        border-radius:8px; 
        padding:8px 0; 
        font-weight:700; 
        cursor:pointer; 
        font-size: 1rem;
    }

    form#payment-form button[type=submit]{display:block;margin:20px auto;padding:14px 40px;font-weight:700;font-size:1.1rem;border:none;border-radius:10px;background:var(--success-green);color:white;box-shadow:0 4px 10px rgba(40, 167, 69, 0.4);cursor:pointer;}
    form#payment-form button[type=submit]:hover{background:#1e7e34;}
    .supplies-card{background:var(--bg-light);border-radius:12px;padding:25px;margin-top:20px;box-shadow:0 2px 10px rgba(0,0,0,0.05);}
    .supplies-card h3{font-size:1.2rem;color:var(--text-dark);font-weight:700;margin-bottom:15px;display:flex;align-items:center;gap:10px;}
    #supplies-container{display:flex;flex-direction:column;gap:15px;} 
    .tab-navigation{display: flex; justify-content: center; margin-bottom: 25px;}
    .tab-button{padding: 10px 20px; border: none; background: #e9ecef; color: var(--text-dark); cursor: pointer; font-weight: 600; transition: background 0.3s; border-radius: 8px 8px 0 0; margin: 0 2px; font-size: 0.95rem;}
    .tab-button.active{background: var(--primary-blue); color: white; box-shadow: 0 -2px 5px rgba(0, 123, 255, 0.2);}
    
    .form-row input[readonly], .form-row select[disabled] {
        background-color: #f0f0f0;
        cursor: not-allowed;
    }
    .total-summary {
        background: var(--bg-light);
        padding: 15px;
        border-radius: 8px;
        margin-top: 10px;
        font-weight: bold;
        color: var(--text-dark);
    }
    .total-summary div {
        display: flex;
        justify-content: space-between;
        padding: 5px 0;
    }
    .total-summary .final-total {
        font-size: 1.3rem;
        border-top: 1px solid #dee2e6;
        margin-top: 10px;
        padding-top: 10px;
    }
    .payment-method {
        display: flex;
        gap: 20px;
    }
    .payment-method label {
        display: flex;
        align-items: center;
        gap: 5px;
        flex: none;
        font-weight: 500;
    }
    
    /* Table Styles for Status Tab (FIXED ALIGNMENT) */
    .table-container { 
        overflow-x: auto; /* Ensure horizontal scrolling for small screens */
        box-shadow: 0 4px 15px rgba(0,0,0,0.1); 
        border-radius: 12px;
    }
    .data-table { 
        width: 100%; 
        border-collapse: separate; 
        border-spacing: 0;
        background-color: white; 
        border-radius: 12px; 
        overflow: hidden; 
        min-width: 800px; /* Ensures columns don't compress too much */
    }
    .data-table th, .data-table td { 
        padding: 15px; 
        text-align: left; 
        border-bottom: 1px solid #eee; 
        font-size: 0.9rem;
    }
    .data-table th { 
        background-color: var(--primary-blue); 
        color: white; 
        font-weight: 600; 
        text-transform: uppercase; 
        font-size: 0.8rem;
    }
    .data-table tr:hover { background-color: #f5f8fc; }

    /* Column Sizing and Alignment */
    .data-table th:nth-child(4), .data-table td:nth-child(4), /* Net Billed */
    .data-table th:nth-child(5), .data-table td:nth-child(5), /* Total Paid */
    .data-table th:nth-child(6), .data-table td:nth-child(6)  /* Remaining */
    {
        width: 10%; 
        text-align: right;
    }
    .data-table th:last-child, .data-table td:last-child { /* Actions */
        width: 100px;
        text-align: center;
    }
    .data-table th:nth-child(3), .data-table td:nth-child(3) { /* Type */
        width: 12%;
    }
    
    .action-btn { padding: 8px 12px; margin: 2px; border: none; border-radius: 5px; cursor: pointer; font-size: 0.8em; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 5px; transition: background-color 0.3s; }
    .btn-remind { background-color: var(--accent-orange); color: white; }
    .btn-remind:hover { background-color: #e0a800; }

    /* Filter Section Styles */
    .filter-section input[type="text"], .filter-section select { padding: 10px 15px; border: 1px solid #ced4da; border-radius: 8px; font-size: 14px; }
    .btn-primary { background-color: var(--primary-blue); color: white; border: none; }
    
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
        <a href="payments.php" class="active"><i class="fas fa-money-bill-wave"></i> Process Payments</a>
        <a href="pending_installments.php"><i class="fas fa-credit-card"></i> Pending Installments</a>
        <a href="pending_payments.php"><i class="fas fa-credit-card"></i> Pending Payments</a>
        <a href="payment_logs.php"><i class="fas fa-file-invoice-dollar"></i> Payments Log</a> 
        <a href="create_announcements.php"><i class="fas fa-bullhorn"></i> Announcements</a> 
    </nav>
    <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Log Out</a> 
</div>

<main class="main-content"> 
    <header><h1><i class="fas fa-cash-register"></i> Record Payments</h1></header> 
    <hr style="border: 0; border-top: 1px solid #ddd;"> 

    <?php if ($message): ?> 
    <div class="flash-message flash-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?></div> 
    <?php elseif ($error): ?> 
    <div class="flash-message flash-error"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?></div> 
    <?php endif; ?> 

    <form id="payment-form" method="post" action="payments.php" novalidate enctype="multipart/form-data" onsubmit="return showLoading(event);"> 
        <h2>Process Payment for Service</h2> 
        <div class="tab-navigation"> 
            <button type="button" class="tab-button active" data-tab="new_service_tab"> 
                <i class="fas fa-plus-circle"></i> Process New Payment 
            </button> 
            <button type="button" class="tab-button" data-tab="status_reminder_tab">
                <i class="fas fa-info-circle"></i> Payment Status & Reminders
            </button>
        </div> 

        <input type="hidden" id="online_booking_mode" value="<?= $online_booking_mode ? '1' : '0' ?>">
        
        <div id="new_service_tab" class="tab-pane" style="display:block;"> 
            <div class="form-row"> 
                <label for="patient_id">Select Patient:</label> 
                <select name="patient_id" id="patient_id" onchange="populateServiceSelector(this.value)" <?= $online_booking_mode ? 'disabled' : '' ?>> 
                    <option value="">-- Select Patient --</option> 
                    <?php foreach ($patients as $p): ?> 
                    <option value="<?= $p['patient_id'] ?>" data-balance="<?= $p['outstanding_balance'] ?>" <?= ($online_booking_mode && $p['patient_id'] == $online_booking_patient_id) ? 'selected' : '' ?>> 
                        <?= htmlspecialchars($p['name']) ?> (Balance: ₱<?= number_format($p['outstanding_balance'], 2) ?>) 
                    </option> 
                    <?php endforeach; ?> 
                </select> 
                <?php if ($online_booking_mode): ?> 
                <input type="hidden" name="patient_id" value="<?= $online_booking_patient_id ?>"> 
                <?php endif; ?> 
            </div> 
            
            <div class="form-row"> 
                <label for="service_selector">Service/Appointment:</label> 
                <select id="service_selector" onchange="updateServicePriceAndTotal()"> 
                    <option value="" data-type="none" data-price="0">-- Select Appointment or New Service --</option>
                    <optgroup label="--- Pending Appointments (Requires Payment) ---" id="pending_appointments_optgroup" style="display:none;"></optgroup>
                    <optgroup label="--- Select New Service ---" id="new_services_optgroup">
                        <?php foreach ($services as $s): ?> 
                        <option value="<?= $s['service_id'] ?>" data-type="service" data-price="<?= $s['price'] ?>"> 
                            <?= htmlspecialchars($s['service_name']) ?> (₱<?= number_format($s['price'], 2) ?>) 
                        </option> 
                        <?php endforeach; ?> 
                    </optgroup>
                </select> 
                
                <input type="hidden" name="service_id_actual" id="service_id_actual" value="<?= $online_booking_mode ? $online_booking_service_id : '' ?>">
                <input type="hidden" name="appointment_id_hidden" id="appointment_id_hidden" value="<?= $online_booking_mode ? $online_booking_appointment_id : '' ?>">
            </div> 

            <div class="form-row"> 
                <label>Payment Method:</label> 
                <div class="payment-method"> 
                    <label><input type="radio" name="payment_method" value="Cash" checked onclick="toggleTransactionIdField()"> Cash</label> 
                    <label><input type="radio" name="payment_method" value="Online" onclick="toggleTransactionIdField()"> Online Transfer</label> 
                    <label><input type="radio" name="payment_method" value="Gcash" onclick="toggleTransactionIdField()"> Gcash</label> 
                    <label><input type="radio" name="payment_method" value="Card" onclick="toggleTransactionIdField()"> Card</label> 
                </div> 
            </div>

            <div class="form-row" id="transaction_id_container" style="display:none;">
                <label for="transaction_id">Transaction ID:</label>
                <div style="flex: 1;">
                    <input type="text" name="transaction_id" id="transaction_id" placeholder="">
                    <p class="form-help" style="font-size:0.85rem; color:var(--text-light); margin-top:5px;"></p>
                </div>
            </div>
            
            <div class="form-row payment-proof-upload" id="payment_proof_new_container" style="display:none;">
                <label for="payment_proof_new">Upload Proof:</label>
                <input type="file" name="payment_proof" id="payment_proof_new" accept="image/*,application/pdf">
            </div> 
            
            <div class="supplies-card" id="supplies-section"> 
                <h3><i class="fas fa-boxes"></i> Supplies Used (Tracked for Inventory)</h3> 
                <div id="supplies-container"></div>
                <button type="button" onclick="addSupplyRow()" style="margin-top:15px; padding: 10px 20px; border-radius: 8px; background: var(--success-green); color: white; border: none; font-weight: 600;"><i class="fas fa-plus"></i> Add Supply</button>
            </div>
            
            <hr style="margin: 25px 0;">

            <div class="form-row">
                <label for="discount_type">Discount:</label>
                <select name="discount_type" id="discount_type" onchange="updateServicePriceAndTotal()">
                    <option value="none">None</option>
                    <option value="loyalty">Loyalty (10%)</option>
                    <option value="health">Health Card (15%)</option>
                </select>
            </div>

            <div class="total-summary" style="background:#eaf3ff; border: 1px solid #cce0ff;">
                <h3 style="margin:0 0 10px 0; color:var(--primary-blue);">Payment Breakdown</h3>
                <div class="breakdown-row">
                    <span>Service Price:</span>
                    <span id="summary_service_price">₱0.00</span>
                </div>
                <div class="breakdown-row" style="font-size: 1.0rem; font-weight: bold; border-top: 1px dashed #004080; padding-top: 5px; margin-top: 5px;">
                    <span>Gross Amount Billed:</span>
                    <span id="summary_gross_amount">₱0.00</span>
                </div>
                <div class="breakdown-row" style="color:var(--alert-red); border-bottom: 1px solid #eee;">
                    <span>Discount Deduction:</span>
                    <span id="summary_discount">- ₱0.00</span>
                </div>
            </div>
            
            <div class="total-summary" style="margin-top: 15px;">
                <div class="final-total" style="font-size: 1.3rem; color: var(--primary-blue);">
                    <span>NET BILL AMOUNT:</span> 
                    <span id="net_amount_billed_display">₱0.00</span>
                </div>
            </div>

            <hr style="margin: 25px 0;">
            
            <div class="total-summary" style="background:#fffbe6; color:#856404; margin-bottom: 15px; display: none;" id="existing_credit_display_section">
                <div class="breakdown-row" style="color:#856404; font-size: 1.1rem; font-weight: bold;">
                    <span>Existing Booking Deposit (-):</span>
                    <span id="existing_deposit_credit_display" style="color:#856404; font-weight: bold;">- ₱0.00</span>
                </div>
            </div>
            <input type="hidden" name="existing_credit_hidden" id="existing_credit_hidden" value="">
            
            <div class="form-row" id="downpayment_section">
                <label for="downpayment">Amount Received Now:</label>
                <input type="number" step="0.01" min="0" name="downpayment" id="downpayment" oninput="updateServicePriceAndTotal()" placeholder="" value="" required>
            </div>
            
            <div class="total-summary" style="background:#d4edda; color:#155724; margin-top: 15px;">
                <div class="breakdown-row" style="color:#155724; font-size: 1.1rem; font-weight: bold;">
                    <span>TOTAL Credit Applied (-):</span>
                    <span id="downpayment_credited_display" style="color:#155724; font-weight: bold;">- ₱0.00</span>
                </div>
            </div>

            <div class="total-summary" style="margin-top: 15px;">
                <div class="final-total" style="color: var(--alert-red);">
                    <span>NET TOTAL BALANCE DUE:</span> 
                    <span id="summary_net_due">₱0.00</span>
                </div>
            </div>

            <hr style="margin: 25px 0;">
            <div class="form-row">
                <label>Payment Option:</label>
                <select name="payment_option" id="payment_option" onchange="toggleInstallmentFields()">
                    <option value="full" selected>Full Payment</option>
                    <option value="installment">New Installment Plan</option>
                </select>
            </div>

            <div id="installment_fields" style="display:none; background:#fffbe6; padding:15px; border-radius:8px; margin-top:15px; border: 1px solid var(--accent-orange);">
                <h3 style="margin-top:0; color:#856404;"><i class="fas fa-credit-card"></i> Installment Details</h3>
                <div class="form-row">
                    <label for="installment_term">Term (Months):</label>
                    <input type="number" name="installment_term" id="installment_term" min="1" oninput="updateServicePriceAndTotal()" value="" required>
                </div>
                <div class="form-row">
                    <label>Remaining Balance:</label>
                    <input type="text" id="remaining_balance" readonly value="₱0.00">
                </div>
                <div class="form-row">
                    <label>Monthly Payment:</label>
                    <input type="text" id="monthly_payment_display" readonly value="₱0.00">
                    <input type="hidden" name="monthly_payment" id="monthly_payment_hidden">
                </div>
            </div>
            
            <input type="hidden" name="base_amount" id="base_amount" value="">
            <input type="hidden" name="total_amount_billed" id="total_amount_billed" value=""> 
            
            <button type="submit" id="new_service_submit_btn">Process New Payment</button>
        </div> 

        <div class="tab-pane" id="status_reminder_tab" style="display:none;">
            <h3>Filter Payment Records</h3>
            <div class="filter-section" style="display: flex; gap: 15px; margin-bottom: 20px;">
                <input type="text" id="status_search" placeholder="Search patient or service..." style="flex-grow: 1;">
                <select id="status_filter">
                    <option value="all">All Statuses</option>
                    <option value="full">Full Payments</option>
                    <option value="installment">Installments</option>
                </select>
                <button type="button" id="apply_status_filter" class="btn-primary" style="padding: 10px 15px; border-radius: 8px;"><i class="fas fa-search"></i> Apply Filter</button>
            </div>
            
            <div class="table-container">
                <table class="data-table" id="payments_status_table">
                    <thead>
                        <tr>
                            <th>Patient</th>
                            <th>Service</th>
                            <th>Type</th>
                            <th>Net Billed</th>
                            <th>Total Paid</th>
                            <th>Remaining</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="payments_status_tbody">
                        <tr><td colspan="7" style="text-align:center;">Use the filter/search above to load payment data.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </form>
</main>

<div id="loadingOverlay" class="loading-overlay">
    <div class="loading-box">
        <div id="loadingIcon" class="loading-spinner"></div>
        <div id="successIcon" class="success-checkmark" style="display: none;"><i class="fas fa-check"></i></div>
        <p id="loadingMessage" style="font-weight: 600; color: var(--text-dark);">Processing payment...</p>
    </div>
</div>

<script>
// --- JavaScript Logic ---

const ONLINE_BOOKING_MODE = document.getElementById('online_booking_mode').value === '1';
// This assumes suppliesData is available from PHP (which it is)
const suppliesData = <?= json_encode($supplies) ?>; 

function getSupplyPrice(supplyId) {
    // FIX: Supplies cost is hardcoded to 0 as per instructions in PHP logic, but keeping the function structure for future flexibility if needed.
    // const supply = suppliesData.find(s => s.supply_id == supplyId);
    // return supply ? parseFloat(supply.price) : 0;
    return 0; // Return 0 as supplies cost is not factored into the bill amount
}

function formatCurrency(amount) {
    const num = parseFloat(amount);
    if (isNaN(num)) return '₱0.00';
    return '₱' + num.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
}

// --- NEW: Loading/Success Functions ---
function showLoading(event) {
    // This function runs on form submit. We only proceed if validation passes.
    const form = document.getElementById('payment-form');
    if (!form.checkValidity()) {
        return true; // Let browser show validation errors
    }
    
    document.getElementById('loadingOverlay').style.display = 'flex';
    document.getElementById('loadingIcon').style.display = 'block';
    document.getElementById('successIcon').style.display = 'none';
    document.getElementById('loadingMessage').innerText = 'Processing payment...';
    
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
        window.location.href = window.location.pathname;
    }, 1500);
}

function showErrorAndRefresh(message) {
    // We already display PHP errors as flash messages on the page, so this sequence is simpler.
    // However, if the error was hit immediately after the form POST, this won't run as the PHP redirects.
    console.error("Submission Error:", message);
}


// Function to handle fetching and populating the Appointment/Service selector
function populateServiceSelector(patientId) {
    const selector = document.getElementById('service_selector');
    const appointmentGroup = document.getElementById('pending_appointments_optgroup');
    
    // Clear dynamic options
    appointmentGroup.innerHTML = '';
    
    if (patientId <= 0) {
        appointmentGroup.style.display = 'none';
        updateServicePriceAndTotal();
        return;
    }
    
    appointmentGroup.style.display = 'block';
    appointmentGroup.label = '--- Loading Appointments... ---';

    fetch(`payments.php?action=fetch_patient_services&patient_id=${patientId}`)
        .then(response => response.json())
        .then(data => {
            appointmentGroup.label = '--- Pending Appointments (Requires Payment) ---';
            if (data.success && data.data.length > 0) {
                let optionsHtml = '';
                data.data.forEach(appt => {
                    // FIX: Fetch the explicit downpayment from payments table
                    const downpayment = parseFloat(appt.downpayment || 0).toFixed(2);
                    
                    // Keep total_paid for reference if needed
                    const totalPaid = parseFloat(appt.total_paid || 0).toFixed(2);

                    // FIX: Added data-downpayment attribute
                    optionsHtml += `<option value="A_${appt.appointment_id}" 
                                             data-type="appointment" 
                                             data-price="${parseFloat(appt.price).toFixed(2)}"
                                             data-appointment-id="${appt.appointment_id}"
                                             data-service-id="${appt.service_id}"
                                             data-downpayment="${downpayment}" 
                                             data-total-paid="${totalPaid}"> 
                                             [${appt.status.toUpperCase()}] ${appt.service_name} (₱${parseFloat(appt.price).toFixed(2)}) - ${new Date(appt.appointment_date).toLocaleDateString()}
                                             ${downpayment > 0 ? `(DEPOSIT: ₱${downpayment})` : ''} 
                                        </option>`;
                });
                appointmentGroup.innerHTML = optionsHtml;
                
                const currentSelectionType = selector.options[selector.selectedIndex]?.getAttribute('data-type');
                if (currentSelectionType !== 'appointment' && !ONLINE_BOOKING_MODE) {
                    // Default to the first pending appointment if no service is selected and not in online booking mode
                    selector.value = data.data[0] ? `A_${data.data[0].appointment_id}` : '';
                }

            } else {
                appointmentGroup.label = '--- No Pending Appointments Found ---';
                appointmentGroup.innerHTML = '<option value="" data-type="none" data-price="0" disabled>No pending services to bill.</option>';
                
                const currentSelectionType = selector.options[selector.selectedIndex]?.getAttribute('data-type');
                if (currentSelectionType === 'appointment' || selector.value.startsWith('A_')) {
                    selector.value = '';
                }
            }
            updateServicePriceAndTotal(); // Recalculate after updating selector
        })
        .catch(error => {
            console.error('Error fetching patient services:', error);
            appointmentGroup.label = '--- Error Loading Appointments ---';
            updateServicePriceAndTotal();
        });
}

// Main calculation function (MODIFIED)
function updateServicePriceAndTotal() {
    const selector = document.getElementById('service_selector');
    const selectedOption = selector.options[selector.selectedIndex];
    
    const appointmentIdHidden = document.getElementById('appointment_id_hidden');
    const serviceIdActual = document.getElementById('service_id_actual');
    const downpaymentInput = document.getElementById('downpayment'); // Current cash received now
    
    // NEW Elements for Existing Credit
    const existingCreditHidden = document.getElementById('existing_credit_hidden');
    const existingCreditDisplay = document.getElementById('existing_deposit_credit_display');
    const existingCreditSection = document.getElementById('existing_credit_display_section');
    
    let servicePrice = 0;
    let serviceId = '';
    let appointmentId = '';

    if (selectedOption && selectedOption.value) {
        servicePrice = parseFloat(selectedOption.getAttribute('data-price')) || 0;
        const type = selectedOption.getAttribute('data-type');
        
        if (type === 'appointment') {
            appointmentId = selectedOption.getAttribute('data-appointment-id');
            serviceId = selectedOption.getAttribute('data-service-id');
        } else if (type === 'service') {
            serviceId = selectedOption.value;
            appointmentId = ''; 
        }
    }
    
    // Update the hidden fields for PHP POST
    serviceIdActual.value = serviceId; 
    appointmentIdHidden.value = appointmentId; 

    // --- Supplies Calculation (Cost is 0, only inventory tracking matters) ---
    let suppliesCost = 0; // Fixed at 0
    
    // FIX: Gross amount is purely the service price.
    const grossAmount = servicePrice + suppliesCost; 
    let netAmountBilled = grossAmount; // Net Amount Billed is Gross - Discount
    
    const discountType = document.getElementById('discount_type').value;
    let discount = 0;

    if (discountType === 'loyalty') {
        discount = grossAmount * 0.10;
    } else if (discountType === 'health') {
        discount = grossAmount * 0.15;
    }
    
    netAmountBilled = grossAmount - discount;
    netAmountBilled = Math.max(0, netAmountBilled); 
    
    
    // --- Determine Existing Credit ---
    let existingCredit = 0.0;
    const isAppointment = selectedOption && selectedOption.getAttribute('data-type') === 'appointment';
    
    // Default to the current payment option selected
    const paymentOption = document.getElementById('payment_option').value;

    if (isAppointment) {
        // FIX: Read the 'downpayment' attribute we added earlier (This comes from payments table)
        const paymentTableDownpayment = parseFloat(selectedOption.getAttribute('data-downpayment')) || 0.0;
        
        // SET existingCredit to the downpayment value from payments table
        existingCredit = paymentTableDownpayment;

        existingCreditDisplay.textContent = '- ' + formatCurrency(existingCredit);
        existingCreditSection.style.display = existingCredit > 0 ? 'flex' : 'none';
        
        // --- Downpayment ReadOnly/Value Logic for APPOINTMENTS ---
        if (paymentOption === 'full') {
             // In full payment mode for an appointment, the input is set to the remaining balance due
             downpaymentInput.value = Math.max(0, netAmountBilled - existingCredit).toFixed(2);
             // Make readonly to ensure full payment is recorded
             downpaymentInput.setAttribute('readonly', 'readonly'); 
        } else if (ONLINE_BOOKING_MODE) {
            // For online booking, the initial deposit is fixed
            // Value is set in initializeOnlineBooking, just ensure it's readonly
            downpaymentInput.setAttribute('readonly', 'readonly');
        } else {
             // If installment or if processing a new service, allow input 
             downpaymentInput.removeAttribute('readonly');
             // If switching to installment, do NOT reset the value if the user already typed a current payment
        }
        
    } else {
        // New service selected or none selected
        existingCreditDisplay.textContent = formatCurrency(0);
        existingCreditSection.style.display = 'none';
        
        // For new service, always allow editing unless online booking (which is handled by initializeOnlineBooking)
        if (!ONLINE_BOOKING_MODE) {
            downpaymentInput.removeAttribute('readonly');
        }
    }
    
    // Store existing credit for PHP
    existingCreditHidden.value = existingCredit.toFixed(2); 

    
    // --- DOWNPAYMENT DEDUCTION LOGIC ---
    const currentCashReceived = parseFloat(downpaymentInput.value) || 0; 
    
    // The TOTAL credit applied is the existing credit PLUS the cash received now
    const totalCreditApplied = existingCredit + currentCashReceived;
    
    // The amount to actually credit/deduct is limited to the net bill amount.
    const amountToCredit = Math.min(totalCreditApplied, netAmountBilled); 

    // Calculate the remaining balance due *after* total credit
    let netTotalBalanceDue = netAmountBilled - amountToCredit;
    netTotalBalanceDue = Math.max(0, netTotalBalanceDue);
    
    
    // --- Update Display Summary ---
    document.getElementById('summary_service_price').textContent = formatCurrency(servicePrice);
    
    document.getElementById('summary_gross_amount').textContent = formatCurrency(grossAmount);
    document.getElementById('summary_discount').textContent = '- ' + formatCurrency(discount);
    
    // NEW: Display Net Amount Billed (Gross - Discount)
    document.getElementById('net_amount_billed_display').textContent = formatCurrency(netAmountBilled);
    
    // Update TOTAL Downpayment deduction display (Existing Credit + New Cash)
    document.getElementById('downpayment_credited_display').textContent = '- ' + formatCurrency(amountToCredit);

    // Update the final Net Balance Due display
    document.getElementById('summary_net_due').textContent = formatCurrency(netTotalBalanceDue);
    
    // Update Hidden Inputs for PHP
    document.getElementById('base_amount').value = grossAmount.toFixed(2);
    document.getElementById('total_amount_billed').value = netAmountBilled.toFixed(2);

    // Handle Installment calculations
    
    if (paymentOption === 'installment') {
        let installmentTerm = parseInt(document.getElementById('installment_term').value) || 1;
        let remainingBalance = netTotalBalanceDue; 
        let monthlyPayment = remainingBalance > 0 ? (remainingBalance / installmentTerm) : 0;
        
        document.getElementById('remaining_balance').value = formatCurrency(remainingBalance);
        document.getElementById('monthly_payment_display').value = formatCurrency(monthlyPayment);
        document.getElementById('monthly_payment_hidden').value = monthlyPayment.toFixed(2);
        
    } else {
        document.getElementById('monthly_payment_hidden').value = 0;
    }
}


function addSupplyRow(supplyId = '', quantity = 0) {
    const container = document.getElementById('supplies-container');
    const newRow = document.createElement('div');
    newRow.className = 'form-row supply-row';
    
    // Create label only for first row
    const label = document.createElement('label');
    label.textContent = 'Supply Item:';
    
    // Create group div
    const group = document.createElement('div');
    group.className = 'supply-row-group';

    // Create select
    const select = document.createElement('select');
    select.name = 'supply_id[]';
    select.className = 'supply-select';
    select.onchange = updateServicePriceAndTotal;
    let optionsHtml = '<option value="">-- Select Supply --</option>';
    
    suppliesData.forEach(s => {
        optionsHtml += `<option value="${s.supply_id}" data-unit="${s.unit}" ${s.supply_id == supplyId ? 'selected' : ''}>${s.name} (Stock: ${s.quantity} ${s.unit})</option>`;
    });
    select.innerHTML = optionsHtml;
    
    // Create quantity input
    const qtyInput = document.createElement('input');
    qtyInput.type = 'number';
    qtyInput.name = 'used_quantity[]';
    qtyInput.className = 'qty-input';
    qtyInput.value = quantity;
    qtyInput.min = '0';
    qtyInput.oninput = updateServicePriceAndTotal;
    
    // Create remove button
    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'remove-btn';
    removeBtn.innerHTML = '<i class="fas fa-times"></i>';
    removeBtn.onclick = function() {
        removeSupplyRow(this);
    };

    group.appendChild(select);
    group.appendChild(qtyInput);
    group.appendChild(removeBtn);
    
    if (container.children.length === 0) {
        // First row: add label
        newRow.appendChild(label);
        newRow.appendChild(group);
    } else {
        // Subsequent rows: no label, add margin to group to align
        const firstRowLabelWidth = 150; // Defined in CSS
        const formRowGap = 20; // Defined in CSS
        group.style.marginLeft = `${firstRowLabelWidth + formRowGap}px`; 
        newRow.appendChild(group);
    }

    container.appendChild(newRow);
}

function removeSupplyRow(button) {
    const row = button.closest('.form-row');
    row.remove();
    updateServicePriceAndTotal();
    
    // If removing the first row, promote the next row's label
    const container = document.getElementById('supplies-container');
    if (container.children.length > 0) {
        const firstRow = container.firstElementChild;
        if (firstRow.firstElementChild.tagName !== 'LABEL') {
            const newLabel = document.createElement('label');
            newLabel.textContent = 'Supply Item:';
            firstRow.insertBefore(newLabel, firstRow.firstChild);
            const groupDiv = firstRow.querySelector('.supply-row-group');
            if(groupDiv) groupDiv.style.marginLeft = '0';
        }
    }
}


function toggleInstallmentFields() {
    const option = document.getElementById('payment_option').value;
    const fields = document.getElementById('installment_fields');
    const downpaymentInput = document.getElementById('downpayment');
    const installmentTermInput = document.getElementById('installment_term');

    if (option === 'installment') {
        fields.style.display = 'block';
        if (!ONLINE_BOOKING_MODE) {
            // Downpayment is required for installment unless there is existing credit
            downpaymentInput.setAttribute('required', 'required');
            downpaymentInput.removeAttribute('readonly'); // Allow typing a new downpayment
        }
        installmentTermInput.setAttribute('required', 'required');
    } else {
        fields.style.display = 'none';
        downpaymentInput.removeAttribute('required');
        installmentTermInput.removeAttribute('required');
        // 'Full Payment' mode sets readonly logic inside updateServicePriceAndTotal()
    }
    updateServicePriceAndTotal();
}

function initializeOnlineBooking() {
    if (ONLINE_BOOKING_MODE) {
        const patientSelect = document.getElementById('patient_id');
        const serviceSelector = document.getElementById('service_selector');
        
        document.getElementById('payment_option').value = 'installment';
        document.getElementById('payment_option').setAttribute('disabled', 'disabled');
        patientSelect.setAttribute('disabled', 'disabled');
        serviceSelector.setAttribute('disabled', 'disabled');
        
        // This is a special case: the downpayment input holds the online booking amount 
        // which serves as the initial credit and the first transaction amount.
        const initialBookingDeposit = parseFloat('<?= $online_booking_downpayment ?? 0 ?>');
        document.getElementById('downpayment').value = initialBookingDeposit.toFixed(2);
        document.getElementById('downpayment').setAttribute('readonly', 'readonly');
        
        toggleInstallmentFields();
        updateServicePriceAndTotal();
    }
}

// Function to handle showing/hiding payment proof upload based on method
document.addEventListener('DOMContentLoaded', function() {
    const paymentMethods = document.querySelectorAll('input[name="payment_method"]');
    const proofContainer = document.getElementById('payment_proof_new_container');
    const proofInput = document.getElementById('payment_proof_new');

    function toggleProofUpload() {
        const selectedMethod = document.querySelector('input[name="payment_method"]:checked').value;
        if (selectedMethod !== 'Cash') {
            proofContainer.style.display = 'flex';
            proofInput.setAttribute('required', 'required');
        } else {
            proofContainer.style.display = 'none';
            proofInput.removeAttribute('required');
        }
    }

    paymentMethods.forEach(radio => {
        radio.addEventListener('change', toggleProofUpload);
    });

    // Initial call
    toggleProofUpload();
});

// --- NEW: Tab Switching Logic ---
function switchTab(targetTabId) {
    document.querySelectorAll('.tab-pane').forEach(tab => {
        tab.style.display = 'none';
        tab.classList.remove('active');
    });
    document.querySelectorAll('.tab-button').forEach(btn => {
        btn.classList.remove('active');
    });

    const targetTab = document.getElementById(targetTabId);
    if (targetTab) {
        targetTab.style.display = 'block';
        targetTab.classList.add('active');
        document.querySelector(`[data-tab="${targetTabId}"]`).classList.add('active');
        
        if (targetTabId === 'status_reminder_tab') {
            fetchAndDisplayPaymentStatus();
        }
    }
}

// --- NEW: Fetch and Display Payment Status ---
function fetchAndDisplayPaymentStatus() {
    const filter = document.getElementById('status_filter').value;
    const search = document.getElementById('status_search').value;
    const tbody = document.getElementById('payments_status_tbody');
    
    tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;">Loading...</td></tr>`;

    fetch(`payments.php?action=fetch_all_payment_status&filter=${filter}&search=${search}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data.length > 0) {
                let rowsHtml = '';
                data.data.forEach(p => {
                    const isInstallment = p.payment_option === 'installment';
                    // Note: p.remaining_balance is the patient's outstanding_balance from DB
                    const balance = parseFloat(p.remaining_balance); 
                    
                    let typeLabel = '';
                    if (isInstallment) {
                           typeLabel = balance > 0 
                             ? `<span style="color: #ff851b; font-weight: bold;">Installment (Due)</span>` 
                             : `<span style="color: #28a745; font-weight: bold;">Installment (Paid)</span>`;
                    } else {
                        typeLabel = `<span style="color: #007bff; font-weight: bold;">Full Payment</span>`;
                    }
                        
                    const remindButton = (isInstallment && balance > 0 && p.patient_email && p.patient_email.trim() !== '')
                        ? `
                            <form method="POST" style="display:inline-block;">
                                <input type="hidden" name="payment_id" value="${p.payment_id}">
                                <button type="submit" name="remind_installment_payment" class="action-btn btn-remind">
                                    <i class="fas fa-bell"></i> Remind
                                </button>
                            </form>
                          `
                        : (isInstallment && balance <= 0) ? '<span style="color: #28a745;">Settled</span>' : '<span style="color: #6c757d;">N/A</span>';
                        
                    const remainingStyle = balance > 0 ? 'color: var(--alert-red); font-weight: bold;' : 'color: var(--success-green);';
                    const netBilledStyle = 'text-align: right;';
                    const totalPaidStyle = 'text-align: right;';
                    const remainingColStyle = remainingStyle + ' text-align: right;';

                    rowsHtml += `
                        <tr>
                            <td>${p.patient_name}</td>
                            <td>${p.service_name}</td>
                            <td>${typeLabel}</td>
                            <td style="${netBilledStyle}">${formatCurrency(p.total_amount)}</td>
                            <td style="${totalPaidStyle}">${formatCurrency(p.total_paid)}</td>
                            <td style="${remainingColStyle}">${formatCurrency(balance)}</td>
                            <td style="text-align: center;">${remindButton}</td>
                        </tr>
                    `;
                });
                tbody.innerHTML = rowsHtml;
            } else {
                tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;">No payment records found matching the criteria.</td></tr>`;
            }
        })
        .catch(error => {
            console.error('Error fetching payment status:', error);
            tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; color: var(--alert-red);">Error loading data. See console for details.</td></tr>`;
        });
}


document.addEventListener('DOMContentLoaded', function() {
    // Clean up container and add a starting supply row
    const container = document.getElementById('supplies-container');
    while (container.firstChild) {
        container.removeChild(container.firstChild);
    }
    addSupplyRow();

    // Default to first tab (Process New Payment)
    switchTab('new_service_tab');

    // Event listeners
    document.getElementById('patient_id').addEventListener('change', () => {
        document.getElementById('service_selector').value = ''; 
        populateServiceSelector(document.getElementById('patient_id').value);
    });
    
    document.getElementById('service_selector').addEventListener('change', updateServicePriceAndTotal);
    document.getElementById('discount_type').addEventListener('change', updateServicePriceAndTotal);
    document.getElementById('payment_option').addEventListener('change', toggleInstallmentFields);
    document.getElementById('downpayment').addEventListener('input', updateServicePriceAndTotal);
    document.getElementById('installment_term').addEventListener('input', updateServicePriceAndTotal);

    // --- NEW STATUS/REMINDER TAB LISTENERS ---
    document.getElementById('apply_status_filter').addEventListener('click', fetchAndDisplayPaymentStatus);
    document.getElementById('status_filter').addEventListener('change', fetchAndDisplayPaymentStatus);
    document.getElementById('status_search').addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault(); 
            fetchAndDisplayPaymentStatus();
        }
    });

    // --- TAB SETUP (Replaces changeTab onclick logic) ---
    document.querySelectorAll('.tab-button').forEach(button => {
        button.addEventListener('click', function() {
            switchTab(this.getAttribute('data-tab'));
        });
    });

    // Initial load checks
    if (document.getElementById('patient_id').value) {
        populateServiceSelector(document.getElementById('patient_id').value);
    }

    // Force a total update on load to show correct defaults
    updateServicePriceAndTotal();
    
    // Initialize online booking mode if active
    if (ONLINE_BOOKING_MODE) {
        initializeOnlineBooking();
    }
    
    // --- Check for success message in URL (for redirect handling) ---
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('message') && !urlParams.has('error')) {
        // If a success message exists, trigger the success animation
        const successMessage = urlParams.get('message');
        // Clear the message from the URL (optional but cleaner)
        const newUrl = window.location.pathname;
        history.replaceState(null, null, newUrl);
        // Show success animation and refresh the page (the logic below handles this)
        showSuccessAndRefresh(successMessage);
    }
});

// Logic to show/hide the Transaction ID based on radio selection
function toggleTransactionIdField() {
    const methods = document.getElementsByName('payment_method');
    const container = document.getElementById('transaction_id_container');
    const proofContainer = document.getElementById('payment_proof_new_container');
    let selectedValue = "Cash";

    for (const rb of methods) {
        if (rb.checked) {
            selectedValue = rb.value;
            break;
        }
    }

    if (selectedValue === "Cash") {
        container.style.display = 'none';
        proofContainer.style.display = 'none';
        document.getElementById('transaction_id').required = false;
    } else {
        container.style.display = 'flex';
        proofContainer.style.display = 'flex';
        document.getElementById('transaction_id').required = true;
    }
}
</script>
</body>
</html>