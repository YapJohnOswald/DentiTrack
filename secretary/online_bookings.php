<?php
session_start();
// Include the database connections
require_once '../config/db_pdo.php';

// --- PHPMailer Includes (Required for Email Reminders) ---
require '../PHPMailer-master/src/PHPMailer.php';
require '../PHPMailer-master/src/SMTP.php';
require '../PHPMailer-master/src/Exception.php';

// Include Composer Autoload (for general dependencies)
require '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// =======================================================
// IMPORTANT CONFIGURATION: SMTP & Timezone
// =======================================================
define('CONF_TIMEZONE', 'Asia/Manila');
define('CONF_SMTP_HOST', 'smtp.gmail.com');
define('CONF_SMTP_PORT', 587);
define('CONF_SMTP_USER', 'dentitrack2025@gmail.com');       
define('CONF_SMTP_PASS', 'gpmennmjrynhujzq');             
// =======================================================

date_default_timezone_set(CONF_TIMEZONE);

// Ensure PDO throws exceptions
if (isset($pdo)) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn = $pdo; 
} else {
    $error = "Database connection failed.";
}

$error = '';
$success = '';
$appointments = [];
$current_page = 'online_bookings'; 

// --- Filter and Search Logic ---
$search_term = $_GET['search'] ?? '';
$filter_status = $_GET['status'] ?? 'all'; 

$where_clauses = [];
$params = [];

if ($filter_status !== 'all') {
    $where_clauses[] = "a.status = ?";
    $params[] = $filter_status;
}

if ($search_term) {
    $search_pattern = '%' . $search_term . '%';
    $where_clauses[] = "(CONCAT(pat.first_name, ' ', pat.last_name) LIKE ? OR s.service_name LIKE ?)";
    $params[] = $search_pattern;
    $params[] = $search_pattern;
}

$where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// ==========================
// HANDLE APPROVE/DECLINE ACTIONS
// ==========================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve_appointment'])) {
        $appointment_id = $_POST['appointment_id'] ?? null;
        if ($appointment_id) {
            try {
                $conn->beginTransaction();

                // 1. Update status to 'approved'
                $stmt = $conn->prepare("UPDATE appointments SET status = 'approved', updated_at = NOW() WHERE appointment_id = ? AND status = 'booked'");
                $stmt->execute([$appointment_id]);

                if ($stmt->rowCount() > 0) {
                    // 2. Fetch the downpayment record to transfer it
                    $stmtDp = $conn->prepare("SELECT * FROM patient_downpayment WHERE appointment_id = ? LIMIT 1");
                    $stmtDp->execute([$appointment_id]);
                    $dpData = $stmtDp->fetch(PDO::FETCH_ASSOC);

                    if ($dpData) {
                        // 3. Insert into the main payments table
                        $insertPay = $conn->prepare("
                            INSERT INTO payments (
                                user_id, amount, payment_date, patient_id, service_id, 
                                payment_method, booking_option, status, proof_image, appointment_id
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'paid', ?, ?)
                        ");
                        $insertPay->execute([
                            $dpData['user_id'],
                            $dpData['amount'],
                            $dpData['payment_date'],
                            $dpData['patient_id'],
                            $dpData['service_id'],
                            $dpData['payment_method'],
                            $dpData['booking_option'],
                            $dpData['proof_image'],
                            $appointment_id
                        ]);

                        $new_payment_id = $conn->lastInsertId();

                        // 4. Update the downpayment record with the new payment_id and status
                        $updateDp = $conn->prepare("UPDATE patient_downpayment SET status = 'paid', payment_id = ? WHERE appointment_id = ?");
                        $updateDp->execute([$new_payment_id, $appointment_id]);
                    }

                    // 5. Fetch details for email
                    $stmtMail = $conn->prepare("
                        SELECT pat.email, CONCAT(pat.first_name, ' ', pat.last_name) AS patient_name,
                               s.service_name, a.appointment_date, a.appointment_time
                        FROM appointments a
                        JOIN patient pat ON a.patient_id = pat.patient_id
                        JOIN services s ON a.service_id = s.service_id
                        WHERE a.appointment_id = ?
                    ");
                    $stmtMail->execute([$appointment_id]);
                    $data = $stmtMail->fetch(PDO::FETCH_ASSOC);

                    $conn->commit();

                    if ($data && $data['email']) {
                        $mail = new PHPMailer(true);
                        $mail->isSMTP();
                        $mail->Host = CONF_SMTP_HOST; 
                        $mail->SMTPAuth = true;
                        $mail->Username = CONF_SMTP_USER; 
                        $mail->Password = CONF_SMTP_PASS; 
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                        $mail->Port = CONF_SMTP_PORT; 
                        $mail->setFrom(CONF_SMTP_USER, 'DentiTrack - Secretary'); 
                        $mail->addAddress($data['email'], $data['patient_name']);
                        $mail->isHTML(true);
                        $mail->Subject = 'Appointment Approved';
                        $mail->Body = "<h2>Appointment Approved</h2><p>Dear {$data['patient_name']}, your appointment for {$data['service_name']} on {$data['appointment_date']} has been approved.</p>";
                        $mail->send();
                        $success = 'Appointment approved, payment recorded, and notification sent.';
                    }
                } else {
                    if ($conn->inTransaction()) $conn->rollBack();
                    $error = 'Appointment not found or already processed.';
                }
            } catch (Exception $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                $error = "Failed to approve: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['decline_appointment'])) {
        $appointment_id = $_POST['appointment_id'] ?? null;
        if ($appointment_id) {
            try {
                $stmt = $conn->prepare("UPDATE appointments SET status = 'declined', updated_at = NOW() WHERE appointment_id = ? AND status = 'booked'");
                $stmt->execute([$appointment_id]);
                $success = 'Appointment declined successfully.';
            } catch (Exception $e) {
                $error = "Error: " . $e->getMessage();
            }
        }
    }
}

// ==========================
// FETCH LISTINGS (Fixed the missing column error)
// ==========================
if (isset($conn)) {
    try {
        $sql = "
            SELECT 
                a.appointment_id,
                CONCAT(pat.first_name, ' ', pat.last_name) AS patient_name,
                pat.patient_id,
                pat.email AS patient_email,
                pat.outstanding_balance,
                s.service_name,
                a.appointment_date,
                a.appointment_time,
                a.status,
                dp.proof_image,
                dp.amount AS initial_fee
            FROM 
                appointments a
            JOIN 
                patient pat ON a.patient_id = pat.patient_id
            JOIN 
                services s ON a.service_id = s.service_id
            LEFT JOIN
                patient_downpayment dp ON a.appointment_id = dp.appointment_id
            {$where_sql} 
            ORDER BY a.appointment_date DESC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $error = "Failed to fetch appointments: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Booking Management - DentiTrack</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
<style>
/* CSS Variables for a clean, consistent color scheme */
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
    --accent-orange: #ffc107;
}

/* Base Layout and Styling */
*,*::before,*::after{box-sizing:border-box;}
body{margin:0;padding:0;height:100%;font-family:'Inter', sans-serif;background:var(--bg-page);color:var(--text-dark);display:flex;min-height:100vh;overflow-x:hidden;}

/* --- Sidebar Styling (Fixed & Aligned) --- */
.sidebar { 
    width: var(--sidebar-width); 
    background: var(--widget-bg); 
    padding: 0; 
    color: var(--text-dark); 
    box-shadow: 2px 0 10px rgba(0,0,0,0.05); 
    display: flex; /* Enables flex container */
    flex-direction: column; /* Stacks children vertically */
    position: fixed; 
    height: 100vh; /* Sets fixed viewport height */
    z-index: 1000;
    transition: transform 0.3s ease, width 0.3s ease;
}
.sidebar-header {
    padding: 25px;
    text-align: center;
    margin-bottom: 20px;
    font-size: 22px;
    font-weight: 700;
    color: var(--primary-blue);
    border-bottom: 1px solid #e9ecef;
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 10px;
}
.sidebar-nav {
    flex-grow: 1; /* Allows nav to take up remaining height */
    padding: 0 15px;
    overflow-y: auto; /* Key for automatic scroll */

    /* --- Hidden Scrollbar CSS --- */
    -ms-overflow-style: none;  /* IE and Edge */
    scrollbar-width: none;     /* Firefox */
}
/* Chrome, Safari, and Opera scrollbar hiding */
.sidebar-nav::-webkit-scrollbar {
    display: none;
}
/* --- End Hidden Scrollbar CSS --- */


.sidebar a {
    display: flex; 
    align-items: center; 
    gap: 12px; 
    padding: 12px 15px; 
    margin: 8px 0; /* Adjusted margin to be inside padding area */
    color: var(--text-dark); 
    text-decoration: none; 
    font-weight: 500; 
    border-radius: 8px;
    transition: background-color 0.3s ease, color 0.3s ease;
}
.sidebar a:hover { 
    background-color: rgba(0, 123, 255, 0.08); 
    color: var(--primary-blue);
}
.sidebar a.active { 
    background-color: var(--primary-blue);
    color: white; 
    font-weight: 600;
    box-shadow: 0 4px 8px rgba(0, 123, 255, 0.2);
}
.sidebar a i {
    font-size: 18px;
    color: var(--text-light);
    transition: color 0.3s ease;
}
.sidebar a.active i {
    color: white; 
}
.sidebar a:hover i {
    color: var(--primary-blue);
}
.sidebar a.active:hover i {
    color: white;
}
.sidebar .logout {
    margin-top: auto; /* Pushes element to the bottom */
    border-top: 1px solid #e9ecef; 
    margin: 0;
    padding: 15px 30px; 
    border-radius: 0;
    flex-shrink: 0; /* Ensures it retains its size when space is limited */
}
.sidebar .logout:hover {
    background-color: #fce8e8;
    color: var(--alert-red);
}
.sidebar .logout:hover i {
    color: var(--alert-red);
}

/* --- MOBILE SPECIFIC HEADER (Only visible on mobile) --- */
.mobile-header { 
    display: none; /* Hidden by default */
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 60px;
    background: var(--widget-bg);
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    z-index: 100;
    justify-content: space-between;
    align-items: center;
    padding: 0 20px;
}
.mobile-logo {
    font-size: 22px;
    font-weight: 700;
    color: var(--primary-blue);
}
#menu-toggle-open {
    background: none;
    border: none;
    font-size: 24px;
    color: var(--primary-blue);
    cursor: pointer;
}
/* --- Main Content --- */
main.main-content{
    flex:1;
    background:var(--bg-page); /* Changed to bg-page for consistent main background */
    padding:40px;
    overflow-y:auto;
    margin-left: var(--sidebar-width);
    transition: margin-left 0.3s ease;
}
header h1{
    font-size:2.0rem;
    font-weight:800;
    color:var(--text-dark); /* Changed to text-dark for main content consistency */
    display:flex;
    align-items:center;
    gap:10px;
    margin-bottom: 30px; /* Increased margin for better spacing */
}
hr { border: none; border-top: 1px solid #dee2e6; margin-bottom: 30px; }

/* Flash Messages */
.flash-message{
    padding:15px 20px;
    margin-bottom:25px;
    border-radius:8px;
    font-weight:600;
    display:flex;
    align-items:center;
    gap:10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.flash-success{background:#e6ffed;color:var(--success-green); border: 1px solid #b7eb8f;}
.flash-error{background:#fff1f0;color:var(--alert-red); border: 1px solid #ffa39e;}

/* Filter and Search Container */
.controls-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    background: var(--widget-bg); /* Changed to widget-bg for container background */
    padding: 20px; /* Increased padding */
    border-radius: 10px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}
.search-form {
    display: flex;
    gap: 15px;
}
.search-form input[type="text"], .search-form select {
    padding: 10px 15px;
    border: 1px solid #ced4da;
    border-radius: 8px;
    font-size: 15px;
    transition: all 0.3s;
    box-shadow: inset 0 1px 2px rgba(0,0,0,0.05);
}
.search-form input[type="text"]:focus, .search-form select:focus {
    border-color: var(--primary-blue);
    outline: none;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
}
.search-form button {
    padding: 10px 15px;
    background-color: var(--primary-blue);
    color: white;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-size: 15px;
    font-weight: 600;
    transition: background-color 0.3s;
    display: flex;
    align-items: center;
    gap: 5px;
}
.search-form button:hover {
    background-color: var(--secondary-blue);
}
.search-form a.action-btn { 
    padding: 10px 15px;
    background-color: #6c757d; 
    color: white;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 5px;
    transition: background-color 0.3s;
}
.search-form a.action-btn:hover {
    background-color: #5a6268;
}

/* Table Styling */
.table-container{overflow-x:auto;}
.data-table{
    width:100%;
    border-collapse:separate;
    border-spacing: 0;
    margin-top:0;
    background: var(--widget-bg);
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
}
.data-table th,.data-table td{
    padding:15px 18px;
    text-align:left;
    border-bottom:1px solid #f0f0f0;
}
.data-table th{
    background-color:var(--primary-blue); /* Changed to primary blue for consistency with header/sidebar active */
    color:white;
    font-weight:700;
    text-transform:uppercase;
    font-size:0.85rem;
}
.data-table th:first-child { border-top-left-radius: 10px; }
th:last-child { border-top-right-radius: 10px; }
.data-table tr:last-child td { border-bottom: none; }
.data-table tr:hover{background-color:#f5f8fc;}

/* Status Tags */
.status-tag{padding:5px 12px;border-radius:15px;font-weight:700;font-size:0.8rem;}
.status-booked{background:#fff3e0;color:#e65100;}
.status-approved{background:#e8f5e9;color:var(--success-green);}
.status-declined{background:#ffebee;color:var(--alert-red);}
.status-completed{background:#e3f2fd;color:#1976d2;}
.status-cancelled{background:#eceff1;color:#455a64;}
.status-pending_installment{background:#fff8e1;color:#ff8f00;}

/* Action Buttons */
.action-btn{
    padding:8px 15px;
    border:1px solid transparent;
    border-radius:8px;
    cursor:pointer;
    font-size:0.85rem;
    font-weight:600;
    transition:all 0.2s; 
    display:inline-flex; 
    align-items:center;
    gap:5px;
    margin-left:5px;
    text-decoration: none;
}
.btn-view{background-color:#00bcd4;color:white;}
.btn-view:hover{background-color:#0097a7;}
.btn-approve{background-color:var(--success-green);color:white; border: 1px solid var(--success-green);}
.btn-approve:hover{background-color:#1e7e34; border-color: #1e7e34;}
.btn-decline{background-color:var(--alert-red);color:white; border: 1px solid var(--alert-red);}
.btn-decline:hover{background-color:#c82333; border-color: #c82333;}
.btn-remind{background-color:var(--accent-orange);color:white; border: 1px solid var(--accent-orange);}
.btn-remind:hover{background-color:#e0a800; border-color: #e0a800;}


/* Modal Styling */
.modal{display:none;position:fixed;z-index:1000;left:0;top:0;width:100%;height:100%;overflow:auto;background-color:rgba(0,0,0,0.6);}
.modal-content{
    background-color:var(--widget-bg);
    margin:5% auto;
    padding:30px;
    width:90%;
    max-width:650px;
    border-radius:12px;
    box-shadow:0 10px 30px rgba(0,0,0,0.4);
    animation: fadeIn 0.3s;
}
@keyframes fadeIn {
    from {opacity: 0; transform: translateY(-20px);}
    to {opacity: 1; transform: translateY(0);}
}
.close-btn{color:var(--text-light);float:right;font-size:30px;font-weight:bold;}
.close-btn:hover,.close-btn:focus{color:var(--alert-red);text-decoration:none;cursor:pointer;}
.modal-content h2 { 
    border-bottom: 1px solid #e9ecef; 
    padding-bottom: 10px; 
    margin-bottom: 25px; 
    color: var(--primary-blue); 
    font-size: 1.8rem;
    font-weight: 700;
}
.detail-item { 
    display: flex; 
    justify-content: space-between; 
    padding: 10px 0; 
    border-bottom: 1px dashed #eee; 
    font-size: 15px;
}
.detail-item:last-child { border-bottom: none; }
.detail-item strong { color: var(--text-dark); font-weight: 600; }
.proof-section { 
    margin-top: 25px; 
    border-top: 1px solid #e9ecef; 
    padding-top: 20px; 
}
.proof-section h3 {
    color: var(--secondary-blue);
    font-size: 1.1rem;
    margin-top: 0;
    margin-bottom: 10px;
}
.proof-section img { 
    max-width: 100%; 
    height: auto; 
    display: block; 
    margin-top: 10px; 
    border: 1px solid #ced4da; 
    border-radius: 8px; 
}

/* =================================== */
/* --- RESPONSIVE MOBILE STYLES --- */
/* =================================== */

@media (max-width: 992px) {
    /* Show mobile header */
    .mobile-header {
        display: flex; 
    }
    
    /* Hide the desktop header on mobile */
    header h1 {
        padding-top: 0;
    }

    /* Full-width sidebar, hiding menu off-screen initially */
    .sidebar {
        width: 80%; /* Takes up 80% of screen */
        max-width: 300px;
        height: 100%;
        position: fixed;
        top: 0;
        left: 0;
        transform: translateX(-100%);
        box-shadow: none;
        padding: 0;
        z-index: 1050;
    }
    
    /* When sidebar is 'open', slide it into view */
    .sidebar.open {
        transform: translateX(0);
        box-shadow: 5px 0 15px rgba(0,0,0,0.2);
    }
    
    /* Main content takes full width, no margin compensation */
    main.main-content {
        margin-left: 0;
        padding: 20px; /* Reduced padding for mobile screens */
        padding-top: 80px; /* Push content down below the fixed mobile header */
    }

    /* Sidebar header behavior in mobile view (add a close button to the header area) */
    .sidebar-header {
        display: flex; 
        justify-content: space-between;
        align-items: center;
    }
    /* Add a specific style for the close button inside the sidebar-header on mobile */
    .sidebar-header button {
        background: none;
        border: none;
        color: var(--text-light);
        font-size: 24px;
        cursor: pointer;
        padding: 0;
    }
    
    /* Navigation links take full width in mobile menu */
    .sidebar a {
        margin: 0 0;
        border-radius: 0;
        padding: 15px 30px;
        border-bottom: 1px solid #f0f0f0;
    }
    .sidebar a.logout {
        margin-top: 10px;
        border-top: 1px solid #e9ecef;
    }
    
    /* Filters and Search stacking */
    .controls-container {
        flex-direction: column;
        align-items: flex-start;
        padding: 15px;
    }
    .search-form {
        flex-direction: column;
        width: 100%;
        gap: 10px;
    }
    .search-form input[type="text"], .search-form select, .search-form button, .search-form a.action-btn {
        width: 100%;
    }
    
    /* Table Responsive Scroll */
    .table-container {
        overflow-x: auto;
    }
    .data-table {
        min-width: 800px; /* Force table to scroll horizontally for better readability */
    }
}
</style>
</head>
<body>

<div class="mobile-header" id="mobileHeader">
    <button id="menu-toggle-open"><i class="fas fa-bars"></i></button>
    <div class="mobile-logo">DentiTrack</div>
    <div style="width: 24px;"></div> </div>


<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <span><i class="fas fa-tooth"></i> DentiTrack</span>
        <button id="menu-toggle-close" style="display: none;"><i class="fas fa-times"></i></button>
    </div>
    <nav class="sidebar-nav">
        <a href="secretary_dashboard.php" class="<?= $current_page === 'secretary_dashboard' ? 'active' : '' ?>"><i class="fas fa-home"></i> Dashboard</a>
        <a href="find_patient.php" class="<?= $current_page === 'find_patient' ? 'active' : '' ?>"><i class="fas fa-search"></i> Find Patient</a>
        <a href="view_patients.php" class="<?= $current_page === 'view_patients' ? 'active' : '' ?>"><i class="fas fa-users"></i> Patients List</a>
        <a href="online_bookings.php" class="<?= $current_page === 'online_bookings' ? 'active' : '' ?>"><i class="fas fa-calendar-check"></i> Booking Mgmt</a>
        <a href="appointments.php" class="<?= $current_page === 'appointments' ? 'active' : '' ?>"><i class="fas fa-calendar-alt"></i> Consultations</a>
        <a href="services_list.php" class="<?= $current_page === 'services_list' ? 'active' : '' ?>"><i class="fas fa-briefcase-medical"></i> Services</a>
        <a href="inventory.php" class="<?= $current_page === 'inventory' ? 'active' : '' ?>"><i class="fas fa-boxes"></i> Inventory Stock</a>
        <a href="payments.php" class="<?= $current_page === 'payments' ? 'active' : '' ?>"><i class="fas fa-money-bill-wave"></i> Process Payments</a>
         <a href="pending_installments.php"><i class="fas fa-credit-card"></i> Pending Installments</a>
         <a href="pending_payments.php"><i class="fas fa-credit-card"></i> Pending Payments</a>
        <a href="payment_logs.php" class="<?= $current_page === 'payment_logs' ? 'active' : '' ?>"><i class="fas fa-file-invoice-dollar"></i> Payments Log</a>
        <a href="create_announcements.php" class="<?= $current_page === 'create_announcements' ? 'active' : '' ?>"><i class="fas fa-bullhorn"></i> Announcements</a>
    </nav>
    <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Log Out</a>
</div>

<main class="main-content">
    <header><h1><i class="fas fa-calendar-check"></i> Booking Management</h1></header>

    <?php if ($error): ?>
    <div class="flash-message flash-error"><i class="fas fa-times-circle"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="flash-message flash-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="controls-container">
        <form method="GET" class="search-form">
            <input type="text" name="search" placeholder="Search Patient/Service" value="<?= htmlspecialchars($search_term) ?>">
            <select name="status">
                <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>All Statuses</option>
                <option value="booked" <?= $filter_status === 'booked' ? 'selected' : '' ?>>Booked</option>
                <option value="pending_installment" <?= $filter_status === 'pending_installment' ? 'selected' : '' ?>>Pending Installment</option>
                <option value="approved" <?= $filter_status === 'approved' ? 'selected' : '' ?>>Approved</option>
                <option value="declined" <?= $filter_status === 'declined' ? 'selected' : '' ?>>Declined</option>
                <option value="completed" <?= $filter_status === 'completed' ? 'selected' : '' ?>>Completed</option>
            </select>
            <button type="submit"><i class="fas fa-filter"></i> Filter</button>
            <?php if ($search_term || $filter_status !== 'all'): ?>
                <a href="online_bookings.php" class="action-btn"><i class="fas fa-times"></i> Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="table-container">
        <?php if (!empty($appointments)): ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Patient Name</th>
                    <th>Service</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($appointments as $appointment): 
                    $status_class = 'status-' . strtolower($appointment['status']);
                    $is_pending = strtolower($appointment['status']) === 'booked';
                    $is_approved = strtolower($appointment['status']) === 'approved';
                    $is_completed = strtolower($appointment['status']) === 'completed';
                    $has_outstanding = $appointment['outstanding_balance'] > 0;
                ?>
                <tr>
                    <td><?= htmlspecialchars($appointment['appointment_id']) ?></td>
                    <td><?= htmlspecialchars($appointment['patient_name']) ?></td>
                    <td><?= htmlspecialchars($appointment['service_name']) ?></td>
                    <td><?= date('M d, Y', strtotime($appointment['appointment_date'])) ?></td>
                    <td><?= htmlspecialchars($appointment['appointment_time']) ?></td>
                    <td><span class="status-tag <?= $status_class ?>"><?= ucfirst($appointment['status']) ?></span></td>
                    <td>
                        <button class="action-btn btn-view" onclick="showAppointmentDetailsModal(
                            '<?= htmlspecialchars($appointment['patient_name']) ?>', 
                            '<?= htmlspecialchars($appointment['service_name']) ?>', 
                            '<?= date('M d, Y', strtotime($appointment['appointment_date'])) ?>', 
                            '<?= htmlspecialchars($appointment['appointment_time']) ?>', 
                            '<?= ucfirst($appointment['status']) ?>', 
                            '<?= htmlspecialchars($appointment['proof_image'] ?? '') ?>',
                            '<?= number_format($appointment['initial_fee'] ?? 0, 2) ?>',
                            '<?= number_format($appointment['outstanding_balance'], 2) ?>'
                        )">
                            <i class="fas fa-eye"></i> View
                        </button>
                        <?php if ($is_pending): ?>
                            <form method="POST" style="display:inline-block; margin-left: 5px;">
                                <input type="hidden" name="appointment_id" value="<?= $appointment['appointment_id'] ?>">
                                <button type="submit" name="approve_appointment" class="action-btn btn-approve" title="Approve Appointment">
                                    <i class="fas fa-check"></i> Approve
                                </button>
                            </form>
                            <form method="POST" style="display:inline-block; margin-left: 5px;">
                                <input type="hidden" name="appointment_id" value="<?= $appointment['appointment_id'] ?>">
                                <button type="submit" name="decline_appointment" class="action-btn btn-decline" title="Decline Appointment">
                                    <i class="fas fa-times"></i> Decline
                                </button>
                            </form>
                        <?php endif; ?>
                        
                        </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p style="text-align: center; margin-top: 30px; font-size: 1.1rem; color: var(--text-light);">No appointments found matching your criteria.</p>
        <?php endif; ?>
    </div>

    <div id="appointmentDetailsModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="document.getElementById('appointmentDetailsModal').style.display='none'">&times;</span>
            <h2>Appointment Details</h2>

            <div class="detail-item"><strong>Patient Name:</strong> <span id="modal-patient-name"></span></div>
            <div class="detail-item"><strong>Service:</strong> <span id="modal-service-name"></span></div>
            <div class="detail-item"><strong>Date:</strong> <span id="modal-date"></span></div>
            <div class="detail-item"><strong>Time:</strong> <span id="modal-time"></span></div>
            <div class="detail-item"><strong>Status:</strong> <span id="modal-status"></span></div>
            <div class="detail-item"><strong>Downpayment:</strong> ₱<span id="modal-initial-fee"></span></div>
            <div class="detail-item"><strong>Outstanding Balance:</strong> ₱<span id="modal-outstanding-balance"></span></div>
            
            <div class="proof-section">
                <h3>Proof of Downpayment</h3>
                <a href="#" id="proof-image-link" target="_blank" style="display: none; font-weight: 600; color: var(--primary-blue);">View Full Image</a>
                <span id="proof-status"></span>
                <img id="proof-image" src="" alt="Proof of Payment" style="display: none;">
            </div>
        </div>
    </div>

</main>

<script>
// --- MODAL LOGIC (Unchanged from original) ---
function showAppointmentDetailsModal(patientName, serviceName, date, time, status, imageUrl, initialFee, outstandingBalance) {
    const modal = document.getElementById('appointmentDetailsModal');
    document.getElementById('modal-patient-name').textContent = patientName;
    document.getElementById('modal-service-name').textContent = serviceName;
    document.getElementById('modal-date').textContent = date;
    document.getElementById('modal-time').textContent = time;
    document.getElementById('modal-status').textContent = status;
    document.getElementById('modal-initial-fee').textContent = initialFee;
    document.getElementById('modal-outstanding-balance').textContent = outstandingBalance;

    // Handle Proof of Payment Image
    const proofImage = document.getElementById('proof-image');
    const proofImageLink = document.getElementById('proof-image-link');
    const proofStatus = document.getElementById('proof-status');

    if (imageUrl && imageUrl.trim() !== '') {
        const fullUrl = '../' + imageUrl; 
        proofImage.src = fullUrl;
        proofImageLink.href = fullUrl;
        proofImageLink.style.display = 'block';
        proofImage.style.display = 'block';
        proofStatus.textContent = '';
    } else {
        proofImage.src = ''; // Clear source
        proofImage.style.display = 'none';
        proofImageLink.style.display = 'none';
        proofStatus.textContent = 'No proof of downpayment provided.';
    }

    modal.style.display = 'block';
}

// Close the modal if the user clicks anywhere outside of it
window.onclick = function(event) {
    const modal = document.getElementById('appointmentDetailsModal');
    if (event.target == modal) {
        modal.style.display = 'none';
    }
}


// --- FORM & SEARCH LOGIC (Slightly revised) ---
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('.search-form');
    const statusFilter = form.querySelector('select[name="status"]');
    const searchInput = form.querySelector('input[name="search"]');

    // Function to submit the form
    const autoSubmitForm = () => {
        form.submit();
    };
    
    // Attach change listener for automatic search on status select
    statusFilter.addEventListener('change', autoSubmitForm);

    // Optional: Auto-submit search input on 'Enter' key press
    searchInput.addEventListener('keypress', function(event) {
        if (event.key === 'Enter') {
            event.preventDefault(); 
            autoSubmitForm();
        }
    });

    // --- MOBILE MENU TOGGLE ---
    const sidebar = document.getElementById('sidebar');
    const menuToggleOpen = document.getElementById('menu-toggle-open');
    const menuToggleClose = document.getElementById('menu-toggle-close');

    // Check for small screen
    if (window.matchMedia('(max-width: 992px)').matches) {
        // Toggle open
        if (menuToggleOpen) {
            menuToggleOpen.addEventListener('click', function() {
                sidebar.classList.add('open');
                // The close button inside the sidebar header
                const closeBtnInSidebar = sidebar.querySelector('#menu-toggle-close');
                if (closeBtnInSidebar) {
                    closeBtnInSidebar.style.display = 'block';
                }
                document.body.style.overflow = 'hidden'; // Prevent scrolling when menu is open
            });
        }
        
        // Toggle close
        if (menuToggleClose) {
             menuToggleClose.addEventListener('click', function() {
                sidebar.classList.remove('open');
                menuToggleClose.style.display = 'none'; // Hide close button
                document.body.style.overflow = ''; // Restore scrolling
            });
        }

        // Close menu if a link is clicked (navigating away)
        document.querySelectorAll('.sidebar-nav a').forEach(link => {
            link.addEventListener('click', function() {
                setTimeout(() => {
                    sidebar.classList.remove('open');
                    document.body.style.overflow = '';
                }, 300); // Small delay to allow transition effect
            });
        });
    }

});
</script>
</body>
</html>