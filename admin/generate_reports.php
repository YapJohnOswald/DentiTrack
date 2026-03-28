<?php
session_start();
// Security check: Redirect non-admins
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    exit("Unauthorized");
}

include '../config/db.php';
// NOTE: Make sure 'settings_loader.php' contains the getSetting() function
include '../config/settings_loader.php'; 

$admin_username = $_SESSION['username'];

// Fetch unique services for dropdowns (Appointments) - REVISED
// Now joins 'appointments' with 'services' table on service_id
$services_result = $conn->query("
    SELECT DISTINCT s.service_name AS service 
    FROM appointments a
    JOIN services s ON a.service_id = s.service_id
    ORDER BY service ASC
");
$services = $services_result ? $services_result->fetch_all(MYSQLI_ASSOC) : [];

// Fetch unique patients for dropdowns (Appointments) - REVISED
// Now joins 'appointments' with 'patient' table on patient_id to get 'fullname'
$patients_result = $conn->query("
    SELECT DISTINCT p.fullname AS patient_name 
    FROM appointments a
    JOIN patient p ON a.patient_id = p.patient_id
    ORDER BY patient_name ASC
");
$patients = $patients_result ? $patients_result->fetch_all(MYSQLI_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Generate Reports - DentiTrack</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
    --radius: 12px;
}

/* --- Global & Layout Styles --- */
html { scroll-behavior: smooth; }
body { 
    margin:0; 
    font-family: 'Inter', sans-serif; 
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
    -ms-overflow-style: none; /* Auto-hidden scrollbar */
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

/* REMOVING the default .sidebar .logout styling as it's now outside the nav */
.logout-container {
    position: absolute;
    top: 15px;
    right: 40px;
    z-index: 990; /* Below mobile header, above content */
}
.logout-btn {
    padding: 10px 18px;
    border-radius: 6px;
    background: var(--alert-red);
    color: white;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: background 0.3s;
}
.logout-btn:hover {
    background: #c82333;
}


/* --- Main Content --- */
main.main-content { 
    flex:1; 
    padding:40px; 
    margin-left: var(--sidebar-width);
    background: var(--bg-page); 
    overflow-y:auto; 
    box-sizing:border-box; 
    position: relative;
}
header h1 { 
    font-size:1.8rem; 
    color:var(--secondary-blue); 
    display:flex; 
    align-items:center; 
    gap:10px; 
    border-bottom: 2px solid #e0e0e0;
    padding-bottom: 10px;
    margin-bottom: 20px;
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

/* Report form - Use grid for better layout */
form.report-form { 
    background:var(--widget-bg); padding:25px; border-radius:var(--radius); margin-bottom:25px; 
    box-shadow:0 6px 15px rgba(0,0,0,0.08);
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
}
.form-group { display: flex; flex-direction: column; }
.form-group label { margin-top:0px; font-weight:600; color:var(--secondary-blue); font-size: 14px;}
.form-group select, .form-group input { 
    padding:8px; width:100%; margin-top:5px; border-radius:6px; 
    border:1px solid #ced4da; background: #f9fcff;
    box-sizing: border-box;
}

/* Specific filters for appointments */
#appointmentFilters {
    grid-column: 1 / -1; /* Make it span full width */
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    padding-top: 10px;
    border-top: 1px dashed #eee;
}
.search-input-container {
    grid-column: span 2; 
}
.clear-button-container {
    align-self: flex-end; 
}

/* --- ANALYTICS STYLES --- */
#analyticsSummary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 25px;
}
.summary-card {
    background: var(--widget-bg);
    padding: 18px;
    border-radius: 12px;
    box-shadow: 0 4px 10px rgba(0,0,0,0.08);
    text-align: center;
    border-left: 5px solid var(--primary-blue); 
    transition: transform 0.2s;
}
.summary-card:hover {
    transform: translateY(-3px);
}
.summary-card h4 {
    margin: 0 0 5px 0;
    font-size: 14px;
    color: var(--text-light);
    font-weight: 500;
}
.summary-card p {
    margin: 0;
    font-size: 2.2rem;
    font-weight: 700;
    color: var(--text-dark);
}
.card-red { border-left-color: var(--alert-red); }
.card-green { border-left-color: var(--success-green); }
.card-blue { border-left-color: var(--primary-blue); }
.card-orange { border-left-color: var(--accent-orange); }

/* Table */
#reportPreview table { width:100%; border-collapse: separate; border-spacing: 0 5px; background:var(--widget-bg); border-radius:10px; overflow:hidden; box-shadow:0 5px 20px rgba(0,0,0,0.08);}
#reportPreview th, #reportPreview td { padding:12px 15px; border-bottom:1px solid #f0f0f0; text-align:left; font-size:14px;}
#reportPreview th { background:var(--primary-blue); color:white; font-weight:600;} 
#reportPreview tr:hover { background:#f5f8fc; }

/* Buttons */
.btn-group {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-bottom: 20px;
}
.btn { 
    padding:10px 18px; 
    border-radius:6px; 
    border:none; 
    cursor:pointer; 
    font-weight:600; 
    transition: background 0.3s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.btn-primary { background:var(--primary-blue); color:white;}
.btn-primary:hover { background:var(--secondary-blue); }
.btn-secondary { background:var(--text-light); color:white;}
.btn-secondary:hover { background:#5a6268; }
.btn-danger { background:var(--alert-red); color:white; padding: 10px 15px;}
.btn-danger:hover { background:#c82333; }
.btn-success { background:var(--success-green); color:white; padding: 10px 15px;}
.btn-success:hover { background:#1e7e34; }

/* --- Mobile Header/Menu Styles --- */
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
/* Re-enable close button in sidebar header for mobile */
.sidebar-header button { display: none !important; }


/* --- Responsive Design --- */
@media (max-width: 992px) {
    .mobile-header { display: flex; }
    main.main-content { margin-left: 0; padding: 20px; padding-top: 80px; }
    
    .sidebar { width: 80%; max-width: 300px; transform: translateX(-100%); z-index: 1050; height: 100vh; }
    .sidebar.open { transform: translateX(0); box-shadow: 5px 0 15px rgba(0,0,0,0.2); }
    .sidebar-header { justify-content: space-between; display: flex; align-items: center; }
    .sidebar-header button { display: block !important; }
    .sidebar-nav { padding: 0; overflow-y: auto !important; }
    .sidebar a { margin: 0; border-radius: 0; padding: 15px 30px; border-bottom: 1px solid #f0f0f0; }
    
    /* Remove desktop logout button from view and ensure mobile button is styled */
    .logout-container { display: none; }
    .sidebar .logout { 
        padding: 15px 30px; margin: 0; margin-top: 10px; border-radius: 0; 
        background: #fce8e8; color: var(--alert-red);
        /* Mobile menu links are generally block elements, this ensures it stacks correctly */
        width: 100%; 
    }
    
    .report-form { grid-template-columns: 1fr; gap: 15px; }
    #appointmentFilters { grid-template-columns: 1fr; }
    .search-input-container { grid-column: span 1; }
    .clear-button-container { align-self: flex-start; }
    .btn-group { justify-content: space-between; flex-wrap: wrap; }
    .btn { flex-grow: 1; margin-top: 10px; }

    /* Fix for table on mobile: allow horizontal scroll of report table */
    #reportPreview { overflow-x: auto; }
    #reportPreview table { min-width: 600px; }
}

/* Hide elements when printing */
@media print {
    body { background: white; }
    .sidebar, .btn-group, .report-form, #analyticsSummary, .mobile-header, .logout-container { display: none; }
    main.main-content { padding: 20px; margin: 0; }
    #reportPreview { box-shadow: none; }
    #reportPreview th { background:#f1f1f1 !important; color:#333 !important; }
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
        <i class="fas fa-tooth"></i> <?= htmlspecialchars(getSetting('system_name','DentiTrack')) ?>
        <button id="menu-toggle-close" style="display: none;"><i class="fas fa-times"></i></button>
    </div>
    <div class="sidebar-nav">
        <a href="admin_dashboard.php" ><i class="fas fa-home"></i> Dashboard</a>
        <a href="manage_accounts.php"><i class="fas fa-users-cog"></i> Manage Accounts</a>
        <a href="clinic_services_admin.php"><i class="fas fa-tools"></i> Clinic Services</a>
        <a href="generate_reports.php" class="active"><i class="fas fa-chart-line"></i> Generate Reports</a>
        <a href="payment_module.php"><i class="fas fa-money-check-dollar"></i> Payment Module</a>
        <a href="clinic_schedule_admin.php"><i class="fas fa-calendar-check"></i> Clinic Schedule</a>
        <a href="admin_settings.php"><i class="fas fa-gear"></i> System Settings</a>
    </div>
    <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
</nav>



<main class="main-content">
    <header>
        <h1><i class="fas fa-chart-line"></i> Reporting</h1>
    </header>
    <p>Welcome, <strong><?= htmlspecialchars($admin_username) ?></strong></p>

    <form class="report-form" id="reportForm">
        <div class="form-group">
            <label for="report_category">Report Category</label>
            <select name="report_category" id="report_category">
                <option value="appointments">Appointments</option>
                <option value="dental_supplies">Dental Supplies</option>
                <option value="activity_log">User Activity Log</option>
            </select>
        </div>
        
        <div class="form-group">
            <label for="start_date">Start Date</label>
            <input type="date" name="start_date" id="start_date">
        </div>
        
        <div class="form-group">
            <label for="end_date">End Date</label>
            <input type="date" name="end_date" id="end_date">
        </div>

        <div class="form-group search-input-container">
            <label for="global_search"><i class="fas fa-magnifying-glass"></i> Search Report Data</label>
            <input type="text" id="global_search" name="global_search" placeholder="Filter by Name, ID, or Keyword..." autocomplete="off">
        </div>
        
        <div id="appointmentFilters">
            <div class="form-group">
                <label for="search_patient">Patient</label>
                <select name="search_patient" id="search_patient">
                    <option value="">All Patients</option>
                    <?php foreach($patients as $p): ?>
                        <option value="<?= htmlspecialchars($p['patient_name']) ?>"><?= htmlspecialchars($p['patient_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="search_service">Service</label>
                <select name="search_service" id="search_service">
                    <option value="">All Services</option>
                    <?php foreach($services as $s): ?>
                        <option value="<?= htmlspecialchars($s['service']) ?>"><?= htmlspecialchars($s['service']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="search_status">Status</label>
                <select name="search_status" id="search_status">
                    <option value="">All</option>
                    <option value="scheduled">Scheduled</option>
                    <option value="completed">Completed</option>
                    <option value="pending">Pending</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>
            <div class="form-group clear-button-container">
                <button type="button" class="btn btn-danger" id="clearFiltersBtn"><i class="fas fa-eraser"></i> Clear All Filters</button>
            </div>
        </div>
        
    </form>
    
    <div class="btn-group">
        <button onclick="window.print()" class="btn btn-secondary"><i class="fas fa-print"></i> Print Report</button>
        <button onclick="exportCSV()" class="btn btn-primary"><i class="fas fa-file-csv"></i> Export to CSV</button>
    </div>

    <div id="analyticsSummary">
    </div>
    
    <div id="reportPreview">
            <p style="text-align: center; color: #555; margin-top: 50px;">Select a report category or apply filters to view data.</p>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const reportForm = document.getElementById('reportForm');
    const categorySelect = document.getElementById('report_category');
    const appointmentFilters = document.getElementById('appointmentFilters');
    const previewDiv = document.getElementById('reportPreview');
    const analyticsDiv = document.getElementById('analyticsSummary'); 
    const clearFiltersBtn = document.getElementById('clearFiltersBtn');
    
    const sidebar = document.getElementById('sidebar');
    const menuToggleOpen = document.getElementById('menu-toggle-open');
    const menuToggleClose = document.getElementById('menu-toggle-close');
    const desktopLogout = document.querySelector('.logout-container');

    // Debounce function (unchanged)
    const debounce = (func, delay) => {
        let timeoutId;
        return function(...args) {
            clearTimeout(timeoutId);
            timeoutId = setTimeout(() => func.apply(this, args), delay);
        };
    };

    function updateFilterVisibility(category) {
        appointmentFilters.style.display = (category === 'appointments') ? 'grid' : 'none';
    }

    function fetchReports() {
        const formData = new FormData(reportForm);
        
        // Clear both sections and show loader
        analyticsDiv.innerHTML = '';
        previewDiv.innerHTML = '<p style="text-align: center; color: #007bff; padding: 50px 0;"><i class="fas fa-spinner fa-spin fa-2x"></i><br>Loading data from database...</p>';

        fetch('fetch_reports.php', { 
            method:'POST', 
            body:formData 
        })
        .then(res => res.json()) // IMPORTANT: Expect JSON response now
        .then(data => {
            // Display Analytics and Report HTML from the JSON object
            analyticsDiv.innerHTML = data.analytics_html;
            previewDiv.innerHTML = data.report_html;
        })
        .catch(error => {
            previewDiv.innerHTML = '<p style="text-align: center; color: red; padding: 50px 0;"><i class="fas fa-exclamation-triangle"></i> Error fetching report data. Check console for details.</p>';
            console.error('Fetch error:', error);
        });
    }

    // Debounced version of fetchReports for global search (unchanged)
    const debouncedFetchReports = debounce(fetchReports, 300); 

    function clearFilters() {
        reportForm.reset();
        updateFilterVisibility(categorySelect.value); 
        fetchReports();
    }

    // --- Event Listeners ---
    reportForm.querySelectorAll('select, input[type="date"]').forEach(el => {
        el.addEventListener('change', fetchReports);
    });
    
    document.getElementById('global_search').addEventListener('input', debouncedFetchReports);

    categorySelect.addEventListener('change', function() {
        updateFilterVisibility(this.value);
        fetchReports();
    });

    clearFiltersBtn.addEventListener('click', clearFilters);
    
    reportForm.addEventListener('submit', (e) => e.preventDefault());

    // Initial load
    updateFilterVisibility(categorySelect.value);
    fetchReports();


    // --- MOBILE MENU TOGGLE LOGIC ---
    function setupMobileMenu() {
        const isMobile = window.innerWidth < 992;
        
        // Toggle desktop/mobile logout visibility
        if (desktopLogout) {
            desktopLogout.style.display = isMobile ? 'none' : 'block';
        }

        if (isMobile) {
            // Initial state: hide close button
            if (menuToggleClose) menuToggleClose.style.display = 'none';

            if (menuToggleOpen && sidebar) {
                menuToggleOpen.addEventListener('click', function() {
                    sidebar.classList.add('open');
                    if (menuToggleClose) menuToggleClose.style.display = 'block';
                    document.body.style.overflow = 'hidden'; 
                });
                
                if (menuToggleClose) {
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
        } else {
            // Desktop reset
            if (menuToggleClose) menuToggleClose.style.display = 'none';
        }
    }
    
    setupMobileMenu();
    window.addEventListener('resize', setupMobileMenu);

});

// CSV Export Function (Client-side logic - unchanged)
function exportCSV() {
    const table = document.querySelector('#reportPreview table');
    if(!table) return alert("No tabular data available to export! Please generate a report first.");

    let csv = [];
    const rows = table.querySelectorAll('tr');

    for (const row of rows) {
        const cells = row.querySelectorAll('th, td');
        const rowData = [];
        for (const cell of cells) {
            let text = cell.textContent.trim();
            if (text.includes(',')) text = `"${text}"`;
            rowData.push(text);
        }
        csv.push(rowData.join(','));
    }

    const csvFile = new Blob([csv.join('\n')], { type: 'text/csv' });
    const downloadLink = document.createElement('a');
    downloadLink.download = `DentiTrack_Report_${document.getElementById('report_category').value}_${new Date().toISOString().slice(0, 10)}.csv`;
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = 'none';
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}
</script>
</body>
</html>