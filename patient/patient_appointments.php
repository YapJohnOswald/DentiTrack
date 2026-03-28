<?php
session_start();

// 1. Security Check
// Ensure the user is logged in and has the 'patient' role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') {
    header('Location: ../public/login.php');
    exit();
}

// 2. Database Connection
// Assuming this file establishes a $conn object using mysqli
include '../config/db.php'; 

// Fetch user data for display (optional, but good practice)
$patient_username = $_SESSION['username'] ?? 'Patient';

// Get preselected service ID for booking calendar initialization
$preselected_service_id = isset($_GET['service_id']) ? (int)$_GET['service_id'] : 0;

// The previous block of code containing counts like $patients_count, $inventory_count, etc.,
// is irrelevant for a patient-facing page and has been removed to avoid potential errors 
// or unnecessary database queries.

// Close the connection if the main logic block doesn't need it later
// Note: Depending on what 'patient_calendar.php' does, you might need to keep it open.
// For now, assume it's used within the calendar file, so we omit closing it here.

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Request Consultation - DentiTrack</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
<style>
    /* --- STYLES ALIGNED WITH SECRETARY FIND PATIENT (Responsive & Modern) --- */
    :root {
        --primary-blue: #007bff;
        --secondary-blue: #0056b3;
        --text-dark: #343a40;
        --text-light: #6c757d;
        --bg-page: #eef2f8; /* Light blue/gray background */
        --widget-bg: #ffffff;
        --alert-red: #dc3545;
        --success-green: #28a745;
        --accent-orange: #ffc107;
        --radius: 12px;
        --max-width: 1100px;
        --sidebar-width: 240px;
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

    /* --- Sidebar Styling (Fixed) --- */
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
    .sidebar a.active i { color: white; }
    .sidebar a i {
        font-size: 18px;
        color: var(--text-light);
        transition: color 0.3s ease;
    }
    .sidebar a:hover i { color: var(--primary-blue); }
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
        color: var(--text-light);
        font-weight: 500;
        font-size: 1rem;
    }

    /* Mobile Header/Menu */
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
    #menu-toggle-open {
        background: none;
        border: none;
        font-size: 24px;
        color: var(--primary-blue);
        cursor: pointer;
    }

    /* Responsive Design */
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
        }
        .sidebar-header button {
            display: block !important;
            background: none;
            border: none;
            color: var(--text-light);
            font-size: 24px;
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
        }
    }
</style>
</head>
<body>
    <div class="mobile-header" id="mobileHeader">
        <button id="menu-toggle-open"><i class="fas fa-bars"></i></button>
        <div class="mobile-logo" style="font-size: 22px; font-weight: 700; color: var(--primary-blue);">DentiTrack</div>
        <div style="width: 24px;"></div> 
    </div>

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <i class="fas fa-tooth"></i> DentiTrack
            <button id="menu-toggle-close" style="display: none;"><i class="fas fa-times"></i></button>
        </div>
        <nav class="sidebar-nav">
            <a href="patient_dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
            <a href="patient_appointments.php" class="active"><i class="fas fa-calendar-check"></i> Appointments</a>
            <a href="patient_records.php"><i class="fas fa-file-medical"></i> Dental Records</a>
            <a href="patient_payment.php"><i class="fas fa-credit-card"></i> Payments</a>
            <a href="Profile.php"><i class="fas fa-user"></i> Profile</a>
        </nav>
        <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </aside>

    <main class="main-content">
        <header>
            <h1><i class="fas fa-calendar-check"></i> Request Consultation</h1>
            <div class="welcome">Welcome, <?= htmlspecialchars($patient_username); ?></div>
        </header>

        <script>
            // Passing the preselected service ID to JavaScript for the calendar script
            const PRESELECTED_SERVICE_ID = <?php echo $preselected_service_id; ?>;
        </script>

        <?php include '../calendar_function/patient_calendar.php'; ?>
    </main>
    
    <script>
        // MOBILE MENU TOGGLE LOGIC
        document.addEventListener('DOMContentLoaded', function() {
            if (window.innerWidth < 992) {
                const sidebar = document.getElementById('sidebar');
                const menuToggleOpen = document.getElementById('menu-toggle-open');
                const menuToggleClose = document.getElementById('menu-toggle-close');

                if (menuToggleOpen && sidebar) {
                    // Ensure the close button inside the sidebar-header is visible on mobile open
                    menuToggleClose.style.display = 'block';

                    menuToggleOpen.addEventListener('click', function() {
                        sidebar.classList.add('open');
                        document.body.style.overflow = 'hidden'; 
                    });
                    
                    menuToggleClose.addEventListener('click', function() {
                        sidebar.classList.remove('open');
                        document.body.style.overflow = ''; 
                    });
                    
                    // Auto-close menu when a link is clicked
                    document.querySelectorAll('.sidebar a').forEach(link => {
                        link.addEventListener('click', function() {
                            setTimeout(() => {
                                sidebar.classList.remove('open');
                                document.body.style.overflow = '';
                            }, 300);
                        });
                    });
                }
            }
        });
    </script>
</body>
</html>