<?php
session_start();
include '../config/db_pdo.php';
include '../config/db_conn.php';

// ✅ Set timezone to Philippines
date_default_timezone_set('Asia/Manila');

// Ensure PDO throws exceptions
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Check secretary role
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'secretary') {
    header('Location: ../public/login.php');
    exit();
}

$error = '';
// --- 1. Filter Variables ---
$search = $_GET['search'] ?? '';
$service_filter = $_GET['service_filter'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// --- 2. Fetch all services for the dropdown filter ---
$services_list = [];
try {
    $stmt_services = $conn->query("SELECT service_id, service_name FROM services ORDER BY service_name ASC");
    $services_list = $stmt_services->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = "Failed to fetch services list: " . $e->getMessage();
}


// --- 3. Build Dynamic SQL Query ---
try {
    $sql = "
        SELECT p.payment_id, CONCAT(pt.first_name, ' ', pt.last_name) AS patient_name,
               s.service_name, p.amount, p.discount_type, p.discount_amount,
               p.total_amount, p.payment_method, p.supplies_used, p.created_at, p.service_id
        FROM payments p
        INNER JOIN patient pt ON p.patient_id = pt.patient_id
        LEFT JOIN services s ON p.service_id = s.service_id
    ";
    
    $where_clauses = [];
    $params = [];
    
    // a) Text Search Filter (Patient Name or Service Name)
    if (!empty($search)) {
        $where_clauses[] = "(pt.first_name LIKE :search OR pt.last_name LIKE :search OR s.service_name LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    // b) Service Filter
    if (!empty($service_filter) && $service_filter != 'all') {
        $where_clauses[] = "p.service_id = :service_id";
        $params[':service_id'] = $service_filter;
    }

    // c) Date Range Filter
    if (!empty($start_date)) {
        // Look for records created ON or AFTER the start date (at the beginning of the day)
        $where_clauses[] = "DATE(p.created_at) >= :start_date";
        $params[':start_date'] = $start_date;
    }
    if (!empty($end_date)) {
        // Look for records created ON or BEFORE the end date (at the end of the day)
        $where_clauses[] = "DATE(p.created_at) <= :end_date";
        $params[':end_date'] = $end_date;
    }

    // Combine all WHERE clauses
    if (!empty($where_clauses)) {
        $sql .= " WHERE " . implode(" AND ", $where_clauses);
    }
    
    $sql .= " ORDER BY p.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $error = "Failed to fetch payments: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Payments Log - DentiTrack</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
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
    --accent-orange: #ffc107;
}

/* Base Styles */
*,*::before,*::after{box-sizing:border-box;}
body{
    margin:0;padding:0;height:100%;
    font-family:'Inter', sans-serif;
    background:var(--bg-page);color:var(--text-dark);
    display:flex;min-height:100vh;overflow-x:hidden;
    scroll-behavior:smooth;
}
a { text-decoration: none; color: inherit; }
button { cursor: pointer; } 

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
}
.sidebar-header {
    padding: 25px;
    text-align: center;
    margin-bottom: 20px;
    font-size: 22px;
    font-weight: 700;
    color: var(--primary-blue);
    border-bottom: 1px solid #e9ecef;
    display: flex; justify-content: center; align-items: center; gap: 10px;
}
.sidebar-nav {
    flex-grow: 1; 
    padding: 0 15px;
    overflow-y: auto; /* Re-added to allow scrolling of nav links */
    
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
    display: flex; align-items: center; gap: 12px;
    padding: 12px 15px; margin: 8px 0;
    color: var(--text-dark); font-weight: 500;
    text-decoration: none;
    border-radius: 8px;
    transition: background-color 0.3s ease, color 0.3s ease;
}
.sidebar a:hover {
    background-color: rgba(0, 123, 255, 0.08);
    color: var(--primary-blue);
}
.sidebar a.active {
    background-color: var(--primary-blue);
    color: white; font-weight: 600;
    box-shadow: 0 4px 8px rgba(0, 123, 255, 0.2);
}
.sidebar a i {
    font-size: 18px; color: var(--text-light); transition: color 0.3s ease;
}
.sidebar a.active i { color: white; }

/* *** LOGOUT ALIGNMENT CSS *** */
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
main.main-content {
    flex:1; margin-left:var(--sidebar-width);
    background:var(--bg-page); padding:40px;
    overflow-y:auto;
}
header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;}
header h1{
    font-size:1.8rem; font-weight:700;
    color:var(--text-dark);
    display:flex; align-items:center; gap:10px;
}

/* Flash Messages */
.flash-message{
    padding:15px 20px; border-radius:8px;
    font-weight:600; font-size:1rem;
    display:flex; align-items:center; gap:10px;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.flash-error{background:#fff1f0;color:var(--alert-red); border: 1px solid #ffa39e;}

/* --- Filter Bar --- */
.filter-controls {
    display: flex;
    gap: 15px;
    align-items: center;
    background: var(--widget-bg);
    padding: 15px 20px;
    border-radius: 10px;
    box-shadow: 0 4px 10px rgba(0,0,0,0.05);
    margin-bottom: 30px;
}
.search-form {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
    flex-grow: 1;
}

/* Styling for all form controls */
.search-form input[type="text"],
.search-form select,
.search-form input[type="date"]{
    padding:10px 15px;
    border-radius:8px;
    border:1px solid #ced4da;
    font-weight:500;
    font-size: 1rem;
    transition: all 0.3s;
}
.search-form input:focus, .search-form select:focus {
    border-color: var(--primary-blue); outline:none; box-shadow:0 0 0 3px rgba(0,123,255,0.1);
}
.search-form label {
    font-weight: 600;
    color: var(--text-dark);
}

/* Buttons */
.search-form button, .export-csv-btn {
    padding: 10px 15px;
    border: none;
    border-radius: 8px;
    background: var(--primary-blue);
    color: white;
    cursor: pointer;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: background-color 0.3s;
}
.search-form button:hover{background:var(--secondary-blue);}

.export-csv-btn{background:var(--success-green);}
.export-csv-btn:hover{background:#1e7e34;}

.reset-link-btn {
    padding: 10px 15px; 
    border-radius: 8px; 
    background: #6c757d; 
    color: white; 
    text-decoration: none; 
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.reset-link-btn:hover {
    background: #5a6268;
}

/* --- Table Styles --- */
.table-container{
    background:var(--widget-bg);
    border-radius:10px;
    box-shadow:0 4px 15px rgba(0,0,0,0.05);
    overflow-x: auto; /* Allows horizontal scrolling for large tables */
}
.data-table{width:100%;border-collapse:separate; border-spacing: 0; min-width: 1000px;} /* Ensure table is wide enough to scroll */
.data-table th{
    padding:15px;
    text-align:left;
    background:var(--primary-blue);
    color:white;
    font-weight:600;
    text-transform:uppercase;
    font-size: 0.8rem;
}
.data-table td{padding:12px 15px;text-align:left;border-bottom:1px solid #f0f0f0;font-weight:400;color:var(--text-dark);}
.data-table th:first-child { border-top-left-radius: 10px; }
.data-table th:last-child { border-top-right-radius: 10px; }
.data-table tr:last-child td { border-bottom: none; }
.data-table tr:hover{background:#f5f8fc;}

.supplies-list{font-weight:500;font-size:0.9rem;color:var(--secondary-blue);}
.supplies-list span { display: block; white-space: nowrap; }

/* Mobile Responsive Styles (Kept for consistency) */
.mobile-header { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 60px; background: var(--widget-bg); box-shadow: 0 2px 5px rgba(0,0,0,0.1); z-index: 100; justify-content: space-between; align-items: center; padding: 0 20px; }
.mobile-logo { font-size: 22px; font-weight: 700; color: var(--primary-blue); }
#menu-toggle-open { background: none; border: none; font-size: 24px; color: var(--primary-blue); cursor: pointer; }

@media (max-width: 992px) {
    .mobile-header { display: flex; }
    .main-content { margin-left: 0; padding: 20px; padding-top: 80px; }
    .sidebar { width: 80%; max-width: 300px; transform: translateX(-100%); z-index: 1050; }
    .sidebar.open { transform: translateX(0); box-shadow: 5px 0 15px rgba(0,0,0,0.2); }
    .sidebar-header { justify-content: space-between; border-bottom: 1px solid #e9ecef; }
    .sidebar-header button { display: block !important; background: none; border: none; color: var(--text-light); font-size: 24px; }
    .sidebar-nav { padding: 0; }
    .sidebar a { margin: 0; border-radius: 0; padding: 15px 30px; border-bottom: 1px solid #f0f0f0; }
    .sidebar .logout { padding: 15px 30px; margin: 0; margin-top: 10px; border-radius: 0; }
    
    .filter-controls { flex-direction: column; align-items: flex-start; padding: 15px; }
    .search-form { flex-direction: column; width: 100%; }
    .search-form input, .search-form select { width: 100%; margin-top: 5px; }
    .search-form button, .reset-link-btn { width: 100%; margin-top: 10px; justify-content: center; }
    
    .table-container { overflow-x: auto; padding: 10px; }
    .data-table { min-width: 1200px; } /* Ensure horizontal scroll works */
    header { flex-direction: column; align-items: flex-start; }
    .export-csv-btn { width: 100%; margin-top: 10px; justify-content: center; }
}
</style>
</head>
<body>

<div class="mobile-header" id="mobileHeader">
    <button id="menu-toggle-open"><i class="fas fa-bars"></i></button>
    <div class="mobile-logo">DentiTrack</div>
    <div style="width: 24px;"></div> 
</div>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <i class="fas fa-tooth"></i> DentiTrack
        <button id="menu-toggle-close" style="display: none;"><i class="fas fa-times"></i></button>
    </div>
    <nav class="sidebar-nav">
        <a href="secretary_dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
        <a href="find_patient.php" ><i class="fas fa-search"></i> Find Patient</a>
        <a href="view_patients.php"><i class="fas fa-users"></i> Patients List</a>
        <a href="online_bookings.php"><i class="fas fa-calendar-check"></i> Booking Mgmt</a>
        <a href="appointments.php"><i class="fas fa-calendar-alt"></i> Consultations</a>
        <a href="services_list.php"><i class="fas fa-briefcase-medical"></i> Services</a>
        <a href="inventory.php"><i class="fas fa-boxes"></i> Inventory Stock</a>
        <a href="payments.php"><i class="fas fa-money-bill-wave"></i> Process Payments</a>
        <a href="pending_installments.php"><i class="fas fa-credit-card"></i> Pending Installments</a>
        <a href="pending_payments.php"><i class="fas fa-credit-card"></i> Pending Payments</a>
        <a href="payment_logs.php" class="active" ><i class="fas fa-file-invoice-dollar"></i> Payments Log</a>
        <a href="create_announcements.php"><i class="fas fa-bullhorn"></i> Announcements</a>
    </nav>
    <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Log Out</a>
</div>

<main class="main-content">
<header>
    <h1><i class="fas fa-file-invoice-dollar"></i> Payments Log</h1>
    <a href="export_payment_logs_csv.php?search=<?= urlencode($search) ?>&service_filter=<?= urlencode($service_filter) ?>&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>" class="export-csv-btn">
        <i class="fas fa-file-csv"></i> Export Filtered to CSV
    </a>
</header>

<div class="filter-controls">
    <form class="search-form" method="GET" id="paymentLogForm">
        
        <label for="service_filter">Service:</label>
        <select name="service_filter" id="service_filter">
            <option value="all">-- All Services --</option>
            <?php foreach ($services_list as $service): ?>
                <option value="<?= htmlspecialchars($service['service_id']) ?>" 
                        <?= $service_filter == $service['service_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($service['service_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="start_date">From:</label>
        <input type="date" name="start_date" id="start_date" value="<?= htmlspecialchars($start_date) ?>">

        <label for="end_date">To:</label>
        <input type="date" name="end_date" id="end_date" value="<?= htmlspecialchars($end_date) ?>">

        <input type="text" name="search" id="search_input" placeholder="Search patient/service name..." value="<?= htmlspecialchars($search) ?>">
        
        <button type="submit" id="manual_filter_btn"><i class="fas fa-filter"></i> Apply Filters</button>
        <a href="payment_logs.php" class="reset-link-btn">
            <i class="fas fa-undo"></i> Reset
        </a>
    </form>
</div>

<?php if ($error): ?>
    <div class="flash-message flash-error"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="table-container">
    <table class="data-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Patient</th>
                <th>Service</th>
                <th>Amount (Service Price)</th>
                <th>Supplies Used (Inventory Only)</th>
                <th>Discount</th>
                <th>Net Billed</th>
                <th>Method</th>
                <th>Date Created</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($payments)): ?>
                <?php foreach ($payments as $p): 
                    // FIX: Supplies cost is now always 0 for the patient.
                    $supplies = json_decode($p['supplies_used'], true);
                ?>
                    <tr>
                        <td><?= htmlspecialchars($p['payment_id']) ?></td>
                        <td><?= htmlspecialchars($p['patient_name']) ?></td>
                        <td><?= htmlspecialchars($p['service_name'] ?? '-') ?></td>
                        <td>₱<?= number_format($p['amount'],2) ?></td>
                        <td class="supplies-list">
                            <?php 
                                if (!empty($supplies) && $supplies !== '[]') {
                                    $list = [];
                                    foreach ($supplies as $s) {
                                        // Uses 'name' and 'qty' logged in the payments table
                                        $list[] = htmlspecialchars($s['name']) . ' x' . intval($s['qty']) . ' ' . htmlspecialchars($s['unit'] ?? '');
                                    }
                                    echo implode('<br>', $list);
                                } else {
                                    echo '-';
                                }
                            ?>
                        </td>
                        <td>
                            <?php if (floatval($p['discount_amount']) > 0): ?>
                                <?= htmlspecialchars($p['discount_type']) ?> (₱<?= number_format($p['discount_amount'],2) ?>)
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td>₱<?= number_format($p['total_amount'],2) ?></td>
                        <td><?= ucfirst($p['payment_method']) ?></td>
                        <td>
                            <?= date('M d, Y', strtotime($p['created_at'])) ?><br>
                            <small style="color:var(--text-light);"><?= date('g:i A', strtotime($p['created_at'])) ?></small>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="9" style="text-align:center; color: var(--text-light);">No payments found based on current filters.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

</main>

<script>
// Debounce function to limit how often a function is called
function debounce(func, timeout = 300) {
    let timer;
    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            func.apply(this, args);
        }, timeout);
    };
}

document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('.search-form');
    const serviceFilter = document.getElementById('service_filter');
    const startDateInput = document.getElementById('start_date');
    const endDateInput = document.getElementById('end_date');
    const searchInput = document.getElementById('search_input');
    const manualFilterBtn = document.getElementById('manual_filter_btn');
    const sidebar = document.getElementById('sidebar');
    const menuToggleOpen = document.getElementById('menu-toggle-open');
    const menuToggleClose = document.getElementById('menu-toggle-close');
    
    const autoSubmitForm = () => {
        form.submit();
    };
    
    const debouncedSubmit = debounce(autoSubmitForm, 300);

    // 1. Attach change listeners for immediate search on select and date inputs
    serviceFilter.addEventListener('change', autoSubmitForm);
    startDateInput.addEventListener('change', autoSubmitForm);
    endDateInput.addEventListener('change', autoSubmitForm);

    // 2. Attach input listener for automatic, debounced search on text input
    searchInput.addEventListener('input', debouncedSubmit);

    // 3. Prevent default form submission on enter keypress if not using the filter button
    form.addEventListener('submit', (e) => {
        if (document.activeElement !== manualFilterBtn) {
            e.preventDefault();
            // Call the debounced submit just in case Enter was pressed immediately after typing
            debouncedSubmit();
        }
    });

    // --- MOBILE MENU TOGGLE ---
    if (window.innerWidth < 992) {
        // Initial setup for mobile toggle visibility
        // Ensure the close button is hidden on load when on mobile
        if (menuToggleClose) menuToggleClose.style.display = 'none';
        if (menuToggleOpen) menuToggleOpen.style.display = 'block';

        if (menuToggleOpen) {
            menuToggleOpen.addEventListener('click', function() {
                sidebar.classList.add('open');
                if (menuToggleClose) menuToggleClose.style.display = 'block';
                document.body.style.overflow = 'hidden'; 
            });
        }
        
        if (menuToggleClose) {
             menuToggleClose.addEventListener('click', function() {
                sidebar.classList.remove('open');
                menuToggleClose.style.display = 'none';
                document.body.style.overflow = ''; 
            });
        }
        
        document.querySelectorAll('.sidebar a').forEach(link => {
            link.addEventListener('click', function() {
                setTimeout(() => {
                    sidebar.classList.remove('open');
                    document.body.style.overflow = '';
                    if (menuToggleClose) menuToggleClose.style.display = 'none';
                }, 300);
            });
        });
    }
});
</script>

</body>
</html>