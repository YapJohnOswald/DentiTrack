<?php
// File: calendar_function/insert_appointment_and_payment_patient.php

// ----------------------------------------------------
// CRITICAL FIX: TEMPORARILY ENABLE ERROR REPORTING
// Check server logs for exact fatal error if execution fails.
// REMOVE these lines once the file is fully stable.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// ----------------------------------------------------

require_once '../calendar_function/calendar_conn.php'; 
header('Content-Type: application/json');

// =======================================================
// 1. SMTP Configuration and PHPMailer Setup (CASE-SENSITIVE FIX APPLIED)
// =======================================================
// Check and ensure these folder casings match your server exactly.
require '../PHPMailer-master/src/PHPMailer.php';
require '../PHPMailer-master/src/SMTP.php';
require '../PHPMailer-master/src/Exception.php';
@require '../vendor/autoload.php'; // Use @ for safety if composer vendor isn't used/present

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// NOTE: Using the credentials provided in your prompt/original file
define('CONF_SMTP_USER', 'dentitrack2025@gmail.com');
define('CONF_SMTP_PASS', 'gpmennmjrynhujzq');
// =======================================================

// Get all data from the patient booking form
$data = $_POST;

// --- APPOINTMENT FIELDS ---
$user_id_raw      = $data['user_id'] ?? 0;
$user_id          = (int)$user_id_raw; // The ID from the hidden form field
$service_id       = $data['service_id'] ?? NULL;
$appointment_date = $data['appointment_date'] ?? NULL;
$appointment_time = $data['appointment_time'] ?? NULL;
$comments         = $data['comments'] ?? '';

// --------------------
// FIXED: Get REAL patient_id from DB
// --------------------
$getPatient = $pdo->prepare("SELECT patient_id FROM patient WHERE user_id = ?");
$getPatient->execute([$user_id]);
$patient_id = $getPatient->fetchColumn();

if (!$patient_id) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Patient record not found for this user.'
    ]);
    exit;
}
// --------------------

// --- PAYMENT FIELDS ---
$primary_method   = $data['primary_method'] ?? 'Walk-in'; // 'Walk-in' or 'Online'
$discount_type    = $data['discount_type'] ?? 'None'; 
// The name of the successfully active payment amount input is always 'amount_paid' (from JS logic)
$submitted_amount = number_format(floatval($data['amount_paid'] ?? 0.00), 2, '.', ''); 

// Variables hardcoded for downpayment-only flow
$installment_term = 0; 
$monthly_payment  = 0.00; 

// Look for the single, consistent renamed key from the frontend
$uploaded_file = $_FILES['uploaded_proof_image'] ?? null; 

/* --------------------------------------------------------
   1. VALIDATION AND SETUP
-------------------------------------------------------- */

// STRICT USER ID VALIDATION
if ($user_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'User ID is missing or invalid. Please ensure you are logged in.']);
    exit;
}

// Basic Validation for other fields
if (
    empty($service_id) || 
    empty($appointment_date) || 
    empty($appointment_time)
) {
    echo json_encode(['status' => 'error', 'message' => 'Missing or invalid required fields (Service, Date, or Time).']);
    exit;
}

/* --------------------------------------------------------
   🛑 CRITICAL SERVER-SIDE CHECK: LIMIT 1 BOOKING PER USER PER DAY
-------------------------------------------------------- */
try {
    $check_stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM appointments 
        WHERE user_id = :user_id 
          AND appointment_date = :appointment_date 
          AND status NOT IN ('Cancelled', 'Rejected', 'No_Show')
    ");
    
    $check_stmt->execute([
        ':user_id' => $user_id,
        ':appointment_date' => $appointment_date 
    ]);
    
    $existing_bookings = $check_stmt->fetchColumn();

    if ($existing_bookings > 0) {
        // Stop execution and return error if a valid booking already exists for the day
        echo json_encode(['status' => 'error', 'message' => "You already have an active appointment scheduled on " . $appointment_date . ". Only one booking per user per day is allowed."]);
        exit;
    }
    
} catch (PDOException $e) {
    error_log("Duplicate booking check failed: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Booking check failed due to a database error. Please try again or contact support.']);
    exit;
}

// Payment Proof Validation
$proof_required = ($primary_method === 'Online');

if ($proof_required) {
    if ($uploaded_file === null || $uploaded_file['error'] !== UPLOAD_ERR_OK) {
        if ($uploaded_file && $uploaded_file['error'] === UPLOAD_ERR_NO_FILE) {
            echo json_encode(['status' => 'error', 'message' => 'Payment proof image is required to secure the booking.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'File upload failed. Error code: ' . ($uploaded_file['error'] ?? 'N/A') . ' Please try again.']);
        }
        exit;
    }
}

$target_file = null; 

try {
    // Sanitize and prepare data
    $service_id       = (int)$service_id;
    $comments         = htmlspecialchars($comments);
    $proof_image_path = NULL; 

    // CRITICAL: SERVER-SIDE CHECK FOR DOCTOR REST DAY
    $restCheckStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM doctor_availability 
        WHERE available_date = ? AND is_available = 0
    ");
    $restCheckStmt->execute([$appointment_date]);
    if ($restCheckStmt->fetchColumn() > 0) {
        echo json_encode(['status' => 'error', 'message' => 'The selected date is a doctor\'s rest day. Please choose another date.']);
        exit;
    }

    // --- Fetch Full Price and Service Name from DB ---
    $serviceStmt = $pdo->prepare("SELECT price, service_name, duration FROM services WHERE service_id = ?");
    $serviceStmt->execute([$service_id]);
    $service = $serviceStmt->fetch(PDO::FETCH_ASSOC);

    if (!$service) {
        throw new Exception("Service not found or price is invalid.");
    }

    $full_price   = floatval($service['price']);
    $service_name = $service['service_name']; 
    $durationText = $service['duration'];    
    
    /* --------------------------------------------------------
       2. CALCULATE PRICE AND STATUS
    -------------------------------------------------------- */
    $discount_rate = 0.00;
    if (strpos($discount_type, 'Loyalty Card') !== false) {
        $discount_rate = 0.10; 
    } elseif (strpos($discount_type, 'Health Card') !== false) {
        $discount_rate = 0.15; 
    }

    $discount_amount = round($full_price * $discount_rate, 2);
    $final_price_after_discount = number_format(($full_price - $discount_amount), 2, '.', ''); 
    
    $deposit_amount = $submitted_amount;
    $remaining_balance = 0.00; 
    $payment_amount_for_db = 0.00; 

    if (floatval($deposit_amount) >= floatval($final_price_after_discount)) {
        $payment_status_db = 'paid'; 
        $payment_amount_for_db = 0.00; 
    } else {
        $payment_status_db = 'pending'; 
        $remaining_balance = floatval($final_price_after_discount) - floatval($deposit_amount);
        $remaining_balance = round($remaining_balance, 2);
        $payment_amount_for_db = $remaining_balance; 
    }
    
    /* --------------------------------------------------------
       3. CLEAN PAYMENT COLUMNS 
    -------------------------------------------------------- */
    $booking_option_db = $primary_method; 
    $payment_method_db = NULL;
    $payment_option_db = NULL; 
    
    if ($primary_method === 'Online') {
        $payment_method_db = 'Online Transfer'; 
        $payment_option_db = 'downpayment'; 
    } else {
        $payment_method_db = 'Cash'; 
        $payment_option_db = 'walk-in';
    }

    /* --------------------------------------------------------
       4. IMAGE UPLOAD
    -------------------------------------------------------- */
    if ($uploaded_file && $uploaded_file['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/payment_proofs/'; 
        if (!is_dir($upload_dir)) {
            if (!mkdir($upload_dir, 0755, true)) {
                 throw new Exception('Failed to create upload directory.');
            }
        }

        $file_extension = pathinfo($uploaded_file['name'], PATHINFO_EXTENSION);
        $new_file_name = uniqid('proof_') . '.' . strtolower($file_extension); 
        $target_file = $upload_dir . $new_file_name; 

        if (move_uploaded_file($uploaded_file['tmp_name'], $target_file)) {
            $proof_image_path = 'uploads/payment_proofs/' . $new_file_name; 
        } else {
            throw new Exception('Failed to move uploaded file.');
        }
    }

    /* --------------------------------------------------------
       5. TIME PARSING AND AVAILABILITY CHECK 
    -------------------------------------------------------- */
    list($start_time_label, $end_time_label) = explode(' - ', $appointment_time);
    $start_time = date('H:i:s', strtotime($start_time_label));
    
    $minutes = 60; 
    if (preg_match('/(\d+)\s*minutes?/i', $durationText, $m)) {
        $minutes = (int)$m[1];
    } elseif (preg_match('/(\d+)\s*hours?/i', $durationText, $m)) {
        $minutes = (int)$m[1] * 60;
    }

    $start_timestamp = strtotime($appointment_date . ' ' . $start_time);
    $end_timestamp = $start_timestamp + ($minutes * 60);
    $end_time = date('H:i:s', $end_timestamp);
    
    $check = $pdo->prepare("
        SELECT COUNT(*) FROM appointments 
        WHERE appointment_date = ? AND appointment_time = ? 
        AND status IN ('booked', 'completed', 'approved')
    ");
    $check->execute([$appointment_date, $appointment_time]);
    if ($check->fetchColumn() > 0) {
        if (isset($target_file) && file_exists($target_file)) { @unlink($target_file); }
        echo json_encode(['status' => 'error', 'message' => 'This time slot is already occupied.']);
        exit;
    }

    /* --------------------------------------------------------
       6. TRANSACTION START
    -------------------------------------------------------- */
    $pdo->beginTransaction();

    // 6a. INSERT INTO APPOINTMENTS
    $stmt = $pdo->prepare("
        INSERT INTO appointments 
        (user_id, patient_id, service_id, appointment_date, appointment_time, start_time, end_time, comments, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'booked') 
    ");
    $stmt->execute([$user_id, $patient_id, $service_id, $appointment_date, $appointment_time, $start_time, $end_time, $comments]);
    $appointment_id = $pdo->lastInsertId(); 

    // 6b. INSERT INTO patient_downpayment
    $payment_stmt = $pdo->prepare("
        INSERT INTO patient_downpayment 
        (amount, patient_id, service_id, discount_type, discount_amount, total_amount, payment_method, booking_option, payment_option, downpayment, appointment_id, proof_image, payment_date, status, installment_term, monthly_payment, user_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?)
    ");
    $payment_stmt->execute([
        $payment_amount_for_db, $patient_id, $service_id, $discount_type, $discount_amount, 
        $final_price_after_discount, $payment_method_db, $booking_option_db, $payment_option_db, 
        $deposit_amount, $appointment_id, $proof_image_path, $payment_status_db, 
        $installment_term, $monthly_payment, $user_id 
    ]);
    
    // Get payment_id for transaction tracking
    $payment_id = $pdo->lastInsertId();

    // =======================================================
    // 6c. NEW: LOG DOWNPAYMENT IN TRANSACTION HISTORY
    // =======================================================
    if (floatval($deposit_amount) > 0) {
        $stmtTrans = $pdo->prepare("
            INSERT INTO payment_transactions (
                payment_id, 
                patient_id, 
                amount_received, 
                transaction_date, 
                payment_method, 
                payment_proof_path,
                created_at
            ) VALUES (?, ?, ?, CURDATE(), ?, ?, NOW())
        ");
        $stmtTrans->execute([
            $payment_id,
            $patient_id,
            $deposit_amount,
            $payment_method_db,
            $proof_image_path
        ]);
    }
    // =======================================================

    // 6d. UPDATE PATIENT OUTSTANDING BALANCE
    if ($payment_status_db !== 'paid' && $remaining_balance > 0) {
         $update_balance_stmt = $pdo->prepare("UPDATE patient SET outstanding_balance = outstanding_balance + ? WHERE user_id = ?");
         $update_balance_stmt->execute([$remaining_balance, $user_id]);
    }

    $pdo->commit();

    /* --------------------------------------------------------
       7. SEND EMAIL CONFIRMATION
    -------------------------------------------------------- */
    $patient_query = $pdo->prepare("
        SELECT u.email, CONCAT(p.first_name, ' ', p.last_name) AS full_name
        FROM users u JOIN patient p ON u.user_id = p.user_id WHERE u.user_id = ?
    ");
    $patient_query->execute([$user_id]);
    $pdata = $patient_query->fetch(PDO::FETCH_ASSOC);

    if ($pdata && $pdata['email']) {
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = CONF_SMTP_USER;
            $mail->Password = CONF_SMTP_PASS;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;

            $mail->setFrom(CONF_SMTP_USER, 'DentiTrack Clinic');
            $mail->addAddress($pdata['email'], $pdata['full_name']);
            $mail->isHTML(true);
            $mail->Subject = "Appointment Submitted for Verification";

            $mail->Body = "
                <h2>Appointment Submitted!</h2>
                <p>Dear {$pdata['full_name']}, your booking has been submitted for verification.</p>
                <ul>
                    <li><strong>Service:</strong> {$service_name}</li>
                    <li><strong>Date:</strong> " . date('F j, Y', strtotime($appointment_date)) . "</li>
                    <li><strong>Time:</strong> " . date('h:i A', strtotime($start_time)) . "</li>
                    <li><strong>Amount Paid:</strong> ₱" . number_format($deposit_amount, 2) . "</li>
                    <li><strong>Remaining Balance:</strong> ₱" . number_format($remaining_balance, 2) . "</li>
                    <li><strong>Status:</strong> " . (($remaining_balance > 0) ? 'Pending Final Payment' : 'Downpayment Received') . "</li>
                </ul>
                <p>You will receive another email after the secretary verifies your payment proof.</p>
                <p>Thank you for choosing DentiTrack.</p>
            ";
            $mail->send();
        } catch (Exception $e) {
            error_log("EMAIL ERROR: " . $e->getMessage());
        }
    }
    
    echo json_encode(['status' => 'success', 'message' => 'Appointment successfully booked and transaction recorded!']);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    if (isset($target_file) && file_exists($target_file)) { @unlink($target_file); }
    error_log("Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Booking failed: ' . $e->getMessage()]);
}
?>