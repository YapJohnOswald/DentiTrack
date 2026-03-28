<?php
session_start();
// Security check: Redirect non-admins
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: admin_login.php');
    exit();
}

// Configuration includes (assuming paths are correct)
include '../config/db.php';
// NOTE: Make sure 'settings_loader.php' contains the getSetting() function
include '../config/settings_loader.php'; 

$admin_username = $_SESSION['username'];
$message = '';
$message_type = 'success'; // Default type for general success

// Function to safely save a setting using the provided MySQLi connection
function saveSetting($conn, $key, $value) {
    // This function encapsulates the logic for saving/updating a key-value pair in system_settings
    $stmt = $conn->prepare("
        INSERT INTO system_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $clean_value = trim($value); 
    
    // Bind parameters, execute, and close
    $stmt->bind_param("ss", $key, $clean_value);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

// Handle form submission and save settings
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // --- 1. Handle Regular Settings from $_POST ---
    $all_post_saved = true;
    
    foreach ($_POST as $key => $value) {
        // Exclude the submit button
        if ($key === 'save_settings') continue; 
        
        // Save individual settings
        if (!saveSetting($conn, $key, $value)) {
             $all_post_saved = false;
        }
    }
    
    // Finalize message based on outcomes
    if ($all_post_saved) {
        $message = "✅ All settings updated successfully!";
    } else {
        $message = "⚠️ Warning: Some settings failed to save in the database. Please check the log.";
        $message_type = 'warning';
    }
}

// --- START: New Email Settings Fetch/Defaults ---
$current_verification_msg = getSetting('email_verification_success_message');
$current_payment_details = getSetting('email_payment_details');

// Set professional default content if settings are empty or not set
if (!$current_verification_msg) {
    $current_verification_msg = "Your account has been successfully registered. You can now securely log in to your patient portal using your created credentials and proceed to our booking system to schedule your appointment.";
}
if (!$current_payment_details) {
    $current_payment_details = "To finalize your booking, a downpayment of PHP 500 is required. Please remit payment via one of the following options and reply to this email with your proof of transaction (screenshot/slip) to confirm your slot.\n\nPayment Options:\n1. Gcash: 09XXXXXXXXX (Clinic Name)\n2. BDO Bank Transfer: 001234567890 (Clinic Name)";
}
// --- END: New Email Settings Fetch/Defaults ---

// Array of common timezones for the select dropdown (Unchanged)
$timezones = [
    'America/New_York' => 'Eastern Time (US)',
    'America/Chicago' => 'Central Time (US)',
    'America/Denver' => 'Mountain Time (US)',
    'America/Los_Angeles' => 'Pacific Time (US)',
    'Europe/London' => 'London (GMT/BST)',
    'Asia/Kolkata' => 'Kolkata (IST)',
    'Asia/Manila' => 'Manila (PHT)', // Common for Philippines
    'Asia/Shanghai' => 'Shanghai (CST)',
    'Australia/Sydney' => 'Sydney (AEST/AEDT)',
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>System Settings - <?= htmlspecialchars(getSetting('system_name','DentiTrack')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<style>
/* --- CSS VARIABLES (ALIGNED) --- */
:root {
    --sidebar-width: 250px;
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
    --mobile-header-height: 60px;
}

/* --- Base Styles (ALIGNED) --- */
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

/* --- Sidebar Styling (ALIGNED) --- */
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
.sidebar .logout:hover i {
    color: var(--alert-red);
}

/* --- Main Content Styling (ALIGNED) --- */
main.main-content {
    flex: 1;
    margin-left: var(--sidebar-width); 
    padding: 40px; 
    background: var(--bg-page); 
    overflow-y: auto; 
}
main.main-content h1 {
    font-size: 1.8rem; 
    color: var(--secondary-blue); 
    font-weight: 700; 
    margin-bottom: 30px;
    display: flex;
    align-items: center;
    gap: 10px;
}

/* Alert Message Styling (ALIGNED) */
.alert {
    margin-bottom: 20px !important;
    padding: 12px 16px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 600;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    border: 1px solid;
}
.alert-success { 
    background: #e6ffed; 
    color: var(--success-green); 
    border-color: #b8e9c6; 
}
.alert-warning { 
    background: #fff3e6; 
    color: var(--accent-orange); 
    border-color: #ffcc99; 
}

/* Card Overrides */
.card { 
    border-radius: var(--radius); 
    box-shadow: 0 6px 16px rgba(0,0,0,0.08); 
    border: none;
    background-color: var(--widget-bg) !important;
}
.card h5 {
    color: var(--primary-blue);
    font-weight: 600;
    border-bottom: 1px dashed #eee;
    padding-bottom: 10px;
}

/* Form Controls (Bootstrap overrides using DentiTrack palette) */
.form-control, .form-select, .form-control-color {
    border-radius: 6px !important;
    border: 1px solid #ced4da;
    transition: border-color 0.3s, box-shadow 0.3s;
}
.form-control:focus, .form-select:focus, .form-control-color:focus {
    border-color: var(--primary-blue);
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.25) !important;
}

/* Save Button */
.btn-primary { 
    background-color: var(--primary-blue);
    border: none;
    font-weight: 600;
    transition: background 0.3s;
}
.btn-primary:hover {
    background-color: var(--secondary-blue);
}

/* --- Mobile Header/Menu Styles (for responsive behavior) --- */
.mobile-header { 
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: var(--mobile-header-height);
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
.sidebar-header button {
    display: none !important; /* Hide close button on desktop sidebar */
}


/* --- Responsive Design --- */
@media (max-width: 992px) {
    .mobile-header { display: flex; z-index: 1050; }
    main.main-content { 
        margin-left: 0; 
        padding: 20px; 
        padding-top: calc(20px + var(--mobile-header-height)); 
    }
    
    /* Sidebar Overlay */
    .sidebar { width: 80%; max-width: 300px; transform: translateX(-100%); position: fixed; height: 100vh; z-index: 1060; }
    .sidebar.open { transform: translateX(0); box-shadow: 5px 0 15px rgba(0,0,0,0.2); }
    
    .sidebar-header { justify-content: space-between; display: flex; align-items: center; }
    .sidebar-header button { display: block !important; }
    .sidebar-nav { padding: 0; overflow-y: auto !important; }
    .sidebar a { margin: 0; border-radius: 0; padding: 15px 30px; border-bottom: 1px solid #f0f0f0; }
    .sidebar .logout { padding: 15px 30px; margin: 0; margin-top: 10px; border-radius: 0; }
}
</style>
</head>
<body>

<div class="mobile-header" id="mobileHeader">
    <button id="menu-toggle-open"><i class="fas fa-bars"></i></button>
    <div class="mobile-logo"><?= htmlspecialchars(getSetting('system_name','DentiTrack')) ?></div>
    <div style="width: 24px;"></div>
</div>

<nav class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <i class="fas fa-tooth"></i> <?= htmlspecialchars(getSetting('system_name','DentiTrack')) ?>
        <button id="menu-toggle-close" style="display: none;"><i class="fas fa-times"></i></button>
    </div>
    <div class="sidebar-nav">
        <a href="admin_dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
        <a href="manage_accounts.php"><i class="fas fa-users-cog"></i> Manage Accounts</a>
        <a href="clinic_services_admin.php"><i class="fas fa-tools"></i> Clinic Services</a>
        <a href="generate_reports.php"><i class="fas fa-chart-line"></i> Generate Reports</a>
        <a href="payment_module.php"><i class="fas fa-money-check-dollar"></i> Payment Module</a>
        <a href="clinic_schedule_admin.php"><i class="fas fa-calendar-check"></i> Clinic Schedule</a>
        <a href="admin_settings.php" class="active"><i class="fas fa-gear"></i> System Settings</a>
    </div>
    <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
</nav>

<main class="main-content">
    <h1 class="mb-4"><i class="fas fa-gear"></i> System Settings</h1>

    <?php if($message): ?>
        <div class="alert <?= ($message_type == 'success' || strpos($message, '✅') !== false) ? 'alert-success' : 'alert-warning' ?>">
            <i class="fas <?= ($message_type == 'success' || strpos($message, '✅') !== false) ? 'fa-check-circle' : 'fa-exclamation-triangle' ?>"></i> 
            <?= str_replace(['✅', '⚠️'], '', htmlspecialchars($message)) ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="card p-4 bg-white" enctype="multipart/form-data">
        
        <h5 class="mb-3">Appearance Settings</h5>
        <div class="row mb-5">
            <div class="col-md-3 mb-3">
                <label class="form-label">Sidebar Background</label>
                <input type="color" name="sidebar_bg" class="form-control form-control-color" value="<?= htmlspecialchars(getSetting('sidebar_bg')) ?>">
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Sidebar Text</label>
                <input type="color" name="sidebar_text" class="form-control form-control-color" value="<?= htmlspecialchars(getSetting('sidebar_text')) ?>">
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Accent Color</label>
                <input type="color" name="accent_color" class="form-control form-control-color" value="<?= htmlspecialchars(getSetting('accent_color')) ?>">
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Header Text Color</label>
                <input type="color" name="header_text_color" class="form-control form-control-color" value="<?= htmlspecialchars(getSetting('header_text_color')) ?>">
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Font Family</label>
                <select name="font_family" class="form-select">
                    <?php
                    $fonts = ['Inter','Segoe UI','Roboto','Poppins','Open Sans','Montserrat','Lato','Arial'];
                    $current = getSetting('font_family','Inter');
                    foreach($fonts as $font){
                        $sel = ($font==$current)?'selected':'';
                        echo "<option value='$font' $sel>$font</option>";
                    }
                    ?>
                </select>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">System Name</label>
                <input type="text" name="system_name" class="form-control" value="<?= htmlspecialchars(getSetting('system_name')) ?>">
            </div>
        </div>
        
        <hr class="mb-5">

        <h5 class="mb-4">System Configuration</h5>
        <div class="row mb-4">
            <div class="col-md-6 mb-3">
                <label class="form-label">Default Timezone</label>
                <select name="default_timezone" class="form-select">
                    <?php
                    $current_timezone = getSetting('default_timezone', 'Asia/Manila');
                    foreach ($timezones as $tz_value => $tz_label) {
                        $sel = ($tz_value === $current_timezone) ? 'selected' : '';
                        echo "<option value='".htmlspecialchars($tz_value)."' $sel>".htmlspecialchars($tz_label)."</option>";
                    }
                    ?>
                </select>
                <small class="text-muted">Used for logging and appointment scheduling.</small>
            </div>

            <div class="col-md-6 mb-3">
                <label class="form-label">System Footer Text</label>
                <input type="text" name="footer_text" class="form-control" placeholder="e.g., Copyright © 2025 DentiTrack" value="<?= htmlspecialchars(getSetting('footer_text')) ?>">
                <small class="text-muted">Text that appears at the bottom of all pages.</small>
            </div>
        </div>
        
        <h5 class="mt-5 mb-4">Clinic Contact Information</h5>

        <div class="row mb-4">
            <div class="col-md-12 mb-3">
                <label class="form-label">Clinic Address</label>
                <input type="text" name="clinic_address" class="form-control" placeholder="e.g., 0067 Wakas, Bocaue, Philippines" value="<?= htmlspecialchars(getSetting('clinic_address')) ?>">
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Contact Number (Phone)</label>
                <input type="text" name="clinic_phone" class="form-control" placeholder="e.g., 0922 878 7341" value="<?= htmlspecialchars(getSetting('clinic_phone')) ?>">
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Clinic Email</label>
                <input type="email" name="clinic_email" class="form-control" placeholder="e.g., danaroxasdental@gmail.com" value="<?= htmlspecialchars(getSetting('clinic_email')) ?>">
            </div>
        </div>

        <hr class="mb-5">
        
        <h5 class="mt-5 mb-4">Patient Email Messages Settings</h5>
        <p class="text-muted mb-4">Customize the content sent to patients upon successful account verification and downpayment request.</p>

        <div class="row mb-4">
            <div class="col-md-12 mb-3">
                <label for="email_verification_success_message" class="form-label fw-bold">Post-Verification Success Message (Instructions):</label>
                <textarea class="form-control" id="email_verification_success_message" name="email_verification_success_message" rows="4" required><?= htmlspecialchars($current_verification_msg); ?></textarea>
                <small class="form-text text-muted">This message confirms successful verification and instructs the patient on how to log in and book.</small>
            </div>

            <div class="col-md-12 mb-3">
                <label for="email_payment_details" class="form-label fw-bold">Appointment Downpayment Details Message:</label>
                <textarea class="form-control" id="email_payment_details" name="email_payment_details" rows="7" required><?= htmlspecialchars($current_payment_details); ?></textarea>
                <small class="form-text text-muted">This message contains payment amount, methods, and proof of payment instructions. **Use a new line (Enter key) for line breaks, which are handled by nl2br in the email.**</small>
            </div>
        </div>
        <div class="text-end mt-4">
            <button type="submit" name="save_settings" class="btn btn-primary px-4"><i class="fas fa-save"></i> Save Settings</button>
        </div>
    </form>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // --- MOBILE MENU TOGGLE LOGIC ---
    if (window.innerWidth < 992) {
        const sidebar = document.getElementById('sidebar');
        const menuToggleOpen = document.getElementById('menu-toggle-open');
        const menuToggleClose = document.getElementById('menu-toggle-close');

        if (menuToggleOpen && sidebar && menuToggleClose) {
            // Initial state: hide close button
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
});
</script>
</body>
</html>