<?php
session_start();

// --- SECURITY CHECK (Unchanged) ---
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'secretary') {
    header('Location: ../public/login.php');
    exit();
}

// --- PHP Fix: Standardize Database Connection (Using PDO) ---
if (file_exists('../config/db_pdo.php')) {
    require_once '../config/db_pdo.php'; 
    $conn = $pdo; // Standardize variable name to $conn (or $pdo, choosing $conn for this block)
} else {
    // Fallback or error if the main config is missing
    die("Error: Database configuration file not found.");
}

// Ensure PDO attributes are set for consistency
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$secretary_username = $_SESSION['username'] ?? 'Secretary';
$error_message = '';

// --- Data Fetching (Re-implemented using PDO for safety) ---
try {
    // Note: This block is typically for a dashboard, but remains here to prevent logic errors.
    
    $stmt_patients_count = $conn->query("SELECT COUNT(*) as count FROM patient");
    $patients_count = $stmt_patients_count->fetchColumn(); 

    $stmt_upcoming_appointments = $conn->query("SELECT COUNT(*) as count FROM appointments WHERE appointment_date >= CURDATE()");
    $upcoming_appointments = $stmt_upcoming_appointments->fetchColumn(); 

    $stmt_announcements_count = $conn->query("SELECT COUNT(*) as count FROM announcements");
    $announcements_count = $stmt_announcements_count->fetchColumn();

    $stmt_inventory_count = $conn->query("SELECT COUNT(*) as count FROM dental_supplies");
    $inventory_count = $stmt_inventory_count->fetchColumn();
    
} catch (PDOException $e) {
    // Initialize counts to 0 upon database error
    $patients_count = $upcoming_appointments = $announcements_count = $inventory_count = 0;
    $error_message = "Failed to load dashboard counts: " . htmlspecialchars($e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Consultations - DentiTrack</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous" />
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
    scrollbar-width: none;     /* Firefox */
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
.sidebar a.active i {
    color: white; 
}
.sidebar a i {
    font-size: 18px;
    color: var(--text-light);
    transition: color 0.3s ease;
}
.sidebar a:hover i {
    color: var(--primary-blue);
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

/* Calendar Container Styling */
.calendar-container {
    background: var(--widget-bg);
    padding: 20px;
    border-radius: 10px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    /* Set min-width or specific styling if the included calendar is wide */
    overflow-x: auto;
}

/* Error Message (for PHP issues) */
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
            <a href="appointments.php" class="active"><i class="fas fa-calendar-alt"></i> Consultations</a>
            <a href="services_list.php"><i class="fas fa-briefcase-medical"></i> Services</a>
            <a href="inventory.php"><i class="fas fa-boxes"></i> Inventory Stock</a>
            <a href="payments.php"><i class="fas fa-money-bill-wave"></i> Process Payments</a>
            <a href="pending_installments.php"><i class="fas fa-credit-card"></i> Pending Installments</a>
            <a href="pending_payments.php"><i class="fas fa-credit-card"></i> Pending Payments</a>
            <a href="payment_logs.php"><i class="fas fa-file-invoice-dollar"></i> Payments Log</a>
            <a href="create_announcements.php"><i class="fas fa-bullhorn"></i> Announcements</a>
        </nav>
        <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Log Out</a>
    </div>

    <main class="main-content">
        <header>
            <h1><i class="fas fa-calendar-alt"></i> Manage Consultations</h1>
        </header>
        
        <?php if (isset($error_message) && !empty($error_message)): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_message) ?>
            </div>
        <?php endif; ?>

        <div class="calendar-container">
            <?php 
                // This file is assumed to contain the calendar structure (PHP/HTML)
                include '../calendar_function/secretary_calendar.php'; 
            ?>
        </div>
        
    </main>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // --- MOBILE MENU TOGGLE LOGIC ---
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
                
                // Auto-close menu when a link is clicked
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
</body>
</html>