<?php
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'doctor') {
    header('Location: doctor_login.php');
    exit();
}

// 2. Database Connection Includes
require_once '../config/db_pdo.php'; 
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$doctor_username = $_SESSION['username'] ?? '';
$clinicDb = $_SESSION['clinic_db'] ?? ''; 

// Initialize counts (Used for sidebar and potential dashboard context)
$patients_count = 0;
$upcoming_appointments = 0;
$announcements_count = 0;
$treatments_count = 0;

try {
    if ($clinicDb) {
        // Connect to clinic-specific DB
        $clinicPDO = new PDO("mysql:host=localhost;dbname=$clinicDb;charset=utf8mb4", "root", "", [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Count patients assigned to this doctor
        $stmt = $clinicPDO->prepare("SELECT COUNT(*) FROM patients WHERE assigned_doctor = :doctor_username");
        $stmt->execute(['doctor_username' => $doctor_username]);
        $patients_count = $stmt->fetchColumn();

        // Count upcoming appointments
        $stmt = $clinicPDO->prepare("SELECT COUNT(*) FROM appointments WHERE doctor_username = :doctor_username AND appointment_date >= CURDATE()");
        $stmt->execute(['doctor_username' => $doctor_username]);
        $upcoming_appointments = $stmt->fetchColumn();

        // Count treatments
        $stmt = $clinicPDO->prepare("SELECT COUNT(*) FROM treatments WHERE doctor_username = :doctor_username");
        $stmt->execute(['doctor_username' => $doctor_username]);
        $treatments_count = $stmt->fetchColumn();
    }

    // Announcements are global from main DB
    $stmt = $pdo->query("SELECT COUNT(*) FROM announcements");
    $announcements_count = $stmt->fetchColumn();

} catch (PDOException $e) {
    error_log("Database query error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Doctor Appointments - DentiTrack</title>
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
        font-family: 'Inter', sans-serif; /* Consistent font */
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
        background: var(--widget-bg); /* Use white background for main content area for clean look */
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
        color: var(--secondary-blue);
        border-bottom: 2px solid #e0e0e0;
        padding-bottom: 10px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
        font-weight: 700;
    }
    
    /* Calendar Container Styling (to match dashboard cards) */
    .calendar-container-wrapper {
        background: var(--bg-page); /* Use page color outside calendar */
        padding: 20px;
        border-radius: 10px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.05);
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
            <a href="doctor_dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
            <a href="my_patients.php"><i class="fas fa-users"></i> My Patients</a>
            <a href="my_appointments.php" class="active"><i class="fas fa-calendar-check"></i> My Appointments</a>
           
        </div>
        <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </nav>

    <main class="main-content">
        <header>
            <h1><i class="fas fa-calendar-alt"></i> Appointment Schedule</h1>
        </header>

        <p style="color:var(--text-dark); margin-bottom: 25px;">
            Welcome, <?= htmlspecialchars($doctor_username) ?>! Manage your consultation schedule here.
        </p>

        <div class="calendar-container-wrapper">
            <?php include '../calendar_function/doctor_calendar.php'; ?>
        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const menuToggleOpen = document.getElementById('menu-toggle-open');
            const menuToggleClose = document.getElementById('menu-toggle-close');

            // --- MOBILE MENU TOGGLE ---
            if (window.innerWidth < 992) {
                
                // Add the close button dynamically if it doesn't exist
                if (!document.getElementById('menu-toggle-close') && sidebar) {
                    const closeBtn = document.createElement('button');
                    closeBtn.id = 'menu-toggle-close';
                    closeBtn.innerHTML = '<i class="fas fa-times"></i>';
                    closeBtn.style.cssText = 'background:none; border:none; font-size:24px; color:var(--text-dark); cursor:pointer; margin-left: auto;';
                    sidebar.querySelector('.sidebar-header').appendChild(closeBtn);
                }
                const newMenuToggleClose = document.getElementById('menu-toggle-close');

                if (menuToggleOpen && newMenuToggleClose && sidebar) {
                    newMenuToggleClose.style.display = 'none';

                    menuToggleOpen.addEventListener('click', function() {
                        sidebar.classList.add('open');
                        newMenuToggleClose.style.display = 'block';
                        document.body.style.overflow = 'hidden'; 
                    });
                    
                    newMenuToggleClose.addEventListener('click', function() {
                        sidebar.classList.remove('open');
                        newMenuToggleClose.style.display = 'none';
                        document.body.style.overflow = ''; 
                    });

                    document.querySelectorAll('.sidebar a').forEach(link => {
                        link.addEventListener('click', function() {
                            setTimeout(() => {
                                sidebar.classList.remove('open');
                                document.body.style.overflow = '';
                                newMenuToggleClose.style.display = 'none';
                            }, 300);
                        });
                    });
                }
            }
        });
    </script>
</body>
</html>