<?php
session_start();

// 1. Authentication and Authorization Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'doctor') {
    header('Location: doctor_login.php');
    exit();
}

// 2. Database Connection Includes
// This file is assumed to define the global $pdo variable for the main DB.
require_once '../config/db_pdo.php'; 
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$doctor_username = $_SESSION['username'] ?? 'Doctor';
$error_message = ''; 

// Helper function for safe PDO column fetching
function fetch_pdo_column($pdo, $sql, $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetchColumn();
        return $result !== false ? $result : 0;
    } catch (PDOException $e) {
        error_log("DB Error (Column Fetch): " . $e->getMessage());
        return 0;
    }
}

// 3. Initialize Variables
$patients_count = 0;
$upcoming_appointments = 0;
$announcements_count = 0;
$lowStock = [];

try {
    // === FETCH COUNTS ===
    // Count all patients (for the doctor's general context)
    $patients_count = fetch_pdo_column($pdo, "SELECT COUNT(*) FROM patient"); 
    
    // Upcoming appointments count
    $sql_upcoming = "SELECT COUNT(*) FROM appointments WHERE appointment_date >= CURDATE() AND status IN ('approved', 'booked')";
    $upcoming_appointments = fetch_pdo_column($pdo, $sql_upcoming);
    
    // Announcements count
    $announcements_count = fetch_pdo_column($pdo, "SELECT COUNT(*) FROM announcements");


    // === LOW STOCK QUERY (Preserved Original Logic) ===
    try {
        $stmt = $pdo->query("
            SELECT 
                name,
                low_stock_threshold,
                quantity AS total_quantity 
            FROM dental_supplies 
            WHERE quantity <= low_stock_threshold
        ");
        $lowStock = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Dental Supplies table error: " . $e->getMessage());
        $error_message = "Error checking supplies. Ensure 'dental_supplies' table exists with columns: name, low_stock_threshold, quantity.";
    }
    // ===================================================================

} catch (PDOException $e) {
    error_log("Database query error: " . $e->getMessage());
    if (empty($error_message)) {
        $error_message = "A main database error occurred. Check logs for details.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Doctor Dashboard - DentiTrack</title>
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
    .sidebar a i {
        font-size: 18px;
        color: var(--text-light);
        transition: color 0.3s ease;
    }
    .sidebar a:hover { background: rgba(0, 123, 255, 0.08); color: var(--primary-blue); }
    .sidebar a.active { background: var(--primary-blue); color: white; font-weight: 600; box-shadow: 0 4px 8px rgba(0, 123, 255, 0.2); }
    .sidebar a.active i { color: white; }
    
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
        transition: all 0.3s ease;
    }
    .sidebar .logout:hover { background-color: #fce8e8; color: var(--alert-red); }


    /* --- Main Content --- */
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
        align-items: flex-start;
        margin-bottom: 20px;
    }
    header h1 {
        font-size: 1.8rem;
        color: var(--secondary-blue);
        border-bottom: 2px solid #e0e0e0;
        padding-bottom: 10px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
        font-weight: 700;
    }
    .welcome {
        font-size: 1.1rem;
        margin-bottom: 30px;
        color: var(--text-light);
        font-weight: 500;
    }
    .welcome strong { font-weight: 700; color: var(--secondary-blue); }
    
    /* --- Widget/Card Styling --- */
    .widgets {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 20px;
        margin-bottom: 40px;
    }
    .widget {
        background: var(--widget-bg);
        padding: 25px;
        border-radius: 10px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.05); 
        cursor: pointer;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        border-left: 5px solid var(--primary-blue);
    }
    .widget:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 20px rgba(0,0,0,0.1);
    }
    .widget i {
        font-size: 38px;
        margin-bottom: 10px;
        color: var(--primary-blue);
    }
    .widget .title { font-size: 0.9rem; color: #777; font-weight: 500; margin-bottom: 5px; }
    .widget .value { font-size: 2.5rem; font-weight: 800; color: var(--text-dark); line-height: 1; margin-bottom: 10px; }
    .widget .subtitle { font-size: 0.8rem; color: var(--text-light); border-top: 1px solid #f0f0f0; padding-top: 10px; }
    
    /* --- Low Stock Alert Styling --- */
    .low-stock-alert {
        margin-top: 40px;
        padding: 25px;
        border-radius: 10px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        border: 2px solid var(--alert-red); 
        background: #fffafa; 
    }
    .low-stock-alert h2 {
        margin: 0 0 15px 0;
        color: var(--alert-red);
        font-size: 1.5rem;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px dashed #ffb3b3;
        padding-bottom: 10px;
    }
    .low-stock-alert ul {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    .low-stock-alert li {
        margin-bottom: 10px;
        padding: 10px;
        border-left: 4px solid var(--alert-red);
        background: #ffebeb; 
        font-size: 0.95rem;
        font-weight: 500;
        border-radius: 4px;
    }
    .low-stock-alert li strong {
        color: var(--alert-red);
        font-weight: 700;
        font-size: 1em;
    }
    .low-stock-alert p.success {
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
    
    /* Inclusion Containers for included PHP files */
    .inclusion-container {
        margin-top: 30px;
        padding: 25px;
        background: var(--widget-bg);
        border-radius: 10px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    }
    .inclusion-container h3 {
        color: var(--primary-blue);
        font-size: 1.5rem;
        margin-top: 0;
        border-bottom: 1px solid #e9ecef;
        padding-bottom: 10px;
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    /* Error Message for PHP issues */
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
        .mobile-header { display: flex; }
        .main-content { margin-left: 0; padding: 20px; padding-top: 80px; }
        
        .sidebar { width: 80%; max-width: 300px; transform: translateX(-100%); z-index: 1050; height: 100vh; }
        .sidebar.open { transform: translateX(0); box-shadow: 5px 0 15px rgba(0,0,0,0.2); }

        .sidebar-header { justify-content: space-between; border-bottom: 1px solid #e9ecef; }
        .sidebar-header button { display: block !important; background: none; border: none; color: var(--text-dark); font-size: 24px; }
        .sidebar-nav { padding: 0; overflow-y: auto !important; }
        .sidebar a { margin: 0; border-radius: 0; padding: 15px 30px; border-bottom: 1px solid #f0f0f0; }
        .sidebar .logout { padding: 15px 30px; margin: 0; margin-top: 10px; border-radius: 0; }
        
        .widgets { grid-template-columns: 1fr; }
        header h1 { width: 100%; padding-bottom: 5px; margin-bottom: 10px; }
        .welcome { margin-bottom: 20px; }
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
            <a href="doctor_dashboard.php" class="active"><i class="fas fa-home"></i> Dashboard</a>
            <a href="my_patients.php"><i class="fas fa-users"></i> My Patients</a>
            <a href="my_appointments.php"><i class="fas fa-calendar-check"></i> My Appointments</a>
           
        </div>
        <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </nav>

    <main class="main-content">
        <header>
            <h1><i class="fas fa-user-md"></i> Doctor Dashboard</h1>
        </header>
        
        <?php if (!empty($error_message)): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-triangle"></i> <?= $error_message ?>
            </div>
        <?php endif; ?>

        <p class="welcome">Welcome, <strong><?= htmlspecialchars($doctor_username) ?></strong>!</p>

        <div class="widgets">
            <div class="widget" onclick="location.href='my_patients.php'">
                <i class="fas fa-users"></i>
                <div class="title">Total Patients</div>
                <div class="value"><?= $patients_count ?></div>
                <div class="subtitle">Total registered patients in the system</div>
            </div>

            <div class="widget" onclick="location.href='my_appointments.php'">
                <i class="fas fa-calendar-check"></i>
                <div class="title">Upcoming Appointments</div>
                <div class="value"><?= $upcoming_appointments ?></div>
                <div class="subtitle">Appointments today and scheduled in the future</div>
            </div>

            <div class="widget" onclick="location.href='announcements.php'">
                <i class="fas fa-bullhorn"></i>
                <div class="title">Active Announcements</div>
                <div class="value"><?= $announcements_count ?></div>
                <div class="subtitle">Clinic updates and notices</div>
            </div>
        </div>

        <div class="low-stock-alert">
            <h2>
                <i class="fas fa-exclamation-triangle"></i>
                Low Stock Supplies 
            </h2>

            <?php if (!empty($lowStock)): ?>
                <ul>
                    <?php foreach ($lowStock as $item): ?>
                        <li>
                            The supply <strong><?= htmlspecialchars($item['name']) ?></strong> is very low.
                            <br/>
                            Quantity: <span style="font-style:italic;"><?= $item['total_quantity'] ?></span> (Threshold: <?= $item['low_stock_threshold'] ?>).
                            Restock Immediately!
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="success">
                    <i class="fas fa-check-circle"></i> All checked dental supplies are currently well-stocked.
                </p>
            <?php endif; ?>
        </div>
        
        <div class="inclusion-container">
            <h3><i class="fas fa-bullhorn"></i> Latest Announcements</h3>
            <?php 
                // Assumed inclusion of the announcements content.
                include '../doctor/announcements.php'; 
            ?>
        </div>
        
        <div class="inclusion-container">
            <h3><i class="fas fa-chart-bar"></i> Performance Analytics</h3>
            <?php 
                // Assumed inclusion of the analytics graph.
                include '../analytics_graph/analytics_doctor.php'; 
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
                
                if (menuToggleOpen && menuToggleClose && sidebar) {
                    // Initial state: hide close button
                    menuToggleClose.style.display = 'none';

                    // Open functionality
                    menuToggleOpen.addEventListener('click', function() {
                        sidebar.classList.add('open');
                        menuToggleClose.style.display = 'block';
                        document.body.style.overflow = 'hidden'; 
                    });
                    
                    // Close functionality
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