<?php

// 1. Authentication and Authorization Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'doctor') {
    header('Location: doctor_login.php');
    exit();
}

// 2. Database Connection Includes
// This file is assumed to define the global $pdo variable for the main DB.
require_once '../config/db_pdo.php'; 

$conn = $pdo; 
$doctor_username = $_SESSION['username'] ?? 'Doctor';

// Fetch announcements using PDO
try {
    $stmt = $conn->query("SELECT * FROM announcements ORDER BY posted_at DESC");
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Failed to fetch announcements: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Doctor Announcements - DentiTrack</title>
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
        border-bottom: 2px solid #e0e0e0;
        padding-bottom: 10px;
    }
    header h1 {
        font-size: 1.8rem;
        color: var(--secondary-blue);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
        font-weight: 700;
    }

    /* Announcement Board Layout */
    .announcement-board {
        max-width: 900px;
        margin: 0 auto;
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    /* Announcement Card */
    .announcement-card {
        background: var(--widget-bg);
        border-radius: 10px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.08); /* Soft shadow */
        padding: 25px;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        border-left: 5px solid var(--primary-blue); /* Accent bar */
    }
    .announcement-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 15px rgba(0,0,0,0.1);
    }

    .announcement-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        border-bottom: 1px dashed #eee;
        padding-bottom: 10px;
        margin-bottom: 15px;
    }
    .announcement-header h2 {
        font-size: 1.3rem;
        color: var(--secondary-blue);
        margin: 0;
        font-weight: 600;
    }
    .announcement-header span {
        font-size: 0.85rem;
        color: var(--text-light);
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .announcement-content {
        margin-top: 0;
        line-height: 1.5;
        color: var(--text-dark);
        background: var(--bg-page);
        border-radius: 8px;
        padding: 15px;
        font-size: 0.95rem;
    }

    .announcement-image {
        margin-top: 15px;
        display: flex;
        justify-content: center;
    }
    .announcement-image img {
        max-width: 100%;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }

    /* Empty State */
    .no-announcement {
        text-align: center;
        background: var(--widget-bg);
        padding: 40px;
        border-radius: 10px;
        box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        font-size: 1rem;
        color: var(--text-light);
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
        .announcement-board {
            padding: 0 5px;
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
            <a href="doctor_dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
            <a href="my_patients.php"><i class="fas fa-users"></i> My Patients</a>
            <a href="my_appointments.php"><i class="fas fa-calendar-check"></i> My Appointments</a>
            <a href="announcements.php" class="active"><i class="fas fa-bullhorn"></i> Announcements</a>
        </div>
        <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </nav>

    <main class="main-content">
        <header>
            <h1><i class="fas fa-bullhorn"></i> Clinic Announcements</h1>
        </header>

        <section class="announcement-board">
        <?php if (empty($announcements)): ?>
            <div class="no-announcement">
                <i class="fas fa-info-circle" style="font-size: 30px; color: var(--primary-blue);"></i>
                <p>No announcements at the moment.</p>
            </div>
        <?php else: ?>
            <?php foreach ($announcements as $announcement): ?>
                <div class="announcement-card">
                    <div class="announcement-header">
                        <h2><i class="fas fa-newspaper"></i> <?= htmlspecialchars($announcement['title']) ?></h2>
                        <span>
                            <i class="fas fa-user"></i> <?= htmlspecialchars($announcement['posted_by']) ?> 
                            &nbsp;|&nbsp; 
                            <i class="fas fa-clock"></i> <?= date('F j, Y g:i A', strtotime($announcement['posted_at'])) ?>
                        </span>
                    </div>
                    <div class="announcement-content">
                        <?= nl2br(htmlspecialchars($announcement['content'])) ?>
                    </div>
                    <?php if (!empty($announcement['image'])): ?>
                    <div class="announcement-image">
                        <img src="../uploads/announcements/<?= htmlspecialchars($announcement['image']) ?>" alt="Announcement Image">
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        </section>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const menuToggleOpen = document.getElementById('menu-toggle-open');
            const menuToggleClose = document.getElementById('menu-toggle-close');

            // --- MOBILE MENU TOGGLE ---
            if (window.innerWidth < 992) {
                
                if (menuToggleOpen && menuToggleClose) {
                    // Ensure close button is hidden on load
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