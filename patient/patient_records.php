<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../public/login.php");
    exit;
}

// --- Database Connection Fix (Using PDO) ---
if (file_exists('../config/db_pdo.php')) {
    require_once '../config/db_pdo.php'; 
    $conn = $pdo; // Standardize PDO connection variable
} else {
    die("Error: Could not find database config file at ../config/db_pdo.php");
}

// Ensure PDO attributes are set
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$user_id = $_SESSION['user_id'];
$full_name = "Patient";

// Helper function for PDO queries
function fetch_pdo_single($conn, $sql, $params = []) {
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("DB Error (Single Fetch): " . $e->getMessage());
        return false;
    }
}
function fetch_pdo_all($conn, $sql, $params = []) {
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("DB Error (All Fetch): " . $e->getMessage());
        return [];
    }
}
function fetch_pdo_column($conn, $sql, $params = []) {
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("DB Error (Column Fetch): " . $e->getMessage());
        return 0;
    }
}

// 1. FETCH FULL NAME & Patient ID
$sql_name = "SELECT patient_id, first_name, last_name FROM patient WHERE user_id = ?";
$patient_details = fetch_pdo_single($conn, $sql_name, [$user_id]);

if ($patient_details) {
    $patient_id = $patient_details['patient_id'];
    $full_name = htmlspecialchars($patient_details['first_name']) . " " . htmlspecialchars($patient_details['last_name']);
} else {
    $patient_id = 0; // Use 0 or appropriate fallback if record isn't linked
}

// Check if patient_id is valid before proceeding
if ($patient_id === 0) {
    $outstanding_balance = 0.00;
    $all_records = $appointments = $transactions = $services_map = [];
    goto skip_db_fetches;
}

// --- Fetch Outstanding Balance (Used in Transactions Tab) ---
$sql_balance = "SELECT outstanding_balance FROM patient WHERE patient_id = ?";
$outstanding_balance = fetch_pdo_column($conn, $sql_balance, [$patient_id]);
if ($outstanding_balance === false) $outstanding_balance = 0.00;


// --- 0. FETCH SERVICES MAP (Used for Appointments and Transactions) ---
$services_map = [];
$sql_services = "SELECT service_id, service_name FROM services";
$result_services = $conn->query($sql_services);
if ($result_services) {
    while ($row = $result_services->fetch(PDO::FETCH_ASSOC)) {
        $services_map[$row['service_id']] = htmlspecialchars($row['service_name']);
    }
}

// --- 1. FETCH DOCTOR UPLOADED FILES ---
$sql_files = "SELECT file_name, file_path, uploaded_at AS date, 'Doctor File' AS type, '' AS description FROM patient_files WHERE patient_id = ?";
$patient_files = fetch_pdo_all($conn, $sql_files, [$patient_id]);


// --- 2. FETCH DOCTOR RECOMMENDATIONS ---
$sql_recommendations = "SELECT created_at AS date, recommendation AS description, 'Recommendation' AS type, '' AS file_name, '' AS file_path FROM patient_recommendations WHERE patient_id = ?";
$patient_recommendations = fetch_pdo_all($conn, $sql_recommendations, [$patient_id]);


/* 3. COMBINE & SORT FILES/RECOMMENDATIONS LATEST FIRST */
$all_records = array_merge($patient_files, $patient_recommendations);
usort($all_records, function($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});


// 4. FETCH ALL APPOINTMENTS (ALL DATABASE RECORDS)
$sql_appts = "SELECT appointment_id, appointment_date, appointment_time, comments, service_id, status, created_at FROM appointments WHERE patient_id = ? ORDER BY appointment_date DESC";
$appointments = fetch_pdo_all($conn, $sql_appts, [$patient_id]);


// 5. FETCH ALL TRANSACTIONS (Individual Payment Transactions)
$sql_trans = "
SELECT 
    pt.transaction_id, pt.amount_received, pt.transaction_date, pt.payment_method AS transaction_method, 
    pt.payment_proof_path AS proof_image, p.payment_id, p.total_amount AS master_total_amount, p.discount_type, 
    p.discount_amount, p.status AS master_payment_status, a.appointment_id, a.appointment_date, 
    a.appointment_time, a.comments AS appointment_comments, s.service_name 
FROM payment_transactions pt
LEFT JOIN payments p ON pt.payment_id = p.payment_id
LEFT JOIN appointments a ON p.appointment_id = a.appointment_id
LEFT JOIN services s ON p.service_id = s.service_id
WHERE pt.patient_id = ?
ORDER BY pt.transaction_date DESC
";
$transactions = fetch_pdo_all($conn, $sql_trans, [$patient_id]);

skip_db_fetches: 

/* ==========================================================
    Helper for file icons
========================================================== */
function get_file_icon($file_name) {
    $ext = pathinfo($file_name, PATHINFO_EXTENSION);
    switch (strtolower($ext)) {
        case 'pdf': return 'fas fa-file-pdf text-danger';
        case 'jpg':
        case 'jpeg':
        case 'png': return 'fas fa-file-image text-success';
        case 'doc':
        case 'docx': return 'fas fa-file-word text-primary';
        default: return 'fas fa-file text-secondary';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Patient Records & Transactions - DentiTrack</title>
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
/* --- Sidebar Styling (Fixed & Consistent UI) --- */
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
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 10px;
}
.sidebar-nav {
    flex-grow: 1;
    padding: 0 15px;
    overflow-y: auto;
    /* Hidden Scrollbar */
    -ms-overflow-style: none;
    scrollbar-width: none;
}
.sidebar-nav::-webkit-scrollbar {
    display: none;
}
.sidebar a {
    padding: 12px 15px;
    margin: 8px 0;
    color: var(--text-dark);
    text-decoration: none;
    border-radius: 8px;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 500;
}
.sidebar a:hover {
    background: rgba(0, 123, 255, 0.08);
    color: var(--primary-blue);
}
.sidebar a.active {
    background: var(--primary-blue);
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
    background: #fce8e8;
    color: var(--alert-red);
}


/* --- Main Content --- */
.main-content { 
    flex:1; 
    margin-left:var(--sidebar-width); 
    padding:40px; 
    background:var(--bg-page); 
    overflow-y:auto; 
}
h1 { 
    display: flex; 
    align-items: center; 
    gap: 10px; 
    font-size: 1.8rem; 
    color: var(--secondary-blue); 
    border-bottom: 2px solid #e0e0e0; 
    padding-bottom: 10px; 
    margin-bottom: 30px; 
}

/* --- TAB and Dropdown Styling --- */
.tab-controls {
    margin-bottom: 20px;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
}
.tab-btn { 
    padding: 10px 18px; 
    background: var(--primary-blue); 
    color:white; 
    border:none; 
    border-radius:8px; 
    font-weight:600; 
    cursor:pointer; 
    transition: background 0.3s;
    font-size: 1rem;
}
.tab-btn:hover {
    background: var(--secondary-blue);
}
.tab-btn.active { 
    background: var(--accent-orange); 
    color: var(--text-dark);
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}
.appt-filter-control {
    margin-left: auto; /* Push filter to the right in desktop view */
    display: flex;
    align-items: center;
    gap: 10px;
}
.appt-filter-control label {
    font-weight: 600;
    color: var(--text-dark);
}
#apptFilter { 
    padding: 10px 15px;
    border-radius: 8px; 
    border: 1px solid #ced4da; 
    font-size: 1rem;
    font-family: 'Inter', sans-serif;
    transition: border-color 0.3s;
}
#apptFilter:focus {
    border-color: var(--primary-blue);
    outline: none;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
}

/* Content visibility toggle */
.tab-content { display:none; }
.tab-content.active { display:block; }

.records-container { 
    background:var(--widget-bg); 
    padding:25px; 
    border-radius:10px; 
    box-shadow: 0 4px 15px rgba(0,0,0,0.05); 
}
.records-container h3 {
    color: var(--text-dark);
    font-size: 1.2rem;
    font-weight: 600;
    margin-top: 0;
    padding-bottom: 10px;
    border-bottom: 1px dashed #eee;
    margin-bottom: 20px;
}
.records-table { 
    width:100%; 
    border-collapse:separate; 
    border-spacing: 0;
    font-size: 0.95rem;
}
.records-table th { 
    padding:12px 15px; 
    background: var(--primary-blue); 
    color: white; 
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.8rem;
}
.records-table td { 
    padding:12px 15px; 
    border-bottom:1px solid #f0f0f0;
}
.records-table tr:last-child td { border-bottom: none; }
.records-table tr:hover { background: #f5f8fc; }

/* Outstanding Balance Box */
#transactions .records-container:first-child {
    border: 2px solid var(--alert-red); 
    background: #fffafa; /* Lighter red tint */
    margin-bottom: 30px;
}
#transactions .records-container:first-child h2 {
    color: var(--alert-red); 
    margin-top: 0;
    font-size: 1.5rem;
    padding-bottom: 10px;
    border-bottom: 1px dashed var(--alert-red);
}
#currentBalanceDisplay {
    font-weight: 800;
    color: var(--alert-red); 
    font-size: 1.8em;
}

/* Status Colors */
.text-danger { color: var(--alert-red); }
.text-success { color: var(--success-green); }
.text-primary { color: var(--primary-blue); }
.text-secondary { color: var(--text-light); }


/* Action Button Styles */
.action-btn-small { 
    background: #0066cc; 
    color: white; 
    border: none; 
    padding: 8px 12px; /* Slightly larger padding */
    border-radius: 8px; /* More rounded corners */
    cursor: pointer; 
    font-size: 0.85rem; /* Slightly larger font */
    transition: all 0.3s;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
.action-btn-small:hover {
    background: var(--secondary-blue);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}
.action-btn-cancel {
    background: var(--alert-red);
}
.action-btn-cancel:hover {
    background: #b32a39;
}


/* Modal Styling */
.modal {
    display: none; 
    position: fixed; 
    z-index: 1000; 
    left: 0;
    top: 0;
    width: 100%; 
    height: 100%; 
    overflow: auto; 
    background-color: rgba(0,0,0,0.6); 
    padding-top: 50px;
}
.modal-content {
    background-color: var(--widget-bg);
    margin: 5% auto; 
    padding: 30px;
    border-radius: 12px;
    width: 90%; 
    max-width: 600px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.4);
}
.close {
    color: var(--text-light);
    float: right;
    font-size: 30px;
    font-weight: bold;
    transition: color 0.3s;
}
.close:hover, .close:focus {
    color: var(--alert-red);
    text-decoration: none;
    cursor: pointer;
}
.modal-detail-row {
    padding: 8px 0;
    border-bottom: 1px dashed #eee;
}
.modal-detail-row:last-child {
    border-bottom: none;
}
.modal-detail-row strong {
    display: inline-block;
    width: 150px;
    color: var(--primary-blue);
    font-weight: 600;
}
.modal-detail-row span {
    color: var(--text-dark);
}
#transactionDetailsModal .modal-content {
    max-width: 700px;
}
#trans-detail-proof-image {
    max-width: 100%; height: auto; display: block; margin: 0 auto; cursor: pointer;
    border: 1px solid #ddd;
    border-radius: 5px;
}

/* --- MOBILE SPECIFIC HEADER --- */
.mobile-header { 
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 60px;
    background: white;
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

/* Responsive Design (for smaller screens) */
@media (max-width: 992px) {
    .mobile-header {
        display: flex; 
    }
    .main-content {
        margin-left: 0;
        padding: 20px;
        padding-top: 80px;
    }
    .sidebar {
        width: 80%;
        max-width: 300px;
        position: fixed;
        transform: translateX(-100%);
        z-index: 1050;
        height: 100vh;
    }
    .sidebar.open {
        transform: translateX(0);
        box-shadow: 5px 0 15px rgba(0,0,0,0.2);
    }
    .sidebar-header {
        justify-content: space-between;
        color: var(--primary-blue); 
    }
    .sidebar-header button {
        display: block !important;
        background: none;
        border: none;
        color: var(--text-dark);
        font-size: 24px;
    }
    .sidebar-nav {
        overflow-y: auto !important; 
        padding: 0;
    }
    .sidebar a {
        margin: 0;
        border-radius: 0;
        padding: 15px 30px;
        border-bottom: 1px solid #f0f0f0;
    }
    .sidebar .logout {
        padding: 15px 30px;
        margin: 0;
        margin-top: 10px;
        border-radius: 0;
        border-top: 1px solid #f0f0f0;
    }
    .records-table {
        display: block;
        overflow-x: auto;
        white-space: nowrap;
        min-width: 700px;
    }
    .tab-controls {
        flex-direction: column;
        align-items: flex-start;
    }
    .tab-btn {
        width: 100%;
        margin-right: 0;
        margin-bottom: 10px;
        text-align: center;
    }
    .appt-filter-control {
        margin-left: 0;
        width: 100%;
        margin-top: 10px;
        flex-direction: column;
        align-items: flex-start;
    }
    #apptFilter {
        width: 100%;
    }
}
</style>
</head>

<body>

<div class="mobile-header" id="mobileHeader">
    <button id="menu-toggle-open"><i class="fas fa-bars"></i></button>
    <div class="mobile-logo">DentiTrack</div>
    <div style="width: 24px;"></div> 
</div>

<nav class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <i class="fas fa-tooth"></i> DentiTrack
        <button id="menu-toggle-close" style="display: none;"><i class="fas fa-times"></i></button>
    </div>
    <div class="sidebar-nav">
        <a href="patient_dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
        <a href="patient_appointments.php"><i class="fas fa-calendar-check"></i> Appointments</a>
        <a class="active" href="patient_records.php"><i class="fas fa-file-medical"></i> Dental Records</a>
        <a href="patient_payment.php"><i class="fas fa-credit-card"></i> Payments</a>
        <a href="Profile.php"><i class="fas fa-user"></i> Profile</a>
    </div>
    <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
</nav>

<main class="main-content">
    <h1><i class="fas fa-paperclip"></i> Patient Files, Appointments & Transactions</h1>

    <div class="tab-controls">
        <button class="tab-btn active" data-tab="records" onclick="showTab('records', this)">Files & Recommendations</button>
        <button class="tab-btn" data-tab="history" onclick="showTab('history', this)">Appointments History</button>
        <button class="tab-btn" data-tab="transactions" onclick="showTab('transactions', this)">Transactions</button>
    </div>

    <div id="records" class="tab-content active">
        <div class="records-container">
            <h3>All Items (Latest First)</h3>
            <?php if (!empty($all_records)): ?>
            <div style="overflow-x: auto;">
            <table class="records-table">
                <thead>
                    <tr>
                        <th style="width:15%;">Date</th>
                        <th style="width:15%;">Type</th>
                        <th style="width:50%;">Details / File Name</th>
                        <th style="width:20%;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_records as $record): ?>
                    <tr>
                        <td><?= date('Y-m-d', strtotime($record['date'])) ?></td>
                        <td><?= htmlspecialchars($record['type']) ?></td>
                        <td>
                            <?php if ($record['type'] === 'Doctor File'): ?>
                                <i class="<?= get_file_icon($record['file_name']) ?>"></i>
                                <?= htmlspecialchars($record['file_name']) ?>
                            <?php else: ?>
                                <?= htmlspecialchars($record['description']) ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($record['type'] === 'Doctor File'): ?>
                                <a href="../uploads/<?= $record['file_path'] ?>" target="_blank" class="action-btn-small">
                                    <i class="fas fa-download"></i> View/Download
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php else: ?>
                <div style="text-align:center; padding:20px;">No files or recommendations yet.</div>
            <?php endif; ?>
        </div>
    </div>

    <div id="history" class="tab-content">
        <div class="records-container">
            <h3>Appointment History (All Records)</h3>

            <div class="appt-filter-control">
                <label for="apptFilter">Filter by Status:</label>
                <select id="apptFilter" onchange="filterAppointments()">
                    <option value="all">All</option>
                    <option value="pending">Pending/Requested</option>
                    <option value="booked">Confirmed/Booked</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Declined/Cancelled</option>
                    <option value="no-show">No Show</option>
                </select>
            </div>

            <div style="overflow-x: auto;">
            <table class="records-table" id="apptTable">
                <thead>
                    <tr>
                        <th style="width:15%;">Date</th>
                        <th style="width:10%;">Time</th>
                        <th style="width:15%;">Service</th>
                        <th style="width:15%;">Status</th>
                        <th style="width:30%;">Comments</th>
                        <th style="width:15%;">Action</th> 
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($appointments as $row): 
                        $status_color = 'gray'; 
                        switch ($row['status']) {
                            case 'completed': $status_color = 'var(--success-green)'; break;
                            case 'booked': $status_color = 'var(--primary-blue)'; break;
                            case 'pending': $status_color = 'var(--accent-orange)'; break;
                            case 'cancelled':
                            case 'declined':
                            case 'no-show': $status_color = 'var(--alert-red)'; break;
                        }
                    ?>
                    <tr data-status="<?= htmlspecialchars($row['status']) ?>">
                        <td><?= htmlspecialchars($row['appointment_date']) ?></td>
                        <td><?= htmlspecialchars($row['appointment_time']) ?></td>
                        <td><?= $services_map[$row['service_id']] ?? 'Service ID: ' . $row['service_id'] ?></td>
                        <td style="font-weight:bold; color:<?= $status_color ?>;">
                            <?= ucfirst(htmlspecialchars($row['status'])) ?>
                        </td>
                        <td><?= htmlspecialchars($row['comments']) ?></td>
                        <td>
                            <button onclick="viewAppointmentDetails(<?= $row['appointment_id'] ?>)" 
                                    class="action-btn-small" style="margin-right: 5px;">
                                <i class="fas fa-eye"></i> Detail
                            </button>
                            
                            <?php if ($row['status'] === 'pending' || $row['status'] === 'booked'): ?>
                                <button onclick="confirmCancel(<?= $row['appointment_id'] ?>)" 
                                        class="action-btn-small action-btn-cancel">
                                    <i class="fas fa-times"></i> Cancel
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php if (empty($appointments)): ?>
                <div style="text-align:center; padding:20px;">No appointment history available.</div>
            <?php endif; ?>
        </div>
    </div>

    <div id="transactions" class="tab-content">
        <div class="records-container" style="margin-bottom: 30px; border: 2px solid var(--alert-red); background: #fffafa;">
            <h2 style="color: var(--alert-red); margin-top: 0; font-size: 1.5rem; padding-bottom: 10px; border-bottom: 1px dashed var(--alert-red);">
                <i class="fas fa-money-bill-wave"></i> Outstanding Balance Summary
            </h2>
            <p style="font-size: 1.5em; font-weight: bold; margin: 10px 0;">
                Current Total Balance: 
                <span id="currentBalanceDisplay" style="color: var(--alert-red);">
                    ₱<?= number_format($outstanding_balance, 2) ?>
                </span>
            </p>
            
            <?php if ($outstanding_balance <= 0): ?>
                <p style="color: var(--success-green); font-weight: bold; margin-top: 20px;">
                    <i class="fas fa-check-circle"></i> You have no outstanding balance at this time.
                </p>
            <?php endif; ?>
        </div>

        <div class="records-container">
            <h3>Transaction History (Individual Payment Logs)</h3>

            <?php if (!empty($transactions)): ?>
            <div style="overflow-x: auto;">
            <table class="records-table">
                <thead>
                    <tr>
                        <th style="width:15%;">Transaction Date</th>
                        <th style="width:20%;">Associated Service</th> 
                        <th style="width:15%;">Amount Received</th>
                        <th style="width:15%;">Payment Method</th>
                        <th style="width:15%;">Master Payment Status</th>
                        <th style="width:20%;">Action</th> 
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $row): 
                         $master_status = $row['master_payment_status'];
                         $status_color = ($master_status == 'paid') ? 'var(--success-green)' : (($master_status == 'pending') ? 'var(--accent-orange)' : 'var(--alert-red)');
                    ?>
                    <tr>
                        <td><?= date('Y-m-d', strtotime($row['transaction_date'])) ?></td>
                        <td><?= htmlspecialchars($row['service_name'] ?? 'N/A') ?></td>
                        <td>₱<?= number_format($row['amount_received'],2) ?></td>
                        <td><?= ucfirst(htmlspecialchars($row['transaction_method'])) ?></td>
                        <td style="font-weight:bold; color:<?= $status_color ?>;">
                            <?= ucfirst(htmlspecialchars($master_status)) ?>
                        </td>
                        <td>
                            <?php 
                                $row['total_amount'] = $row['master_total_amount']; // Fix key for JS
                                $json_data = json_encode(array_map('htmlspecialchars', $row), JSON_UNESCAPED_SLASHES);
                            ?>
                            <button onclick='viewTransactionDetails(<?= $json_data ?>)' 
                                    class="action-btn-small">
                                <i class="fas fa-eye"></i> View Log Detail
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php else: ?>
                <div style="text-align:center; padding:20px;">No payment transactions available for this patient.</div>
            <?php endif; ?>
        </div>
    </div>
    
    <div id="appointmentModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('appointmentModal')">&times;</span>
            <h2>Appointment Details</h2>
            <div id="appointmentDetailsContent">
                <div class="modal-detail-row"><strong>Appointment ID:</strong> <span id="detail-id"></span></div>
                <div class="modal-detail-row"><strong>Service:</strong> <span id="detail-service"></span></div>
                <div class="modal-detail-row"><strong>Date:</strong> <span id="detail-date"></span></div>
                <div class="modal-detail-row"><strong>Time:</strong> <span id="detail-time"></span></div>
                <div class="modal-detail-row"><strong>Status:</strong> <span id="detail-status"></span></div>
                <div class="modal-detail-row"><strong>Comments:</strong> <span id="detail-comments"></span></div>
                <div class="modal-detail-row"><strong>Created At:</strong> <span id="detail-created"></span></div>
            </div>
        </div>
    </div>

    <div id="transactionDetailsModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('transactionDetailsModal')">&times;</span>
            <h2><i class="fas fa-receipt"></i> Individual Transaction Log Details</h2>
            
            <h3 style="margin-top: 5px;">Transaction Details (This Log Entry)</h3>
            <div style="margin-bottom: 10px; border-bottom: 2px solid var(--primary-blue);">
                <div class="modal-detail-row"><strong>Transaction ID:</strong> <span id="trans-detail-id"></span></div>
                <div class="modal-detail-row"><strong>Transaction Date:</strong> <span id="trans-detail-date"></span></div>
                <div class="modal-detail-row"><strong>Amount Received:</strong> <span id="trans-detail-amount-received" style="font-weight: bold; color: var(--success-green);"></span></div>
                <div class="modal-detail-row"><strong>Payment Method:</strong> <span id="trans-detail-method"></span></div>
            </div>

            <h3 style="margin-top: 5px;">Master Payment & Appointment Summary</h3>
            <div style="margin-bottom: 20px;">
                <div class="modal-detail-row"><strong>Master Payment ID:</strong> <span id="trans-detail-payment-id"></span></div>
                <div class="modal-detail-row"><strong>Master Status:</strong> <span id="trans-detail-master-status" style="font-weight: bold;"></span></div>
                <div class="modal-detail-row"><strong>Total Master Amount:</strong> <span id="trans-detail-total"></span></div>
                <div class="modal-detail-row"><strong>Discount Type:</strong> <span id="trans-detail-discount-type"></span></div>
                <div class="modal-detail-row"><strong>Discount Amount:</strong> <span id="trans-detail-discount-amount"></span></div>
                <hr style="border-top: 1px dashed #ccc; margin: 15px 0;">
                <div class="modal-detail-row"><strong>Appointment ID:</strong> <span id="trans-appt-id"></span></div>
                <div class="modal-detail-row"><strong>Service:</strong> <span id="trans-appt-service"></span></div>
                <div class="modal-detail-row"><strong>Date/Time:</strong> <span id="trans-appt-datetime"></span></div>
                <div class="modal-detail-row"><strong>Appt. Notes:</strong> <span id="trans-appt-comments"></span></div>
            </div>

            <div id="trans-detail-proof-container" style="text-align: center; border: 1px solid #ddd; padding: 15px; border-radius: 8px;">
                <h3>Proof of Payment (for this specific transaction)</h3>
                <p id="trans-detail-proof-status" style="color: var(--alert-red); font-style: italic;"></p>
                <div id="trans-detail-proof-image-container" style="max-height: 300px; overflow: auto; margin-top: 10px; display: none;">
                    <img id="trans-detail-proof-image" src="" alt="Payment Proof" style="max-width: 100%; height: auto; display: block; margin: 0 auto; cursor: pointer;" onclick="window.open(this.src)">
                </div>
                <p style="margin-top: 15px; display: none;" id="trans-detail-download-link-container"><a id="trans-detail-download-link" href="#" download style="color:var(--primary-blue); font-weight: bold;"><i class="fas fa-download"></i> Download Image</a></p>
            </div>
        </div>
    </div>
    
    </main>

<script>
// --- Global Constants ---
const servicesMap = <?= json_encode($services_map) ?>;
const balanceFormatter = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });


/* ==========================================================
    UI & TAB LOGIC
========================================================== */
/**
 * Switches the active tab content and highlights the clicked button.
 * @param {string} id The ID of the content div to show ('records', 'history', 'transactions').
 * @param {HTMLElement} clickedButton The button element that was clicked.
 */
function showTab(id, clickedButton) {
    // 1. Hide all content tabs
    document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
    // 2. Show the selected content tab
    document.getElementById(id).classList.add('active');

    // 3. Deactivate all buttons
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    // 4. Activate the clicked button
    clickedButton.classList.add('active');
    
    // 5. For History tab, re-apply the filter
    if (id === 'history') {
        filterAppointments();
    }
}

function filterAppointments() {
    const filter = document.getElementById('apptFilter').value;
    const rows = document.querySelectorAll('#apptTable tbody tr');

    rows.forEach(row => {
        const status = row.getAttribute('data-status');
        row.style.display = (filter === 'all' || status.toLowerCase().startsWith(filter)) ? '' : 'none';
    });
}

// --- General Modal Functions ---
function openModal(modalId) {
    document.getElementById(modalId).style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Close the modals if the user clicks anywhere outside of it
window.onclick = function(event) {
    const apptModal = document.getElementById('appointmentModal');
    const transModal = document.getElementById('transactionDetailsModal');

    if (event.target == apptModal) {
        closeModal('appointmentModal');
    }
    if (event.target == transModal) {
        closeModal('transactionDetailsModal');
    }
}


/* ==========================================================
    APPOINTMENT DETAIL LOGIC (Relies on fetch_appointment_details.php)
========================================================== */
function viewAppointmentDetails(appointmentId) {
    fetch('fetch_appointment_details.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `appointment_id=${appointmentId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById('detail-id').textContent = data.details.appointment_id;
            document.getElementById('detail-service').textContent = servicesMap[data.details.service_id] || 'N/A';
            document.getElementById('detail-date').textContent = data.details.appointment_date;
            document.getElementById('detail-time').textContent = data.details.appointment_time;
            document.getElementById('detail-status').textContent = data.details.status.charAt(0).toUpperCase() + data.details.status.slice(1);
            document.getElementById('detail-comments').textContent = data.details.comments || 'No comments.';
            document.getElementById('detail-created').textContent = data.details.created_at;

            openModal('appointmentModal');
        } else {
            alert("Error fetching appointment details: " + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert("An unexpected error occurred while fetching details. Check console for error.");
    });
}


/* ==========================================================
    TRANSACTION DETAIL LOGIC
========================================================== */
function viewTransactionDetails(data) {
    // Populate Transaction Details (from payment_transactions table)
    document.getElementById('trans-detail-id').textContent = data.transaction_id;
    document.getElementById('trans-detail-date').textContent = new Date(data.transaction_date).toLocaleDateString();
    document.getElementById('trans-detail-amount-received').textContent = balanceFormatter.format(data.amount_received);
    document.getElementById('trans-detail-method').textContent = data.transaction_method.charAt(0).toUpperCase() + data.transaction_method.slice(1);
    
    // Populate Master Payment Details (from payments table)
    document.getElementById('trans-detail-payment-id').textContent = data.payment_id;

    const masterStatusElement = document.getElementById('trans-detail-master-status');
    masterStatusElement.textContent = data.master_payment_status.charAt(0).toUpperCase() + data.master_payment_status.slice(1);
    masterStatusElement.style.color = data.master_payment_status === 'paid' ? 'var(--success-green)' : (data.master_payment_status === 'pending' ? 'var(--accent-orange)' : 'var(--alert-red)');
    
    document.getElementById('trans-detail-total').textContent = balanceFormatter.format(data.total_amount);
    document.getElementById('trans-detail-discount-type').textContent = data.discount_type.charAt(0).toUpperCase() + data.discount_type.slice(1);
    document.getElementById('trans-detail-discount-amount').textContent = balanceFormatter.format(data.discount_amount);

    // Populate Appointment Context Details
    const appointmentAvailable = data.appointment_id && data.appointment_id !== 'N/A';
    document.getElementById('trans-appt-id').textContent = appointmentAvailable ? data.appointment_id : 'N/A';
    document.getElementById('trans-appt-service').textContent = data.service_name || 'N/A';
    document.getElementById('trans-appt-datetime').textContent = appointmentAvailable ? (data.appointment_date + ' at ' + data.appointment_time) : 'N/A';
    document.getElementById('trans-appt-comments').textContent = appointmentAvailable ? (data.appointment_comments || 'N/A') : 'N/A';

    // Handle Proof of Payment (Receipt)
    const proofContainer = document.getElementById('trans-detail-proof-image-container');
    const proofImage = document.getElementById('trans-detail-proof-image');
    const proofStatus = document.getElementById('trans-detail-proof-status');
    const downloadLinkContainer = document.getElementById('trans-detail-download-link-container');
    const downloadLink = document.getElementById('trans-detail-download-link');
    
    const proofImagePath = data.proof_image; 

    if (proofImagePath && proofImagePath.trim() !== '') {
        let fullPath = proofImagePath.replace(/\\/g, '/'); 
        if (!fullPath.startsWith('../uploads/') && !fullPath.startsWith('uploads/')) {
            fullPath = '../uploads/' + fullPath; 
        } else if (fullPath.startsWith('uploads/')) {
            fullPath = '../' + fullPath; 
        }
        
        proofImage.src = fullPath;
        downloadLink.href = fullPath;
        
        proofStatus.style.display = 'none';
        proofContainer.style.display = 'block';
        downloadLinkContainer.style.display = 'block';
    } else {
        proofStatus.textContent = 'No proof of payment/receipt was uploaded for this individual transaction.';
        proofStatus.style.display = 'block';
        proofContainer.style.display = 'none';
        downloadLinkContainer.style.display = 'none';
        proofImage.src = '';
    }

    openModal('transactionDetailsModal');
}

/* ==========================================================
    CANCELLATION LOGIC
========================================================== */
function confirmCancel(appointmentId) {
    if (confirm("Are you sure you want to cancel this appointment? This action cannot be undone.")) {
        fetch('cancel_appointment.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `appointment_id=${appointmentId}` 
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert("Appointment cancelled successfully!");
                window.location.reload();
            } else {
                alert("Error cancelling appointment: " + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert("An unexpected error occurred during cancellation.");
        });
    }
}


/* ==========================================================
    MOBILE MENU TOGGLE
========================================================== */
document.addEventListener('DOMContentLoaded', function() {
    // Initial tab setup: Show the 'records' tab and highlight the button
    const initialButton = document.querySelector('.tab-btn[data-tab="records"]');
    if (initialButton) {
        showTab('records', initialButton);
    }
    
    // Wire up all tab buttons using their data-tab attribute (Already done in HTML)
    document.querySelectorAll('.tab-btn').forEach(button => {
        button.addEventListener('click', function() {
            const tabId = this.getAttribute('data-tab');
            showTab(tabId, this);
        });
    });


    // --- MOBILE MENU TOGGLE ---
    const sidebar = document.getElementById('sidebar');
    const menuToggleOpen = document.getElementById('menu-toggle-open');
    const menuToggleClose = document.getElementById('menu-toggle-close');

    if (window.innerWidth < 992) {
        
        if (menuToggleOpen && menuToggleClose) {
            menuToggleClose.style.display = 'none';

            menuToggleOpen.addEventListener('click', function() {
                sidebar.classList.add('open');
                menuToggleClose.style.display = 'block';
                document.body.style.overflow = 'hidden'; 
            });
            
            menuToggleClose.addEventListener('click', function() {
                sidebar.classList.remove('open');
                menuToggleClose.style.display = 'none';
                document.body.style.overflow = ''; 
            });

            document.querySelectorAll('.sidebar a').forEach(link => {
                link.addEventListener('click', function() {
                    setTimeout(() => {
                        sidebar.classList.remove('open');
                        document.body.style.overflow = '';
                        menuToggleClose.style.display = 'none';
                    }, 300);
                });
            });
        }
    }
});
</script>