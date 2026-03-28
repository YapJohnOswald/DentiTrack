<?php
// payment_module.php
// Payments / Receipts admin module adjusted to show only clinic income (no expenses).
// FIX: Confirmed the join structure (payments -> patient -> users) and (payments -> services).
// FIX: Ensured the SELECT statements correctly alias joined columns to 'patient_username' and 'servicename_joined'.
// NOTE: If you see 'N/A (user empty)' or 'N/A (service missing)', the data in your linked tables is empty, not the join failing.

define('DEBUG', true);
if (DEBUG) { ini_set('display_errors','1'); error_reporting(E_ALL); }

session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: admin_login.php');
    exit();
}

// NOTE: Ensure your database connection file path is correct.
include '../config/db.php'; // expects $conn (mysqli)

function safe($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function refValues($arr){ foreach ($arr as $k => $v) $refs[$k] = &$arr[$k]; return $refs ?? []; }

// Helper to check column existence (Kept for robustness in other areas)
function column_exists($conn, $table, $column) {
    try {
        $dbNameResult = $conn->query("SELECT DATABASE()");
        if (!$dbNameResult) return false;
        $dbName = $dbNameResult->fetch_row()[0];
        $dbNameResult->free();
    } catch (Throwable $e) {
        return false;
    }
    
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE 
             TABLE_SCHEMA = ? AND 
             TABLE_NAME = ? AND 
             COLUMN_NAME = ? LIMIT 1";
    
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('sss', $dbName, $table, $column);
        $stmt->execute();
        $res = $stmt->get_result();
        $exists = ($res && $res->num_rows > 0);
        $stmt->close();
        return $exists;
    }
    return false; 
}

// Allowed statuses (adjust if your schema uses different values)
$allowed_statuses = ['paid','completed','pending','failed','refunded','cancelled'];

$error_messages = [];

// default filters & pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = isset($_GET['limit']) ? max(5, min(100, (int)$_GET['limit'])) : 15;
$offset = ($page - 1) * $limit;

$start_date = trim($_GET['start_date'] ?? '');
$end_date = trim($_GET['end_date'] ?? '');
$status = trim($_GET['status'] ?? '');
$q = trim($_GET['q'] ?? ''); // search term for invoice, name, or email

// --- COLUMN LIST ---
$requiredCols = [
    'payment_id', 'amount', 'total_amount', 'created_at', 'patient_id', 'service_id' // patient_id and service_id are critical for joins
];
$optionalCols = [
    'supplies_used', 'email', 'payment_method', 'status', 'details', 'invoice_no', 'currency', 
    'payment_date', 'discount_type', 'discount_amount'
];
// -------------------

$availableCols = [];

// Detect columns.
foreach ($requiredCols as $c) {
    if (column_exists($conn, 'payments', $c)) {
        $availableCols[] = $c;
    } else {
        // ERROR: If a critical column is missing, the module might be unusable.
        $error_messages[] = "Missing critical payments column: '{$c}'. Listing transactions may fail.";
    }
}
foreach ($optionalCols as $c) {
    if (column_exists($conn, 'payments', $c)) $availableCols[] = $c;
}


// --- SIMPLIFIED JOIN LOGIC & ALIASING FOR NAME/SERVICE ---
$joinClauses = "";
$joinSelects = [];
$isJoinedPayer = false;
$isJoinedService = false;

// 1. Join for Patient Username (Payer Name) - Schema: payments -> patient -> users
if (in_array('patient_id', $availableCols)) {
    // Join 1: payments to patient table (pat)
    $joinClauses .= " LEFT JOIN `patient` AS `pat` ON `payments`.`patient_id` = `pat`.`patient_id`";
    // Join 2: patient to users table (u) to get the username
    $joinClauses .= " LEFT JOIN `users` AS `u` ON `pat`.`user_id` = `u`.`user_id`";
    
    // Select the username, falling back to 'N/A (user empty)' if the username is NULL or empty
    $nameSelect = "COALESCE(NULLIF(`u`.`username`, ''), 'N/A (user empty)') AS `patient_username`";
    $joinSelects[] = $nameSelect;
    $isJoinedPayer = true;
} else {
    $error_messages[] = "Cannot join patient name: 'patient_id' column is missing in 'payments' table.";
}


// 2. Join for Service Name - Schema: payments -> services
if (in_array('service_id', $availableCols)) {
    // Join: payments to services table (s)
    $joinClauses .= " LEFT JOIN `services` AS `s` ON `payments`.`service_id` = `s`.`service_id`";
    
    // Select the service name, falling back to 'N/A (service missing)' if the service is missing or name is NULL
    $joinSelects[] = "IFNULL(`s`.`service_name`, 'N/A (service missing)') AS `servicename_joined`";
    $isJoinedService = true;
} else {
    $error_messages[] = "Cannot join service name: 'service_id' column is missing in 'payments' table.";
}


// Build FINAL SELECT list, combining direct columns and joined columns
$selectList = [];
foreach ($availableCols as $c) {
    switch ($c) {
        case 'payment_id':
            $selectList[] = "`payments`.`payment_id` AS `id`";
            break;
        case 'service_id':
            $selectList[] = "`payments`.`service_id` AS `serviceid`";
            break;
        case 'patient_id':
            $selectList[] = "`payments`.`patient_id` AS `patientid`";
            break;
        case 'service_name': // Ignore existing service_name/payer_name
        case 'payer_name':
             break;
        case 'supplies_used':
            $selectList[] = "`payments`.`supplies_used` AS `supply_used`";
            break;
        default:
            $selectList[] = "`payments`.`{$c}`";
            break;
    }
}

// Add the joined columns to the select list
$selectSQL = implode(', ', array_merge($selectList, $joinSelects));


// Determine which date column to use for filtering
$dateCol = in_array('created_at', $availableCols, true) ? 'created_at' : (in_array('payment_date', $availableCols, true) ? 'payment_date' : null);

// Build dynamic WHERE with prepared statement
$where_clauses = [];
$params = [];
$types = '';

if ($dateCol) {
    if ($start_date !== '') {
        $where_clauses[] = "DATE(`payments`.`$dateCol`) >= ?";
        $types .= 's'; $params[] = $start_date;
    }
    if ($end_date !== '') {
        $where_clauses[] = "DATE(`payments`.`$dateCol`) <= ?";
        $types .= 's'; $params[] = $end_date;
    }
} else {
    if ($start_date || $end_date) $error_messages[] = "Date filters ignored: No suitable date column present.";
}

if ($status !== '' && in_array(strtolower($status), $allowed_statuses, true) && in_array('status', $availableCols, true)) {
    $where_clauses[] = "LOWER(`payments`.`status`) = ?";
    $types .= 's'; $params[] = strtolower($status);
} elseif ($status !== '' && !in_array('status', $availableCols, true)) {
    $error_messages[] = "Status filter ignored: 'status' column not present.";
}

if ($q !== '') {
    $qClauses = [];
    if (in_array('invoice_no', $availableCols, true)) { $qClauses[] = "`payments`.`invoice_no` LIKE ?"; $types .= 's'; $params[] = "%$q%"; }
    
    // Search on joined patient_username (u)
    if ($isJoinedPayer) { 
        $qClauses[] = "`u`.`username` LIKE ?"; // Search on the joined username
        $types .= 's'; 
        $params[] = "%$q%";
        
        // Also search on patient_id for direct matches
        if (is_numeric($q) && in_array('patient_id', $availableCols)) {
             $qClauses[] = "`payments`.`patient_id` = ?"; $types .= 'i'; $params[] = (int)$q;
        }
    }
    
    if (in_array('email', $availableCols, true)) { $qClauses[] = "`payments`.`email` LIKE ?"; $types .= 's'; $params[] = "%$q%"; }
    
    if ($qClauses) $where_clauses[] = '(' . implode(' OR ', $qClauses) . ')';
    else $error_messages[] = "Search ignored: no invoice/payer/email columns present to search on.";
}

$where = '';
if (count($where_clauses)) $where = 'WHERE ' . implode(' AND ', $where_clauses);

// total count for pagination
$total = 0;
$total_sql = "SELECT COUNT(*) AS cnt FROM `payments` $joinClauses $where"; 
if ($stmt = $conn->prepare($total_sql)) {
    if ($types !== '') {
        $count_params = $params;
        $count_types = $types;
        $bind_arr = array_merge([$count_types], $count_params);
        call_user_func_array([$stmt, 'bind_param'], refValues($bind_arr));
    }
    if (!$stmt->execute()) {
        $error_messages[] = 'Failed to execute count query: ' . $stmt->error;
    } else {
        $res = $stmt->get_result();
        if ($res) {
            $row = $res->fetch_assoc();
            $total = (int)($row['cnt'] ?? 0);
        }
    }
    $stmt->close();
} else {
    $error_messages[] = 'Failed to prepare count query: ' . $conn->error;
}

// fetch transactions using the dynamic select list and joins
$transactions = [];
$orderBy = $dateCol ? "`payments`.`$dateCol` DESC" : '`payments`.`payment_id` DESC';
$list_sql = "SELECT $selectSQL FROM `payments` $joinClauses $where ORDER BY $orderBy LIMIT ? OFFSET ?";

try {
    if ($stmt = $conn->prepare($list_sql)) {
        // bind dynamic params + limit + offset
        $bind_params = [];
        $bind_types = $types . 'ii';
        $bind_params[] = $bind_types;
        foreach ($params as $p) $bind_params[] = $p;
        $bind_params[] = $limit;
        $bind_params[] = $offset;
        call_user_func_array([$stmt, 'bind_param'], refValues($bind_params));

        if (!$stmt->execute()) {
            $error_messages[] = 'Failed to execute list query: ' . $stmt->error . ' SQL: ' . $list_sql;
        } else {
            $res = $stmt->get_result();
            if ($res) {
                while ($r = $res->fetch_assoc()) $transactions[] = $r;
            }
        }
        $stmt->close();
    } else {
        $error_messages[] = 'Failed to prepare list query: ' . $conn->error . ' SQL: ' . $list_sql;
    }
} catch (Throwable $e) {
    $error_messages[] = 'Server error while fetching transactions: ' . $e->getMessage();
}

// pagination helpers
$total_pages = $limit > 0 ? ceil($total / $limit) : 1;

// build query strings for links
function build_qs($overrides = []) {
    $base = $_GET;
    // ensure page is not kept if cleared
    if (isset($overrides['page']) && $overrides['page'] === null) unset($base['page']);
    // Remove the 'action' parameter (used for form submission context)
    unset($base['action']); 
    foreach ($overrides as $k=>$v) $base[$k] = $v;
    return http_build_query($base);
}

// financial summary using the detected columns (income only)
$total_revenue = 0.00;
try {
    $revenueCol = in_array('total_amount', $availableCols, true) ? 'total_amount' : (in_array('amount', $availableCols, true) ? 'amount' : null);
    
    if ($revenueCol) {
        $Res = $conn->query("SELECT IFNULL(SUM($revenueCol),0) AS total_revenue FROM payments");
        if ($Res) { $row = $Res->fetch_assoc(); $total_revenue = (float)($row['total_revenue'] ?? 0); $Res->free(); }
    }
} catch (Throwable $e) {
    $error_messages[] = 'Revenue query failed: ' . $e->getMessage();
}

$admin_username = $_SESSION['username'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Payments — DentiTrack</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous">
<style>
/* --- CSS STYLES FOR SIDEBAR AND MOBILE HEADER --- */
:root {
    --primary-blue: #007bff;
    --secondary-blue: #0056b3;
    --text-dark: #343a40;
    --text-light: #6c757d;
    --bg-page: #eef2f8; 
    --widget-bg: #ffffff;
    --alert-red: #dc3545;
    --success-green: #28a745;
    --accent-orange: #ffc107;
    --radius: 12px;
    --sidebar-width: 250px;
    --mobile-header-height: 60px;
}
* { box-sizing: border-box; }
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

/* --- Sidebar Styling --- */
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
    -ms-overflow-style: none;
    scrollbar-width: none;
}
.sidebar-nav::-webkit-scrollbar { display: none; }

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
    transition: all 0.3s ease;
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
.sidebar a.active i { color: white; }
.sidebar a:hover i { color: var(--primary-blue); }

/* Specific style for Logout button (MOBILE VIEW ONLY) */
.sidebar a[href="logout.php"].logout {
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
}
.sidebar a[href="logout.php"].logout:hover {
    background-color: #fce8e8;
    color: var(--alert-red);
}
.sidebar a[href="logout.php"].logout:hover i {
    color: var(--alert-red);
}

/* --- Main Content Positioning (Crucial for layout) --- */
main.main-content {
    flex: 1;
    margin-left: var(--sidebar-width); /* Offset for the fixed sidebar */
    padding: 40px 60px;
    background: var(--bg-page); 
    overflow-y: auto;
    box-sizing: border-box;
    max-width: 100%;
    position: relative; /* Needed for desktop logout positioning */
}

/* --- LOGOUT ALIGNMENT OUTSIDE SIDEBAR (DESKTOP) --- */
.logout-desktop-container {
    position: absolute;
    top: 30px; /* Aligned with header h1 top margin */
    right: 40px;
    z-index: 990;
}
.logout-desktop-btn {
    padding: 8px 15px;
    border-radius: 6px;
    background: var(--alert-red);
    color: white;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: background 0.3s;
    font-size: 14px;
}
.logout-desktop-btn:hover {
    background: #c82333;
}


/* Header and Page Title */
.header {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    margin-bottom: 25px;
    border-bottom: 2px solid #e0e0e0;
    padding-bottom: 10px;
}
.header-left h1 { 
    font-size: 1.8rem; 
    color: var(--secondary-blue); 
    display: flex; 
    align-items: center; 
    gap: 10px; 
    font-weight: 700;
    margin: 0;
}
.small-muted { color: var(--text-light); font-size: 13px; }

/* Summary Cards */
.summary-row {
    display: flex;
    gap: 20px;
    margin-bottom: 25px;
    flex-wrap: wrap;
}
.summary-card {
    background: var(--widget-bg);
    padding: 18px;
    border-radius: var(--radius);
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    border-left: 5px solid var(--primary-blue);
    flex: 1;
    min-width: 200px;
}
.summary-card h3 {
    margin: 0 0 5px 0;
    font-size: 14px;
    color: var(--text-light);
    font-weight: 600;
}
.summary-card .value {
    font-size: 24px;
    font-weight: 800;
    color: var(--primary-blue);
}

/* Filters */
.filters-card {
    background: var(--widget-bg);
    padding: 20px;
    border-radius: var(--radius);
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.05);
    display: flex;
    gap: 15px;
    align-items: flex-end;
    flex-wrap: wrap;
    margin-bottom: 25px;
}
.filters-card label {
    font-weight: 600;
    color: var(--text-dark);
    font-size: 0.9rem;
}
.filters-card input, .filters-card select {
    padding: 8px 12px;
    border-radius: 6px;
    border: 1px solid #ced4da;
    background: #f9fcff;
    box-sizing: border-box;
}
.filters-card .btn {
    background: var(--primary-blue);
    color: #fff;
    padding: 8px 15px;
    border-radius: 6px;
    border: 0;
    cursor: pointer;
    transition: background 0.2s;
    font-weight: 600;
}
.filters-card .btn:hover {
    background: var(--secondary-blue);
}
.filters-card input[type="search"] {
    flex-grow: 1;
}


/* Table */
.table-card {
    background: var(--widget-bg);
    padding: 18px;
    border-radius: var(--radius);
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    overflow-x: auto; /* Enable horizontal scroll for table */
}
.table {
    width: 100%;
    min-width: 900px; /* Ensure table is wide enough for columns */
    border-collapse: collapse;
}
.table th, .table td {
    padding: 12px 15px;
    border-bottom: 1px solid #f1f6fb;
    font-size: 14px;
    text-align: left;
}
.table th {
    color: white;
    font-weight: 700;
    background: var(--primary-blue); 
}
.table tbody tr:hover { background: #f5f8fc; }

.badge {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 999px;
    font-weight: 700;
    font-size: 13px;
}
.badge.paid, .badge.completed { background: rgba(34, 197, 94, 0.12); color: var(--success-green); }
.badge.pending { background: rgba(250, 204, 21, 0.12); color: var(--accent-orange); }
.badge.failed, .badge.refunded, .badge.cancelled { background: rgba(239, 68, 68, 0.08); color: var(--alert-red); }

/* --- TABLE ACTION BUTTON FIXES --- */
.actions {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
}
.actions .btn.view-receipt {
    background: var(--accent-orange); /* Use accent color for visibility */
    color: var(--text-dark); 
    padding: 6px 12px;
    font-weight: 600;
    border-radius: 6px;
    transition: background 0.2s, color 0.2s;
    border: none;
}
.actions .btn.view-receipt:hover {
    background: #e0a800; /* Darker accent on hover */
    color: white;
}

/* Pagination */
.pagination {
    display: flex;
    gap: 8px;
    align-items: center;
    margin-top: 12px;
}
.page-link {
    padding: 8px 12px;
    background: #fff;
    border-radius: 8px;
    border: 1px solid #eef6ff;
    color: var(--primary-blue);
    text-decoration: none;
    transition: background 0.2s;
    font-weight: 500;
}
.page-link:hover {
    background: #f0f8ff;
}

/* Receipt Modal Overrides */
.receipt-modal {
    position: fixed;
    left: 50%;
    top: 50%;
    transform: translate(-50%, -50%);
    width: 760px;
    max-width: 96%;
    background: var(--widget-bg);
    border-radius: var(--radius);
    box-shadow: 0 30px 80px rgba(2, 6, 23, 0.2);
    z-index: 9999;
    display: none;
    padding: 25px;
}
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}
.close-btn {
    background: #f3f4f6;
    border: 0;
    padding: 6px 10px;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    transition: background 0.2s;
}
.close-btn:hover {
    background: #e5e7eb;
}

/* --- RECEIPT MODAL BUTTONS FIXES --- */
/* Print Button */
.btn-print {
    background: var(--primary-blue);
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    transition: background 0.2s;
}
.btn-print:hover {
    background: var(--secondary-blue);
}

/* Proceed Button */
.btn-proceed {
    background: var(--success-green);
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 8px;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    font-weight: 600;
    transition: background 0.2s;
}
.btn-proceed:hover {
    background: #1e7e34;
}

.error-banner {
    background: var(--alert-red);
    color: white;
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 12px;
}
.error-banner strong { font-weight: 700; }

@media (max-width: 992px) {
    .mobile-header { display: flex; }
    main.main-content { margin-left: 0; padding: 20px; padding-top: 80px; }
    
    .sidebar { width: 80%; max-width: 300px; transform: translateX(-100%); z-index: 1050; height: 100vh; }
    .sidebar.open { transform: translateX(0); box-shadow: 5px 0 15px rgba(0,0,0,0.2); }
    .sidebar-header { justify-content: space-between; display: flex; align-items: center; }
    .sidebar-header button { display: block !important; }
    .sidebar-nav { padding: 0; overflow-y: auto !important; }
    .sidebar a { margin: 0; border-radius: 0; padding: 15px 30px; border-bottom: 1px solid #f0f0f0; }
    .sidebar .logout { padding: 15px 30px; margin: 0; margin-top: 10px; border-radius: 0; }
    
    .summary-row { flex-direction: column; gap: 15px; }
    .filters-card { flex-direction: column; align-items: stretch; gap: 10px; }
    .filters-card input, .filters-card select, .filters-card .btn { width: 100%; }
    .table-card { padding: 10px; }
    .table { min-width: 600px; }

    /* Hide desktop logout on mobile */
    .logout-desktop-container { display: none; }
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
        <a href="admin_dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
        <a href="manage_accounts.php"><i class="fas fa-users-cog"></i> Manage Accounts</a>
        <a href="clinic_services_admin.php"><i class="fas fa-tools"></i> Clinic Services</a>
        <a href="generate_reports.php"><i class="fas fa-chart-line"></i> Generate Reports</a>
        <a href="payment_module.php" class="active"><i class="fas fa-money-check-dollar"></i> Payment Module</a>
        <a href="clinic_schedule_admin.php"><i class="fas fa-calendar-check"></i> Clinic Schedule</a>
        <a href="admin_settings.php"><i class="fas fa-gear"></i> System Settings</a>
    </div>
    <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a> 
</nav>



<main class="main-content" role="main">
    <div class="header">
        <div class="header-left">
            <h1><i class="fas fa-money-check-dollar" style="color:var(--primary-blue)"></i> Payments & Receipts</h1>
            <div class="small-muted">Welcome, <?= safe($admin_username) ?> — Manage transactions and clinic income</div>
        </div>

    </div>

    <?php if (!empty($error_messages)): ?>
        <div class="error-banner" role="status" aria-live="polite">
            <strong>Warning:</strong>
            <ul style="margin:8px 0 0 18px;">
                <?php foreach ($error_messages as $em): ?><li><?= safe($em) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="summary-row" role="region" aria-label="Financial summary">
        <div class="summary-card" aria-hidden="false">
            <h3>Clinic Income</h3>
            <div class="value"><?= '₱' . number_format($total_revenue, 2) ?></div>
            <div class="small-muted">Total income from payments</div>
        </div>
    </div>

    <form class="filters-card" role="search" aria-label="Filters" id="filtersForm" method="get" action="payment_module.php">
        
        <div style="display:flex;gap:10px;align-items:flex-end;">
            <label>Start Date:
                <input type="date" name="start_date" value="<?= safe($start_date) ?>" aria-label="Start date">
            </label>
            <label>End Date:
                <input type="date" name="end_date" value="<?= safe($end_date) ?>" aria-label="End date">
            </label>
            <label>Status:
                <select name="status" aria-label="Payment status">
                    <option value="">All statuses</option>
                    <?php foreach ($allowed_statuses as $s): ?>
                        <option value="<?= safe($s) ?>" <?= strtolower($status) === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        
        <input type="search" name="q" placeholder="Search invoice, name, email, patient ID..." value="<?= safe($q) ?>" aria-label="Search">
        
        <div style="margin-left:auto;display:flex;gap:8px">
            <button type="submit" class="btn">Apply Filter</button>
        </div>
    </form>

    <div class="table-card" role="table" aria-label="Payments table">
        <table class="table" role="presentation">
            <thead>
                <tr>
                    <th>Invoice / ID / Payer</th>
                    <th>Service</th>
                    <th>Supply used</th>
                    <th style="min-width:120px">Amount</th>
                    <th>Discount</th>
                    <th>Total</th>
                    <th>Date</th>
                    <th style="text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($transactions) === 0): ?>
                    <tr><td colspan="8" class="small-muted">No transactions found.</td></tr>
                <?php else: ?>
                    <?php foreach ($transactions as $t):
                        $statusKey = isset($t['status']) ? strtolower($t['status']) : 'unknown';
                        $badgeClass = 'badge';
                        if ($statusKey === 'paid' || $statusKey === 'completed') $badgeClass .= ' paid';
                        elseif ($statusKey === 'pending') $badgeClass .= ' pending';
                        elseif ($statusKey === 'failed' || $statusKey === 'refunded' || $statusKey === 'cancelled') $badgeClass .= ' failed';
                        
                        // Use detected date column for display
                        $displayDate = $t['created_at'] ?? $t['payment_date'] ?? ''; 
                        ?>
                        <tr>
                            <td>
                                <strong><?= safe($t['invoice_no'] ?? $t['id']) ?></strong><br>
                                <div class="small-muted">Payer: <?= safe($t['patient_username'] ?? '—') ?></div>
                                <?php if (isset($t['patientid'])): ?>
                                    <div class="small-muted" style="margin-top: 2px;">(P-ID: <?= safe($t['patientid']) ?>)</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><strong><?= safe($t['servicename_joined'] ?? '—') ?></strong></div>
                                <div class="small-muted">Service ID: <?= safe($t['serviceid'] ?? '—') ?></div>
                            </td>
                            <td><?= safe($t['supply_used'] ?? '—') ?></td>
                            <td><strong style="color:var(--primary-blue)"><?= '₱' . number_format((float)($t['amount'] ?? 0), 2) ?> <?= safe($t['currency'] ?? '') ?></strong></td>
                            <td>
                                <?= isset($t['discount_type']) ? safe($t['discount_type']) : '—' ?>
                                <?php if (isset($t['discount_amount'])): ?>
                                    <div class="small-muted"><?= '₱' . number_format((float)$t['discount_amount'], 2) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><strong><?= '₱' . number_format((float)($t['total_amount'] ?? ($t['amount'] ?? 0)), 2) ?></strong></td>
                            <td><?= safe($displayDate) ?></td>
                            <td class="actions" style="text-align:right">
                                <button class="btn view-receipt" data-id="<?= (int)$t['id'] ?>" aria-label="View receipt <?= safe($t['invoice_no'] ?? $t['id']) ?>">View</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px">
            <div class="small-muted">Showing <?= ($total === 0) ? 0 : ($offset + 1) ?> - <?= min($total, $offset + count($transactions)) ?> of <?= $total ?> transactions</div>
            <div class="pagination" role="navigation" aria-label="Pagination">
                <?php if ($page > 1): ?>
                    <a class="page-link" href="?<?= build_qs(['page' => $page-1]) ?>">&laquo; Prev</a>
                <?php endif; ?>
                <?php if ($page < $total_pages): ?>
                    <a class="page-link" href="?<?= build_qs(['page' => $page+1]) ?>">Next &raquo;</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<div id="receiptModal" class="receipt-modal" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="modal-header">
        <div><strong>Receipt Details</strong></div>
        <div><button id="closeModal" class="close-btn" aria-label="Close receipt">Close</button></div>
    </div>
    <div id="receiptBody" class="modal-body">
        <div class="small-muted">Loading...</div>
    </div>
</div>

<script>
// --- SIDEBAR/MOBILE MENU JAVASCRIPT LOGIC ---
document.addEventListener('DOMContentLoaded', function() {
    // Only apply mobile logic if screen width is less than 992px on load
    if (window.innerWidth < 992) {
        const sidebar = document.getElementById('sidebar');
        const menuToggleOpen = document.getElementById('menu-toggle-open');
        // Close button inside the sidebar header
        const closeBtn = document.querySelector('.sidebar-header button'); 

        if (menuToggleOpen && sidebar && closeBtn) {
            closeBtn.style.display = 'none';
            
            // OPEN action
            menuToggleOpen.addEventListener('click', function() {
                sidebar.classList.add('open');
                closeBtn.style.display = 'block';
                document.body.style.overflow = 'hidden'; 
            });
            
            // CLOSE action
            closeBtn.addEventListener('click', function() {
                sidebar.classList.remove('open');
                closeBtn.style.display = 'none';
                document.body.style.overflow = ''; 
            });
            
            // Auto-close menu when a link is clicked
            document.querySelectorAll('.sidebar a').forEach(link => {
                link.addEventListener('click', function() {
                    setTimeout(() => {
                        sidebar.classList.remove('open');
                        closeBtn.style.display = 'none';
                        document.body.style.overflow = '';
                    }, 300);
                });
            });
        }
    }
});


(function(){
    // Helper function for XSS protection in JavaScript
    function safe(v) {
        if (v === null || v === undefined) return '';
        // Basic HTML escaping
        return String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
    
    // Function to format currency (Using PHP currency)
    function formatPeso(amount) {
        // Formats as ₱25,000.00
        return '₱' + parseFloat(amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    // Function to parse the complex JSON string in supplies_used
    function parseSupplies(jsonString) {
        try {
            if (typeof jsonString === 'string') {
                 // Check for the specific double array structure: [[{"id":...,"name":...,"qty":...}]]
                 if (jsonString.startsWith('[[') && jsonString.endsWith(']]')) {
                     const innerJson = jsonString.slice(1, -1);
                     const parsed = JSON.parse(innerJson);
                     return `Used: ${parsed.name} (Qty: ${parsed.qty})`;
                 }
                 
                 // Check for a standard array structure: [{"id":...,"name":...,"qty":...}]
                 if (jsonString.startsWith('[') && jsonString.endsWith(']')) {
                     const parsedArray = JSON.parse(jsonString);
                     if (Array.isArray(parsedArray) && parsedArray.length > 0) {
                         return parsedArray.map(item => `${item.name} (Qty: ${item.qty})`).join(', ');
                     }
                 }
            }
        } catch (e) {
            // Parsing failed, return raw string or default
        }
        return safe(jsonString) || 'None';
    }

    async function loadReceipt(id){
        const modal = document.getElementById('receiptModal');
        const body = document.getElementById('receiptBody');
        modal.style.display = 'block';
        modal.setAttribute('aria-hidden', 'false');
        body.innerHTML = '<div class="small-muted" style="text-align:center;">Loading receipt...</div>';
        
        try {
            // NOTE: This call relies on payments_api.php having the CRITICAL JOIN LOGIC
            const res = await fetch('payments_api.php', {
                method: 'POST',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify({ action: 'detail', id: id })
            });
            const json = await res.json();
            
            if (!res.ok || json.error) {
                body.innerHTML = '<div class="error-banner" style="margin:0;">Error: ' + safe(json.error || 'Unable to fetch receipt details.') + '</div>';
                return;
            }

            const p = json.data || {};
            
            // Patient Name is now expected to be 'patient_username' from the joined field.
            const patientName = safe(p.patient_username || p.username || 'N/A'); 
            
            // serviceName is expected to be pulled from the joined field 'servicename_joined'
            const serviceName = safe(p.servicename_joined || p.service_name || 'N/A'); 
            const createdAt = safe(p.payment_date || p.created_at || 'N/A');
            const invoiceNo = safe(p.invoice_no || p.payment_id);
            const originalAmount = parseFloat(p.amount || 0); 
            const totalAmountDue = parseFloat(p.total_amount || p.amount || 0); 
            const discountAmount = parseFloat(p.discount_amount || 0);
            const discountType = safe(p.discount_type || 'None');
            const suppliesUsed = parseSupplies(p.supplies_used);
            const cashReceived = parseFloat(p.cash_received || totalAmountDue || 0); 
            const paymentMethod = safe(p.payment_method || 'N/A');
            const paymentStatus = safe(p.status || 'N/A');
            const paymentOption = safe(p.payment_option || 'Full Payment');

            let html = `
                <style>
                    /* Styles for the receipt modal */
                    .receipt-content .row { 
                        display: flex; 
                        justify-content: space-between; 
                        margin: 4px 0; 
                        border-bottom: 1px dotted #ccc; 
                        padding-bottom: 4px;
                        font-size: 14px;
                    }
                    .receipt-content .row strong { font-weight: 600; }
                    .receipt-content table{
                        width:100%;
                        border-collapse:collapse;
                        margin-top: 15px;
                        margin-bottom: 15px;
                    }
                    .receipt-content table th, .receipt-content table td{
                        padding: 10px 8px;
                        text-align: left;
                        font-size: 14px;
                        border-bottom: 1px solid #eee;
                    }
                    .receipt-content table th{
                        background: #f0f8ff;
                        font-weight: 700;
                        color: #0077b6;
                    }
                    .receipt-content table td:last-child, .receipt-content table th:last-child {
                        text-align: right; 
                    }
                    .summary-label {
                        text-align: right;
                        padding-right: 15px;
                        font-size: 14px;
                    }
                    .summary-value {
                        text-align: right;
                        font-weight: bold;
                        font-size: 14px;
                    }
                    .total-bill-line, .cash-received-line {
                        font-size: 1.1rem;
                        font-weight: 800;
                        display: flex;
                        justify-content: space-between;
                        padding: 10px 0;
                        margin-top: 5px;
                    }
                    .total-bill-line {
                        border-top: 2px solid #00c853; 
                        color: #004080;
                    }
                    .cash-received-line {
                        color: #008000; 
                        border-top: 1px solid #008000;
                    }
                    .button-row {
                        display: flex;
                        justify-content: center;
                        gap: 15px;
                        margin-top: 25px;
                    }
                    .btn-print {
                        background: var(--primary-blue);
                        color: white;
                        border: none;
                        padding: 10px 20px;
                        border-radius: 8px;
                        cursor: pointer;
                        font-weight: 600;
                        transition: background 0.2s;
                    }
                    .btn-print:hover {
                        background: var(--secondary-blue);
                    }
                    .btn-proceed {
                        background: var(--success-green);
                        color: white;
                        border: none;
                        padding: 10px 20px;
                        border-radius: 8px;
                        cursor: pointer;
                        text-decoration: none;
                        display: inline-block;
                        font-weight: 600;
                        transition: background 0.2s;
                    }
                    .btn-proceed:hover {
                        background: #1e7e34;
                    }
                    /* Print/Media styles for the receipt modal */
                    @media print {
                        .no-print { display: none !important; }
                        body { background: white !important; }
                        .receipt-modal { transform: none; left: 0; top: 0; position: relative; width: 100%; box-shadow: none; padding: 0; margin: 0; }
                    }
                </style>
                <div class="receipt-content">
                    <h2 style="text-align:center;margin-bottom:25px;color:#004080;">
                        <i class="fas fa-receipt"></i> ${safe(p.receipt_title || 'PAYMENT RECEIPT')}
                    </h2>

                    <div class="row"><strong>Receipt ID:</strong> <span style="font-weight: bold;">${invoiceNo}</span></div>
                    <div class="row"><strong>Patient Name:</strong> <span>${patientName}</span></div>
                    <div class="row"><strong>Payment Date:</strong> <span>${createdAt}</span></div>
                    <div class="row"><strong>Payment Method:</strong> <span>${paymentMethod}</span></div>
                    <div class="row"><strong>Payment Option:</strong> <span>${paymentOption}</span></div>
                    <div class="row" style="border-bottom: none; padding-bottom: 0;"><strong>Payment Status:</strong> <span>${paymentStatus}</span></div>

                    
                    <table>
                        <thead>
                            <tr>
                                <th>Service/Item</th>
                                <th>Unit Price</th>
                                <th>Quantity</th>
                                <th>Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>${serviceName}</td>
                                <td>${formatPeso(originalAmount)}</td>
                                <td>1</td>
                                <td>${formatPeso(originalAmount)}</td>
                            </tr>
                            <tr>
                                <td colspan="4" style="font-size: 12px; color: #6b7280; padding-top: 5px; border-bottom: none;">
                                    * Supplies Used: ${suppliesUsed}
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div style="text-align: left; margin-top: -10px; margin-bottom: 10px; font-weight: bold; color: #6b7280; font-size: 14px;">
                        --- FINANCIAL SUMMARY ---
                    </div>
                    
                    <div class="row" style="border-bottom: 1px dotted #ccc;">
                        <span class="summary-label">Total Service Cost:</span>
                        <span class="summary-value">${formatPeso(originalAmount)}</span>
                    </div>

                    <div class="row" style="border-bottom: 1px dotted #ccc;">
                        <span class="summary-label">Discount (${discountType}):</span>
                        <span class="summary-value">-${formatPeso(discountAmount)}</span>
                    </div>

                    <div class="total-bill-line">
                        <span>TOTAL AMOUNT DUE:</span>
                        <span>${formatPeso(totalAmountDue)}</span>
                    </div>

                    <div class="cash-received-line">
                        <span>CASH RECEIVED THIS TRANSACTION:</span>
                        <span>${formatPeso(cashReceived)}</span>
                    </div>

                    <div class="button-row no-print">
                        <button id="printReceipt" class="btn-print">
                            <i class="fas fa-print"></i> Print Receipt
                        </button>
                        <a href="payment_module.php" class="btn-proceed" role="button">
                            <i class="fas fa-arrow-right"></i> Proceed to Payments
                        </a>
                    </div>
                </div>`;
            
            body.innerHTML = html;

            // Print functionality
            document.getElementById('printReceipt').addEventListener('click', function(){
                const printWindow = window.open('', '_blank');
                const printContent = document.querySelector('.receipt-content').innerHTML;
                
                printWindow.document.write(`
                    <html>
                    <head>
                        <title>Receipt ${invoiceNo}</title>
                        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
                        <style>
                            /* Minimal print styles matching the visual structure */
                            body{font-family:'Inter',sans-serif;color:#003366;padding:20px;margin:0 auto;max-width:600px;}
                            .receipt-content h2{text-align:center;margin-bottom:20px;color:#004080;}
                            .receipt-content .row{display:flex;justify-content:space-between;margin:4px 0;border-bottom:1px dotted #ccc;padding-bottom:4px;font-size:14px;}
                            .receipt-content .row strong:first-child{font-weight:600;}
                            .receipt-content table{width:100%;border-collapse:collapse;margin-top:15px;margin-bottom:15px;font-size:12px;}
                            .receipt-content table th, .receipt-content table td{padding:8px;text-align:left;border-bottom: 1px solid #eee;}
                            .receipt-content table th{background:#eaf3ff;}
                            .receipt-content table td:last-child, .receipt-content table th:last-child { text-align: right; }
                            .summary-label, .summary-value { font-size: 14px; }
                            .summary-label { text-align: right; padding-right: 15px; }
                            .total-bill-line, .cash-received-line { font-size: 1.1rem !important; font-weight: bold !important; display: flex; justify-content: space-between; padding: 10px 0; margin-top: 5px; }
                            .total-bill-line { border-top: 2px solid #00c853 !important; color: #004080 !important; }
                            .cash-received-line { color: #008000 !important; border-top: 1px solid #008000 !important; }
                            @media print { .no-print { display: none; } }
                        </style>
                    </head>
                    <body>${printContent}</body>
                    </html>
                `);
                printWindow.document.close();
                printWindow.print();
                printWindow.close();
            });

            document.getElementById('closeModal').addEventListener('click', function() {
                document.getElementById('receiptModal').style.display = 'none';
                document.getElementById('receiptModal').setAttribute('aria-hidden', 'true');
            });
            window.addEventListener('click', function(event) {
                if (event.target === document.getElementById('receiptModal')) {
                    document.getElementById('receiptModal').style.display = 'none';
                    document.getElementById('receiptModal').setAttribute('aria-hidden', 'true');
                }
            });
            
        } catch (err) {
            body.innerHTML = '<div class="error-banner" style="margin:0;">Network or server error while fetching receipt.</div>';
            console.error(err);
        }
    }

    document.querySelectorAll('.view-receipt').forEach(btn => {
        btn.addEventListener('click', function(){
            const id = this.getAttribute('data-id');
            if (id) loadReceipt(id);
        });
    });

    document.getElementById('closeModal').addEventListener('click', function() {
        document.getElementById('receiptModal').style.display = 'none';
        document.getElementById('receiptModal').setAttribute('aria-hidden', 'true');
    });

    window.addEventListener('click', function(event) {
        if (event.target === document.getElementById('receiptModal')) {
            document.getElementById('receiptModal').style.display = 'none';
            document.getElementById('receiptModal').setAttribute('aria-hidden', 'true');
        }
    });
})();
</script>
</body>
</html>