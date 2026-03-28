<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'secretary') {
    header('Location: ../public/login.php');
    exit();
}

// --- PHP Fix: Standardize Database Connection ---
// Assuming '../config/db_pdo.php' correctly sets up $pdo (PDO connection object)
if (file_exists('../config/db_pdo.php')) {
    require_once '../config/db_pdo.php'; 
    $conn = $pdo; // Standardize variable name
} else {
    die("Error: Database configuration file not found.");
}

// Ensure PDO attributes are set for consistency
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$secretary_username = $_SESSION['username'] ?? 'Secretary';
$error_message = ''; 

// --- 1. Filter Variables ---
$search_term = $_GET['search'] ?? '';
$status_filter = $_GET['filter_status'] ?? 'all';

// ✅ Handle AJAX update for status
if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
    $service_id = $_POST['service_id'];
    $status = $_POST['status'];

    try {
        $update = $conn->prepare("UPDATE services SET status = :status WHERE service_id = :id");
        $update->bindParam(':status', $status);
        $update->bindParam(':id', $service_id);
        $update->execute();
        echo "success";
    } catch (PDOException $e) {
        http_response_code(500);
        echo "Database error during AJAX update: " . $e->getMessage();
    }
    exit;
}

// --- 2. Build Dynamic SQL Query ---
$sql = "SELECT * FROM services";
$where_clauses = [];
$params = [];

// a) Text Search Filter (Service Name or Category)
if (!empty($search_term)) {
    $where_clauses[] = "(service_name LIKE :search_term OR category LIKE :search_term)";
    $params[':search_term'] = "%$search_term%";
}

// b) Status Filter
if ($status_filter !== 'all') {
    $where_clauses[] = "status = :status_filter";
    $params[':status_filter'] = $status_filter;
}

if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(" AND ", $where_clauses);
}

$sql .= " ORDER BY service_id ASC";

// Fetch all services based on filters
try {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle query error
    $services = [];
    $error_message = "Database query error: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Services List - DentiTrack</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
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

/* --- Sidebar Styling (Fixed & Aligned) --- */
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
    
    /* --- START: HIDDEN SCROLLBAR CSS --- */
    -ms-overflow-style: none;  /* IE and Edge */
    scrollbar-width: none; /* Firefox */
}
/* Chrome, Safari, and Opera scrollbar hiding */
.sidebar-nav::-webkit-scrollbar {
    display: none;
}
/* --- END: HIDDEN SCROLLBAR CSS --- */

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
.sidebar .logout {
    margin-top: auto; 
    border-top: 1px solid #e9ecef; 
    padding: 12px 15px; /* Aligned with other links */
    margin: 8px 15px; /* Aligned with other links */
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

/* --- Main Content Styling --- */
.main-content { 
    flex: 1; 
    margin-left: var(--sidebar-width); 
    padding: 40px; 
    background: var(--bg-page); 
    overflow-y: auto; 
}
header { 
    display: flex; 
    justify-content: space-between; 
    align-items: center; 
    margin-bottom: 30px; 
}
header h1 { 
    font-size: 1.8rem; 
    color: var(--text-dark); 
    font-weight: 700; 
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}
/* Removed .welcome and .welcome strong CSS rules */


/* Table Styling */
table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    background: var(--widget-bg);
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
}
th, td {
    padding: 15px 18px;
    text-align: left;
    border-bottom: 1px solid #f0f0f0;
}
th {
    background-color: var(--primary-blue);
    color: white;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 0.85rem;
}
th:first-child { border-top-left-radius: 10px; }
th:last-child { border-top-right-radius: 10px; }
tr:last-child td { border-bottom: none; }
tr:hover { background-color: #f5f8fc; }

.price {
    font-weight: 600;
    color: var(--success-green);
}

.no-data {
    text-align: center;
    padding: 20px;
    color: var(--text-light);
}

/* Status Select */
select.status-select {
    padding: 8px 12px;
    border-radius: 6px;
    border: 1px solid #ced4da;
    font-size: 14px;
    cursor: pointer;
    background-color: var(--bg-light);
    transition: border-color 0.3s;
}
select.status-select:focus {
    border-color: var(--primary-blue);
    outline: none;
    box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.1);
}

/* Success Message */
.success-message {
    position: fixed;
    top: 20px;
    right: 20px;
    background: var(--success-green);
    color: white;
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 600;
    display: none;
    z-index: 1000;
    box-shadow: 0 4px 10px rgba(0,0,0,0.2);
    display: flex;
    align-items: center;
    gap: 10px;
}

/* Error Message */
.error-message {
    background:#fff1f0;
    color:var(--alert-red); 
    padding:15px; 
    border-radius:8px; 
    margin-bottom: 20px;
    border: 1px solid #ffa39e;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
}

/* Filter Styles */
.controls-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding: 15px 20px;
    background: var(--widget-bg);
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.filter-form {
    display: flex;
    gap: 10px;
    align-items: center;
}
.filter-form input[type="text"], .filter-form select {
    padding: 10px 15px;
    border: 1px solid #ced4da;
    border-radius: 8px;
    font-size: 15px;
    transition: all 0.3s;
    box-shadow: inset 0 1px 2px rgba(0,0,0,0.05);
}
.filter-form input[type="text"]:focus, .filter-form select:focus {
    border-color: var(--primary-blue);
    outline: none;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
}
.filter-form label {
    font-weight: 600;
    color: var(--text-dark);
}
.filter-form button {
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
.filter-form button:hover {
    background-color: var(--secondary-blue);
}
.reset-link {
    padding: 10px 15px;
    background-color: #6c757d; 
    color: white;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
    transition: background-color 0.3s;
    display: flex;
    align-items: center;
    gap: 5px;
}
.reset-link:hover {
    background-color: #5a6268;
}

/* --- MOBILE SPECIFIC HEADER --- */
.mobile-header { 
    display: none;
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

/* Responsive Design (for smaller screens) */
@media (max-width: 992px) {
    /* Show mobile header */
    .mobile-header {
        display: flex; 
    }
    .main-content {
        margin-left: 0;
        padding: 20px;
        padding-top: 80px;
    }
    
    /* Sidebar full screen overlay */
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
        border-bottom: 1px solid #e9ecef;
    }
    .sidebar-header button {
        display: block !important;
        background: none;
        border: none;
        color: var(--text-light);
        font-size: 24px;
    }
    .sidebar-nav {
        padding: 0;
        /* Ensure mobile sidebar scrolling works without cluttering the screen */
        overflow-y: auto !important; 
    }
    .sidebar a {
        margin: 0;
        border-radius: 0;
        padding: 15px 30px;
        border-bottom: 1px solid #f0f0f0;
    }

    /* Filters and Search stacking */
    .controls-container {
        flex-direction: column;
        align-items: flex-start;
        padding: 15px;
    }
    .filter-form {
        flex-direction: column;
        width: 100%;
        gap: 10px;
    }
    .filter-form input[type="text"], .filter-form select, .filter-form button, .reset-link {
        width: 100%;
    }
    /* Table scroll */
    .main-content > table {
        display: block;
        overflow-x: auto;
        white-space: nowrap;
    }
    .main-content table thead, .main-content table tbody {
        display: table;
        width: 100%;
    }
    .main-content table th, .main-content table td {
        min-width: 120px; /* Ensure columns don't shrink too much */
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
            <a href="services_list.php" class="active"><i class="fas fa-briefcase-medical"></i> Services</a>
            <a href="inventory.php"><i class="fas fa-boxes"></i> Inventory Stock</a>
            <a href="payments.php"><i class="fas fa-money-bill-wave"></i> Process Payments</a>
            <a href="pending_installments.php"><i class="fas fa-credit-card"></i> Pending Installments</a>
            <a href="pending_payments.php"><i class="fas fa-credit-card"></i> Pending Payments</a>
            <a href="payment_logs.php" ><i class="fas fa-file-invoice-dollar"></i> Payments Log</a>
            <a href="create_announcements.php"><i class="fas fa-bullhorn"></i> Announcements</a>
        </nav>
        <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Log Out</a>
    </div>

    <main class="main-content">
        <header>
            <h1><i class="fas fa-briefcase-medical"></i> Services List</h1>
        </header>

        <div class="success-message" id="successMsg" style="display: none;"><i class="fas fa-check-circle"></i> Status updated successfully!</div>
        
        <?php if (isset($error_message) && !empty($error_message)): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_message) ?>
            </div>
        <?php endif; ?>

        <div class="controls-container">
            <form method="GET" class="filter-form" id="serviceFilterForm">
                <label for="filter_status">Status:</label>
                <select name="filter_status" id="filter_status">
                    <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>-- All --</option>
                    <option value="active" <?= $status_filter == 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $status_filter == 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>

                <input type="text" name="search" id="search_input" placeholder="Search name or category..." value="<?= htmlspecialchars($search_term) ?>">
                
                <button type="submit" id="manual_filter_btn"><i class="fas fa-filter"></i> Filter</button>
                <a href="services_list.php" class="reset-link"><i class="fas fa-undo"></i> Reset</a>
            </form>
        </div>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Service Name</th>
                    <th>Category</th>
                    <th>Description</th>
                    <th>Price (₱)</th>
                    <th>Duration</th>
                    <th>Created At</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($services) > 0): ?>
                    <?php foreach ($services as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['service_id']) ?></td>
                            <td><?= htmlspecialchars($row['service_name']) ?></td>
                            <td><?= htmlspecialchars($row['category']) ?></td>
                            <td><?= htmlspecialchars($row['description']) ?></td>
                            <td class="price">₱<?= number_format($row['price'], 2) ?></td>
                            <td><?= htmlspecialchars($row['duration']) ?></td>
                            <td><?= htmlspecialchars(date('M d, Y', strtotime($row['created_at']))) ?></td>
                            <td>
                                <select class="status-select" data-id="<?= $row['service_id'] ?>">
                                    <option value="active" <?= ($row['status'] == 'active') ? 'selected' : '' ?>>Active</option>
                                    <option value="inactive" <?= ($row['status'] == 'inactive') ? 'selected' : '' ?>>Inactive</option>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="8" class="no-data">No services found matching the criteria.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
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
    // --- 1. AJAX for Status Update ---
    document.querySelectorAll('.status-select').forEach(select => {
        select.addEventListener('change', function() {
            const serviceId = this.dataset.id;
            const newStatus = this.value;

            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('service_id', serviceId);
            formData.append('status', newStatus);

            fetch('services_list.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.text())
            .then(response => {
                if (response.trim() === 'success') {
                    const msg = document.getElementById('successMsg');
                    msg.style.display = 'flex';
                    // Hide after 2 seconds
                    setTimeout(() => msg.style.display = 'none', 2000);
                } else {
                    console.error("AJAX error:", response);
                }
            })
            .catch(error => {
                console.error("Fetch error:", error);
                alert('An error occurred during status update.');
            });
        });
    });

    // --- 2. Automatic Search Filter ---
    const filterForm = document.getElementById('serviceFilterForm');
    const statusFilter = document.getElementById('filter_status');
    const searchInput = document.getElementById('search_input');

    const autoSubmitForm = () => {
        filterForm.submit();
    };
    
    const debouncedSubmit = debounce(autoSubmitForm, 300);

    statusFilter.addEventListener('change', autoSubmitForm);
    searchInput.addEventListener('input', debouncedSubmit);

    // Prevent default form submission on enter unless manual filter button is clicked
    filterForm.addEventListener('submit', (e) => {
        if (document.activeElement.id !== 'manual_filter_btn') {
            e.preventDefault();
        }
    });

    // --- 3. MOBILE MENU TOGGLE ---
    const sidebar = document.getElementById('sidebar');
    const menuToggleOpen = document.getElementById('menu-toggle-open');
    const menuToggleClose = document.getElementById('menu-toggle-close');
    
    if (menuToggleOpen && menuToggleClose) {
        // Only run mobile logic if the elements exist
        
        // Ensure the close button is hidden initially on mobile
        if (window.innerWidth < 992) {
             menuToggleClose.style.display = 'none';
        }

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
        
        // Auto-close menu when a link is clicked
        document.querySelectorAll('.sidebar a').forEach(link => {
            link.addEventListener('click', function() {
                // Use a short timeout to allow navigation before closing
                setTimeout(() => {
                    sidebar.classList.remove('open');
                    document.body.style.overflow = '';
                    menuToggleClose.style.display = 'none';
                }, 300);
            });
        });
    }
});
</script>

</body>
</html>