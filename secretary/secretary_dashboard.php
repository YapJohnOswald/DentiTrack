<?php
session_start();
// 1. Security Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'secretary') {
    header('Location: ../public/login.php');
    exit();
}

// --- PHP Fix: Standardize Database Connection (Using PDO) ---
if (file_exists('../config/db_pdo.php')) {
    require_once '../config/db_pdo.php'; 
    $conn = $pdo; // Standardize variable name
} else {
    // If db_pdo is missing, stop execution with an error
    die("Error: Database configuration file not found.");
}

// Ensure PDO attributes are set
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$secretary_username = $_SESSION['username'] ?? 'Secretary'; // Username identifier
$error_message = '';
$low_stock_items = [];

try {
    // --- Data Fetching (Using PDO) ---
    
    // Count total patients
    $patients_count = $conn->query("SELECT COUNT(*) FROM patient")->fetchColumn(); 

    // Count upcoming appointments (status 'approved' or 'booked')
    $stmt_upcoming = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date >= CURDATE() AND status IN ('approved', 'booked')"); 
    $stmt_upcoming->execute();
    $upcoming_appointments = $stmt_upcoming->fetchColumn();

    // Count announcements 
    $announcements_count = $conn->query("SELECT COUNT(*) FROM announcements")->fetchColumn();

    // Count total inventory items
    $inventory_count = $conn->query("SELECT COUNT(*) FROM dental_supplies")->fetchColumn();

    // Fetch low stock items
    $sql_low_stock = "SELECT name, quantity, low_stock_threshold FROM dental_supplies WHERE quantity <= low_stock_threshold";
    $low_stock_items = $conn->query($sql_low_stock)->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // Initialize counts to 0 upon database error
    $patients_count = $upcoming_appointments = $announcements_count = $inventory_count = 0;
    $error_message = "Database Error: Failed to load dashboard metrics. " . htmlspecialchars($e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Clinic Overview | Secretary</title>
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
        --bg-light: #f8f9fa;
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
        /* Hidden Scrollbar */
        -ms-overflow-style: none;
        scrollbar-width: none;
    }
    .sidebar-nav::-webkit-scrollbar {
        display: none;
    }
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
        margin-bottom: 20px; 
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
    .welcome { 
        font-size: 1rem; 
        margin-bottom: 30px; 
        font-weight: 500; 
        color: var(--text-light); 
    }
    .welcome strong {
        color: var(--primary-blue);
    }
    .welcome span.identifier {
        font-weight: 700;
        color: var(--primary-blue);
    }

    /* --- Widget/Card Styling --- */
    .widgets { 
        display: grid; 
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); 
        gap: 20px; 
    }
    .widget { 
        background: var(--widget-bg); 
        padding: 25px; 
        border-radius: 10px; 
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05); 
        text-align: left; 
        cursor: pointer; 
        transition: transform 0.3s ease, box-shadow 0.3s ease; 
        border-left: 5px solid var(--primary-blue); 
    }
    .widget:hover { 
        transform: translateY(-5px); 
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1); 
    }
    .widget i { 
        font-size: 32px; 
        color: var(--primary-blue); 
        margin-bottom: 15px; 
    }
    .widget .title { 
        font-size: 16px; 
        font-weight: 600; 
        color: var(--text-light); 
        margin-bottom: 5px; 
    }
    .widget .value { 
        font-size: 38px; 
        font-weight: 800; 
        color: var(--text-dark); 
    }
    .widget .subtitle { 
        font-size: 12px; 
        color: #999; 
        margin-top: 10px;
    }

    /* Low stock note styling */
    .low-stock-note {
        margin-top: 40px;
        padding: 25px;
        border-radius: 10px;
        border: 1px solid var(--alert-red);
        background: #fffafa;
        box-shadow: 0 4px 12px rgba(220, 53, 69, 0.1);
    }
    .low-stock-note h2 {
        margin: 0 0 15px 0;
        font-size: 20px;
        color: var(--alert-red);
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .low-stock-note ul {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    .low-stock-note li {
        margin-bottom: 8px;
        padding: 12px;
        border-left: 4px solid var(--alert-red);
        background: #ffebeb;
        font-size: 15px;
        color: var(--text-dark);
        font-weight: 500;
        border-radius: 4px;
    }
    .low-stock-note li strong {
        color: var(--alert-red);
        font-weight: 700;
    }
    .low-stock-note p.success {
        color: var(--success-green);
        font-weight: 600;
        text-align: center;
        padding: 10px;
        background: #f0fff0;
        border-radius: 5px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    /* Analytics Container */
    .analytics-container {
        background: var(--widget-bg);
        padding: 25px;
        margin-top: 30px;
        border-radius: 10px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
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

        /* Sidebar full screen */
        .sidebar {
            width: 80%;
            max-width: 300px;
            position: fixed;
            top: 0;
            left: 0;
            transform: translateX(-100%);
            box-shadow: none;
            z-index: 1050;
            padding: 0;
            height: 100vh;
        }
        
        /* Slide sidebar into view when open */
        .sidebar.open {
            transform: translateX(0);
            box-shadow: 5px 0 15px rgba(0,0,0,0.2);
        }
        
        /* Main content needs full width and padding push down */
        .main-content {
            margin-left: 0;
            padding: 20px;
            padding-top: 80px;
        }
        
        /* Mobile-specific sidebar header */
        .sidebar-header {
            border-bottom: 1px solid #e9ecef;
            justify-content: space-between;
        }
        .sidebar-header button {
            display: block !important;
            background: none;
            border: none;
            color: var(--text-light);
            font-size: 24px;
        }
        
        /* Adjust links and logout for mobile menu */
        .sidebar-nav {
            padding: 0;
            overflow-y: auto !important; 
        }
        .sidebar a {
            margin: 0;
            border-radius: 0;
            padding: 15px 30px;
            border-bottom: 1px solid #f0f0f0;
        }
        .logout {
             padding: 15px 30px; 
             margin-top: 10px;
             border-radius: 0;
        }

        /* Adjust widget layout for smaller screens */
        .widgets {
            grid-template-columns: 1fr; 
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
            <a href="find_patient.php" ><i class="fas fa-search"></i> Find Patient</a>
            <a href="view_patients.php"><i class="fas fa-users"></i> Patients List</a>
            <a href="online_bookings.php"><i class="fas fa-calendar-check"></i> Booking Mgmt</a>
            <a href="appointments.php"><i class="fas fa-calendar-alt"></i> Consultations</a>
            <a href="services_list.php"><i class="fas fa-briefcase-medical"></i> Services</a>
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
            <h1><i class="fas fa-chart-line"></i> Clinic Overview</h1>
        </header>
        
        <?php if (!empty($error_message)): ?>
            <div class="error-message low-stock-note">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_message) ?>
            </div>
        <?php endif; ?>

        <p class="welcome">Welcome, <span class="identifier"><?= htmlspecialchars($secretary_username) ?></span>. Here is an overview of the clinic operations.</p>

        <div class="widgets">
            <div class="widget" onclick="location.href='view_patients.php'">
                <i class="fas fa-users"></i>
                <div class="title">Total Patients</div>
                <div class="value"><?= $patients_count ?></div>
                <div class="subtitle">All registered patient records</div>
            </div>

            <div class="widget" onclick="location.href='online_bookings.php'">
                <i class="fas fa-calendar-check"></i>
                <div class="title">Pending Bookings</div>
                <div class="value"><?= $upcoming_appointments ?></div>
                <div class="subtitle">Appointments to be reviewed/approved</div>
            </div>

            <div class="widget" onclick="location.href='inventory.php'">
                <i class="fas fa-boxes"></i>
                <div class="title">Total Inventory Items</div>
                <div class="value"><?= $inventory_count ?></div>
                <div class="subtitle">Current count of all dental supplies</div>
            </div>

            <div class="widget" onclick="location.href='create_announcements.php'">
                <i class="fas fa-bullhorn"></i>
                <div class="title">Announcements</div>
                <div class="value"><?= $announcements_count ?></div>
                <div class="subtitle">Currently active clinic updates</div>
            </div>
        </div>

        <div class="low-stock-note">
            <h2><i class="fas fa-exclamation-triangle"></i> Low Stock Alert</h2>
            <?php if (!empty($low_stock_items)): ?>
                <ul>
                    <?php foreach ($low_stock_items as $item): ?>
                        <li>
                            Supply **<?= htmlspecialchars($item['name']) ?>** is low.<br>
                            Quantity: **<?= $item['quantity'] ?>** (Threshold: <?= $item['low_stock_threshold'] ?>). Restock immediately!
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="success"><i class="fas fa-check-circle"></i> All dental supplies are well-stocked.</p>
            <?php endif; ?>
        </div>

        <div class="analytics-container">
        <?php 
            // Analytics graph inclusion
            include '../analytics_graph/analytics_secretary.php'; 
        ?> 
        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const menuToggleOpen = document.getElementById('menu-toggle-open');
            const menuToggleClose = document.getElementById('menu-toggle-close');

            // --- MOBILE MENU TOGGLE ---
            if (window.innerWidth < 992) {
                // Initial check: Hide the close button and desktop header elements
                if (menuToggleClose) menuToggleClose.style.display = 'none';

                // Open functionality
                if (menuToggleOpen) {
                    menuToggleOpen.addEventListener('click', function() {
                        sidebar.classList.add('open');
                        if (menuToggleClose) menuToggleClose.style.display = 'block';
                        document.body.style.overflow = 'hidden'; // Prevent main content scroll
                    });
                }
                
                // Close functionality
                if (menuToggleClose) {
                    menuToggleClose.addEventListener('click', function() {
                        sidebar.classList.remove('open');
                        menuToggleClose.style.display = 'none';
                        document.body.style.overflow = ''; // Restore scroll
                    });
                }

                // Auto-close menu when a link is clicked
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