<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: admin_login.php');
    exit();
}

include '../config/db.php'; // expects $conn (mysqli)

// helper
function safe($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

// Initialize defaults for safety
$total_users_count = 0;
$admin_count = 0;
$upcoming_appointments = 0;
$total_revenue = 0;
$admin_username = isset($_SESSION['username']) ? $_SESSION['username'] : 'Admin';


if ($conn) {
    // 1) Users counts
    $sql = "SELECT 
                SUM(role IN ('patient','secretary','doctor')) AS total_users_count, 
                SUM(role = 'admin') AS admin_count
            FROM users";
    if ($res = $conn->query($sql)) {
        $row = $res->fetch_assoc();
        // NOTE: The original logic counts non-admins as 'total users', using that for the widget value.
        $total_users_count = (int)($row['total_users_count'] ?? 0);
        $admin_count = (int)($row['admin_count'] ?? 0);
        $res->free();
    }

    // 3) Upcoming appointments (from today)
    $sql = "SELECT COUNT(*) AS cnt FROM appointments WHERE appointment_date >= CURDATE()";
    if ($res = $conn->query($sql)) {
        $row = $res->fetch_assoc();
        $upcoming_appointments = (int)($row['cnt'] ?? 0);
        $res->free();
    }

    // 4) Total revenue (payments) - adjust column/table names if your schema differs
    // assumes payments.amount and payments.status (paid/completed)
    $sql = "SELECT IFNULL(SUM(amount),0) AS total_revenue FROM payments WHERE status IN ('paid','completed')";
    if ($res = $conn->query($sql)) {
        $row = $res->fetch_assoc();
        $total_revenue = (float)($row['total_revenue'] ?? 0);
        $res->free();
    }

    // 6) Top services/procedures in last 30 days based on appointments count
    $sql = "SELECT s.name AS service_name, COUNT(a.id) AS cnt
            FROM appointments a
            LEFT JOIN services s ON a.service_id = s.id
            WHERE a.service_id IS NOT NULL 
            -- WHERE a.appointment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) -- (You can uncomment this line if you still want the 30-day filter)
            GROUP BY s.id, s.name
            ORDER BY cnt DESC
            LIMIT 5";
            
    // The original code did not fetch the top services result set, so we won't display it, but keep the query space.
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard - DentiTrack</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
/* --- CSS Variables for a clean, consistent color scheme --- */
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
    --mobile-header-height: 60px;
}

/* --- Global & Layout Styles --- */
html { scroll-behavior: smooth; }
body { 
    margin:0; 
    font-family: 'Inter', sans-serif; /* Standardized font */
    color: var(--text-dark); 
    display:flex; 
    min-height:100vh; 
    background: var(--bg-page);
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
    -ms-overflow-style: none; /* Hidden scrollbar */
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
.sidebar a.active i { color: white; }

/* Specific style for Logout button (MOBILE VIEW ONLY) */
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
.sidebar .logout:hover {
    background: #fce8e8;
    color: var(--alert-red);
}

/* --- Main Content --- */
main.main-content { 
    flex:1; 
    padding:40px; 
    margin-left: var(--sidebar-width);
    background: var(--bg-page); 
    overflow-y:auto; 
    box-sizing:border-box; 
    position: relative; /* Needed for desktop logout positioning */
}
header {
    border-bottom: 2px solid #e0e0e0;
    padding-bottom: 10px;
    margin-bottom: 30px;
}
header h1 { 
    font-size: 1.8rem;
    color: var(--secondary-blue);
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 700;
}
main.main-content p {
    font-size: 1rem;
    color: var(--text-light);
    margin-bottom: 25px;
}
main.main-content strong {
    color: var(--primary-blue);
}

/* --- LOGOUT ALIGNMENT OUTSIDE SIDEBAR (DESKTOP) --- */
.logout-desktop-container {
    position: absolute;
    top: 30px; 
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


.widgets {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); 
    gap: 20px;
    margin-bottom: 40px;
}

.widget {
    background: var(--widget-bg);
    padding: 25px;
    border-radius: 10px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    text-align: left;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    flex-direction: column;
    gap: 8px;
    border-left: 5px solid var(--primary-blue);
}
.widget:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.1);
    background: #f1f7ff;
}

.widget i {
    font-size: 38px;
    color: var(--primary-blue);
    margin-bottom: 10px;
}
.widget .title {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--text-light);
}
.widget .value {
    font-size: 2.5rem;
    font-weight: 800;
    color: var(--text-dark);
    line-height: 1;
}
.widget .subtitle {
    font-size: 0.8rem;
    color: var(--text-light);
    border-top: 1px solid #f0f0f0;
    padding-top: 10px;
}

.chart-container-wrapper {
    background: var(--widget-bg);
    padding: 25px;
    border-radius: 10px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    margin-bottom: 20px;
}
.chart-container-wrapper h4 {
    color: var(--secondary-blue);
    border-bottom: 1px dashed #e9ecef;
    padding-bottom: 10px;
    margin-top: 0;
    font-size: 1.2rem;
}

/* Chart filter styles */
.chart-filter {
    margin-bottom: 20px;
}
.chart-filter label {
    font-weight: 600;
    margin-right: 5px;
    color: var(--text-dark);
}
.chart-filter input {
    padding: 8px 10px;
    margin-right: 10px;
    border-radius: 6px;
    border: 1px solid #ced4da;
}
.chart-filter button {
    padding: 8px 15px;
    border: none;
    border-radius: 6px;
    background: var(--primary-blue);
    color: white;
    cursor: pointer;
    font-weight: 600;
    transition: background 0.3s;
}
.chart-filter button:hover { background: var(--secondary-blue); }


/* --- MOBILE SPECIFIC HEADER/RESPONSIVENESS --- */
.mobile-header { 
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: var(--mobile-header-height);
    background: var(--widget-bg);
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    z-index: 1000;
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
.sidebar-header button { display: none !important; }

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
    
    .widgets { grid-template-columns: 1fr; gap: 20px; }
    
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
        <a href="admin_dashboard.php" class="active"><i class="fas fa-home"></i> Dashboard</a>
        <a href="manage_accounts.php"><i class="fas fa-users-cog"></i> Manage Accounts</a>
        <a href="clinic_services_admin.php"><i class="fas fa-tools"></i> Clinic Services</a>
        <a href="generate_reports.php"><i class="fas fa-chart-line"></i> Generate Reports</a>
        <a href="payment_module.php"><i class="fas fa-money-check-dollar"></i> Payment Module</a>
        <a href="clinic_schedule_admin.php"><i class="fas fa-calendar-check"></i> Clinic Schedule</a>
        <a href="admin_settings.php"><i class="fas fa-gear"></i> System Settings</a>
    </div>
    <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
</nav>



<main class="main-content">
    <header>
        <h1><i class="fas fa-tachometer-alt"></i> Admin Dashboard</h1>
    </header>
    <p>Welcome, <strong><?= safe($admin_username) ?></strong></p>

    <div class="widgets">
        
        <div class="widget" onclick="location.href='manage_accounts.php'">
            <i class="fas fa-users"></i>
            <div class="title">Total Users (Staff/Patients)</div>
            <div class="value"><?= $total_users_count ?></div>
            <div class="subtitle">Excludes current admin count</div>
        </div>

        <div class="widget" onclick="location.href='appointments.php'">
            <i class="fas fa-calendar-check"></i>
            <div class="title">Upcoming Appointments</div>
            <div class="value"><?= $upcoming_appointments ?></div>
            <div class="subtitle">Appointments scheduled from today</div>
        </div>

        <div class="widget" onclick="location.href='payment_module.php'">
            <i class="fas fa-sack-dollar"></i>
            <div class="title">Total Revenue</div>
            <div class="value"><?= '₱' . number_format($total_revenue, 2) ?></div>
            <div class="subtitle">Completed patient payments</div>
        </div>
        
    </div>

    <?php include '../analytics_graph/analytics_admin.php'; ?>
    
</main>

<script>
// NOTE: Chart.js is included in the header. The following functions use it.
let ctx = document.getElementById('appointmentsChart').getContext('2d');
let appointmentsChart = new Chart(ctx, {
    type: 'bar',
    data: { labels: [], datasets: [{ label: 'Appointments', data: [], backgroundColor: '#007bff' }] },
    options: { responsive:true, maintainAspectRatio: false, plugins: { legend: { display: false } }, 
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
    }
});

// Fetch initial chart (MOCK function since data link isn't fully provided)
function fetchChart(start='', end=''){
    console.log(`Fetching chart data from ${start} to ${end}. (Mocking data)`);
    
    // MOCK DATA: Replace this block with your actual AJAX call to dashboard_chart_data.php
    let mockData = {
        labels: ['Wk 1', 'Wk 2', 'Wk 3', 'Wk 4'],
        data: [25, 40, 30, 45]
    };

    appointmentsChart.data.labels = mockData.labels;
    appointmentsChart.data.datasets[0].data = mockData.data;
    appointmentsChart.update();
}

// Initial load
document.addEventListener('DOMContentLoaded', function() {
    fetchChart();

    document.getElementById('applyFilter').addEventListener('click', function(){
        const start = document.getElementById('filterStart').value;
        const end = document.getElementById('filterEnd').value;
        fetchChart(start, end);
    });

    // --- MOBILE MENU TOGGLE LOGIC ---
    // The mobile logic is applied globally via DOMContentLoaded, using responsive queries in CSS/JS
    function setupMobileMenuLogic() {
        if (window.innerWidth < 992) {
            const sidebar = document.getElementById('sidebar');
            const menuToggleOpen = document.getElementById('menu-toggle-open');
            const menuToggleClose = document.getElementById('menu-toggle-close');

            if (menuToggleOpen && sidebar && menuToggleClose) {
                // Initial state for mobile
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
    }
    setupMobileMenuLogic();
});
</script>
</body>
</html>