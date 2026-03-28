<?php
// File: patient_dashboard.php

// Ensure session is started before using $_SESSION
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') {
    header("Location: ../public/login.php");
    exit;
}

// Database connection
// Assuming this file establishes a $conn object using mysqli
include '../config/db.php'; 

$user_id = $_SESSION['user_id'];

// --- DEBUG FUNCTION TO CATCH ERRORS IMMEDIATELY ---
function check_stmt_error($conn, $stmt, $context) {
    if ($stmt === false) {
        // Use a more robust error message in production, but for development, this helps.
        die("<h3>Fatal SQL Error in $context:</h3>" . htmlspecialchars($conn->error));
    }
}

// FETCH PATIENT ID (Required for joins to patient tables like dental_records, payments)
$patient_data = [];
$sql_patient_id = "SELECT patient_id, first_name, last_name, fullname FROM patient WHERE user_id = ?";
$stmt = $conn->prepare($sql_patient_id);
check_stmt_error($conn, $stmt, 'Patient ID Fetch');
$stmt->bind_param("i", $user_id);
$stmt->execute();
$patient_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

$patient_id = $patient_data['patient_id'] ?? 0;

$full_name = ($patient_data)
    ? htmlspecialchars($patient_data['fullname'])
    : htmlspecialchars($_SESSION['username'] ?? "Patient");

// --- WIDGET DATA QUERIES (Using $patient_id for patient-specific data) ---

// 1. FETCH NEXT APPOINTMENT
$next_appointment = null;
if ($patient_id) {
    $sql_next_appointment = "SELECT appointment_date, appointment_time, status 
                             FROM appointments 
                             WHERE patient_id = ? AND appointment_date >= CURDATE() 
                             ORDER BY appointment_date ASC LIMIT 1";
    $stmt = $conn->prepare($sql_next_appointment);
    check_stmt_error($conn, $stmt, 'Next Appointment Fetch');
    // Using $patient_id for tables linked to the 'patient' primary key
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $next_appointment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// 2. COUNT UPCOMING APPOINTMENTS
$upcoming_count = 0;
if ($patient_id) {
    $sql_upcoming_count = "SELECT COUNT(*) as count 
                             FROM appointments 
                             WHERE patient_id = ? AND appointment_date >= CURDATE()";
    $stmt = $conn->prepare($sql_upcoming_count);
    check_stmt_error($conn, $stmt, 'Upcoming Appointments Count');
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $upcoming_count = $stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();
}


// 3. FETCH AND COUNT ANNOUNCEMENTS (Clinic-wide)
$announcements = [];
$announcements_count = 0;
$sql_announcements = "SELECT * FROM announcements ORDER BY posted_at DESC";
$result = $conn->query($sql_announcements);
if ($result) {
    $announcements_count = $result->num_rows;
    while ($row = $result->fetch_assoc()) {
        $announcements[] = $row;
    }
}

// 4. FETCH LAST DENTAL RECORD (Using $patient_id)
$last_record = null;
$records_count = 0;
if ($patient_id) {
    $sql_last_record = "SELECT record_date 
                          FROM dental_records 
                          WHERE patient_id = ? 
                          ORDER BY record_date DESC LIMIT 1";
    $stmt = $conn->prepare($sql_last_record);
    check_stmt_error($conn, $stmt, 'Last Dental Record Fetch');
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $last_record = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // COUNT DENTAL RECORDS
    $sql_records_count = "SELECT COUNT(*) AS count 
                          FROM dental_records 
                          WHERE patient_id = ?";
    $stmt = $conn->prepare($sql_records_count);
    check_stmt_error($conn, $stmt, 'Dental Records Count');
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $records_count = $stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();
}


// 5. COUNT DOCTOR UPLOADS (Using $patient_id)
$uploads_count = 0;
if ($patient_id) {
    $sql_uploads_count = "SELECT COUNT(*) AS count 
                          FROM patient_files 
                          WHERE patient_id = ?";
    $stmt = $conn->prepare($sql_uploads_count);
    check_stmt_error($conn, $stmt, 'Doctor Uploads Count');
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $uploads_count = $stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();
}

// 6. FETCH USER PAYMENT TRANSACTIONS (latest 5) (Using $patient_id)
$payments = [];
if ($patient_id) {
    $sql_payments = "SELECT payment_id, amount, payment_date, payment_method, status 
                     FROM payments 
                     WHERE patient_id = ? 
                     ORDER BY payment_date DESC 
                     LIMIT 5";
    $stmt = $conn->prepare($sql_payments);
    check_stmt_error($conn, $stmt, 'Fetch Payments');
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $payments[] = $row;
    }
    $stmt->close();
}

// 7. FETCH SERVICES (Clinic-wide)
$services = [];
$sql_services = "SELECT service_id, service_name, duration, price, description, image_path FROM services WHERE status = 'active' ORDER BY service_name ASC"; 
$result = $conn->query($sql_services);
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $services[] = $row;
    }
}

// Close the main connection
$conn->close(); 
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Patient Dashboard - DentiTrack</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
<style>
/* --- STYLES ALIGNED WITH PREVIOUS RESPONSIVE DESIGN --- */
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
    --max-width: 1200px;
    --sidebar-width: 250px;
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

/* --- Sidebar Styling (Fixed & Responsive) --- */
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
    font-size: 24px;
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
    max-width: 100%;
}
header h1 {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 2rem;
    color: var(--text-dark);
    font-weight: 700;
    border-bottom: 2px solid #e0e0e0;
    padding-bottom: 10px;
    margin-bottom: 20px;
}
.welcome {
    font-size: 1.2rem;
    margin-bottom: 30px;
    color: var(--text-light);
    font-weight: 400;
}
.welcome strong { font-weight: 700; color: var(--text-dark); }

/* WIDGETS */
.widgets {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 25px;
    margin-bottom: 50px;
}
.widget {
    background: var(--widget-bg);
    padding: 30px;
    border-radius: 12px;
    border: 1px solid #dcdcdc;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    cursor: pointer;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}
.widget:hover { transform: translateY(-5px); box-shadow: 0 8px 20px rgba(0,0,0,0.15); }

/* Specific widget icons */
.widget i {
    font-size: 40px;
    margin-bottom: 15px;
    color: white;
    padding: 15px;
    border-radius: 50%;
    display: inline-block;
}
.widget i.fa-calendar-alt { background: #007bff; }
.widget i.fa-file-medical { background: #28a745; }
.widget i.fa-bullhorn { background: #ffc107; }
.widget i.fa-receipt { background: #dc3545; }

.widget .title { font-size: 1rem; color: var(--text-light); font-weight: 500; margin-bottom: 5px; }
.widget .value { font-size: 2.5rem; font-weight: 700; color: var(--primary-blue); line-height: 1; margin-bottom: 10px; }
.widget .subtitle { font-size: 0.9rem; color: var(--text-dark); border-top: 1px solid #f0f0f0; padding-top: 10px; }
.widget .subtitle ul { list-style:none; padding-left:0; margin:0; font-size:0.9rem; }

/* SERVICES SECTION */
.services-section {
    margin-top: 60px;
    padding: 30px;
    background: var(--widget-bg);
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
}
.services-title {
    font-size: 1.8rem;
    color: var(--primary-blue);
    font-weight: 700;
    text-align: center;
    margin-bottom: 30px;
}
.services-wrapper {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}
.service-card-link { text-decoration: none; display: block; }
.service-card {
    background: #f8f9fa;
    padding: 0;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    border: 1px solid #eee;
    transition: 0.3s;
    overflow: hidden;
    height: 100%;
}
.service-card:hover { transform: translateY(-3px); box-shadow: 0 8px 15px rgba(0,0,0,0.15); border-color: var(--primary-blue); }
.service-card img { width: 100%; height: 160px; object-fit: cover; border-radius: 10px 10px 0 0; }
.service-content { padding: 15px; }
.service-title { font-size: 1.1rem; font-weight: 700; color: var(--text-dark); margin-bottom: 5px; }
.service-desc { font-size: 0.85rem; color: var(--text-light); line-height: 1.4; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; }

/* ANNOUNCEMENT SPECIFIC STYLES */
.announcement-board {
    max-width: var(--max-width);
    margin: 40px 0;
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.announcement-card {
    background: var(--widget-bg);
    border-radius: var(--radius);
    box-shadow: 0 4px 10px rgba(0,0,0,0.05);
    padding: 20px;
    border-left: 6px solid var(--primary-blue);
    transition: box-shadow 0.2s ease;
}

.announcement-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    border-bottom: 1px solid #f0f0f0;
    padding-bottom: 10px;
    margin-bottom: 10px;
}
.announcement-header h2 {
    font-size: 1.2rem;
    color: var(--primary-blue);
    margin: 0;
}
.announcement-header span {
    font-size: 0.8rem;
    color: var(--text-light);
}

.announcement-content {
    line-height: 1.5;
    color: var(--text-dark);
}
.announcement-image {
    margin-top: 15px;
    text-align: center;
}
.announcement-image img {
    max-width: 100%;
    height: auto;
    border-radius: 8px;
}
.no-announcement {
    text-align: center;
    background: #ffffff;
    padding: 30px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    font-size: 1.1rem;
    color: var(--text-light);
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
.mobile-logo {
    font-size: 24px;
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
        cursor: pointer;
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
    .services-section {
        padding: 20px;
    }
    .services-title {
        font-size: 1.5rem;
    }
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

<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <i class="fas fa-tooth"></i> DentiTrack
        <button id="menu-toggle-close" style="display: none;"><i class="fas fa-times"></i></button>
    </div>
    <nav class="sidebar-nav">
        <a href="patient_dashboard.php" class="active"><i class="fas fa-home"></i> Dashboard</a>
        <a href="patient_appointments.php"><i class="fas fa-calendar-check"></i> Appointments</a>
        <a href="patient_records.php"><i class="fas fa-file-medical"></i> Dental Records</a>
        <a href="patient_payment.php"><i class="fas fa-credit-card"></i> Payments</a>
        <a href="Profile.php"><i class="fas fa-user"></i> Profile</a>
    </nav>
    <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
</aside>

<main class="main-content">

<header>
    <h1><i class="fas fa-chart-line"></i> Patient Dashboard</h1>
</header>

<p class="welcome">Welcome back, <strong><?= $full_name ?></strong>! We hope you're smiling today.</p>

<div class="widgets">

    <div class="widget" onclick="location.href='patient_appointments.php'">
        <i class="fas fa-calendar-alt"></i>
        <div class="title">Upcoming Appointments</div>
        <div class="value"><?= $upcoming_count ?></div>
        <?php if ($next_appointment): ?>
            <div class="subtitle">
                Next: <strong><?= htmlspecialchars($next_appointment['appointment_date']) ?></strong> @ <?= htmlspecialchars($next_appointment['appointment_time']) ?>
            </div>
        <?php else: ?>
            <div class="subtitle">No scheduled appointments</div>
        <?php endif; ?>
    </div>

    <div class="widget" onclick="location.href='patient_records.php'">
        <i class="fas fa-file-medical"></i>
        <div class="title">Total Dental Records</div>
        <div class="value"><?= $records_count + $uploads_count ?></div>
        <div class="subtitle">
            Records: <strong><?= $records_count ?></strong> | Files: <strong><?= $uploads_count ?></strong> | Last record: <strong><?= $last_record ? htmlspecialchars($last_record['record_date']) : "N/A" ?></strong>
        </div>
    </div>

    <div class="widget" onclick="location.href='#announcements-board'">
        <i class="fas fa-bullhorn"></i>
        <div class="title">Clinic Announcements</div>
        <div class="value"><?= $announcements_count ?></div>
        <div class="subtitle">
            Stay updated with clinic news!
        </div>
    </div>

    <div class="widget">
        <i class="fas fa-receipt"></i>
        <div class="title">Recent Payments</div>
        <div class="subtitle">
            <?php if (!empty($payments)): ?>
                <ul style="list-style:none; padding-left:0; margin:0; font-size:0.9rem;">
                    <?php foreach ($payments as $pay): ?>
                        <li>
                            <?= htmlspecialchars($pay['payment_date']) ?> - 
                            ₱<?= number_format($pay['amount'], 2) ?> 
                            <span style="color:<?= $pay['status'] == 'paid' || $pay['status'] == 'completed' ? 'var(--success-green)' : 'var(--alert-red)' ?>;">(<?= htmlspecialchars($pay['status']) ?>)</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                No payments recorded yet.
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="announcement-board" id="announcements-board">
    <div class="inclusion-container">
        <h3><i class="fas fa-bullhorn"></i> Latest Announcements</h3>
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
                        <span><i class="fas fa-user"></i> <?= htmlspecialchars($announcement['posted_by']) ?> &nbsp;|&nbsp; <i class="fas fa-clock"></i> <?= date('F j, Y g:i A', strtotime($announcement['posted_at'])) ?></span>
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
    </div>
</div>


<div class="services-section" id="services-section">
    <div class="services-title">Explore Our Dental Services 🦷</div>
    <div class="services-wrapper">
        <?php if (!empty($services)): ?>
            <?php foreach ($services as $srv): ?>
                <a href="../patient/patient_appointments.php?service_id=<?= htmlspecialchars($srv['service_id']) ?>" class="service-card-link">
                    <div class="service-card">
                        <?php 
                        // Assuming image_path is relative to the project root or accessible via ../
                        $image_src = !empty($srv['image_path']) ? "../" . htmlspecialchars($srv['image_path']) : "../assets/default_service.jpg";
                        ?>
                        <img src="<?= $image_src ?>" alt="<?= htmlspecialchars($srv['service_name']) ?> Image" onerror="this.onerror=null;this.src='../assets/default_service.jpg';">
                        <div class="service-content">
                            <div class="service-title"><?= htmlspecialchars($srv['service_name']) ?> (₱<?= number_format($srv['price'], 2) ?>)</div>
                            <div class="service-desc"><?= htmlspecialchars($srv['description']) ?></div>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="text-align:center; color:var(--text-light); font-size: 1.1rem; grid-column: 1 / -1;">
                No active services available at the moment. Please check back later!
            </p>
        <?php endif; ?>
    </div>
</div>

</main>

<script>
// MOBILE MENU TOGGLE LOGIC (Aligned with previous file structure)
document.addEventListener('DOMContentLoaded', function() {
    // Hide the close button initially on the mobile sidebar header
    const closeBtn = document.getElementById('menu-toggle-close');
    if (closeBtn) {
        closeBtn.style.display = 'none';
    }

    if (window.innerWidth < 992) {
        const sidebar = document.getElementById('sidebar');
        const menuToggleOpen = document.getElementById('menu-toggle-open');

        if (menuToggleOpen && sidebar) {
            // Function to open the sidebar
            menuToggleOpen.addEventListener('click', function() {
                sidebar.classList.add('open');
                if (closeBtn) closeBtn.style.display = 'block';
                document.body.style.overflow = 'hidden'; 
            });
            
            // Function to close the sidebar
            if (closeBtn) {
                closeBtn.addEventListener('click', function() {
                    sidebar.classList.remove('open');
                    closeBtn.style.display = 'none';
                    document.body.style.overflow = ''; 
                });
            }
            
            // Auto-close menu when a link is clicked
            document.querySelectorAll('.sidebar a').forEach(link => {
                link.addEventListener('click', function() {
                    setTimeout(() => {
                        sidebar.classList.remove('open');
                        if (closeBtn) closeBtn.style.display = 'none';
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