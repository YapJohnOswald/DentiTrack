<?php
session_start();
require_once '../config/db_pdo.php';

// Restrict access to admin only
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: admin_login.php');
    exit();
}

$admin_username = $_SESSION['username'];
date_default_timezone_set('Asia/Manila');
$pdo->exec("SET time_zone = '+08:00'");

// Clinic name placeholder for single-clinic context
$current_clinic_name = 'The Clinic'; 

// Alert messages
$alertMessage = '';
if (isset($_GET['created'])) $alertMessage = "✅ Account successfully created.";
if (isset($_GET['updated'])) $alertMessage = "✅ Account updated successfully.";
if (isset($_GET['deleted'])) $alertMessage = "✅ Account deleted successfully.";
if (isset($_GET['error'])) $alertMessage = "⚠️ " . htmlspecialchars($_GET['error']);

// Fetch ALL users for client-side filtering and searching
$sql = "SELECT user_id, username, email, role, created_at, updated_at 
        FROM users 
        ORDER BY created_at DESC";
    
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $all_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle database error gracefully
    $all_users = [];
    $alertMessage = "Database Error: Failed to load user accounts. " . htmlspecialchars($e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Manage Accounts - DentiTrack</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
    /* CSS Variables for a clean, consistent color scheme (ALIGNED) */
    :root {
        --sidebar-width: 240px;
        --primary-blue: #007bff;
        --secondary-blue: #0056b3;
        --text-dark: #343a40;
        --text-light: #6c757d;
        --bg-light: #f8f9fa;
        --bg-page: #eef2f8; /* Light blue/grey background for main area */
        --widget-bg: #ffffff;
        --alert-red: #dc3545;
        --success-green: #28a745;
        --accent-orange: #ffc107;
    }

    /* Base Styles (ALIGNED) */
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
    
    /* --- LOADING OVERLAY STYLES (NEW) --- */
    .loading-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(255, 255, 255, 0.95);
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 9999;
        opacity: 1;
        transition: opacity 0.5s ease-out;
    }
    .loading-overlay.fade-out {
        opacity: 0;
        pointer-events: none;
    }
    .loading-overlay i {
        color: var(--primary-blue);
        font-size: 3rem;
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
    .sidebar .logout:hover i {
        color: var(--alert-red);
    }

    /* --- Main Content Styling (ALIGNED) --- */
    .main-content { 
        flex: 1; 
        margin-left: var(--sidebar-width); 
        padding: 40px; 
        background: var(--bg-page); 
        overflow-y: auto; 
    }
    header h1 { 
        font-size: 1.8rem; 
        color: var(--text-dark); 
        font-weight: 700; 
        margin: 0 0 20px 0;
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

    /* Alert Message Styling (ALIGNED) */
    .alert {
        margin: 15px 0;
        padding: 12px 16px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        gap: 10px;
        font-weight: 600;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        border: 1px solid;
    }
    .alert i { font-size: 1.2rem; }
    .alert[style*="✅"] { /* Success style */
        background: #e6ffed;
        color: var(--success-green);
        border-color: #b8e9c6;
    }
    .alert[style*="⚠️"] { /* Warning/Error style */
        background: #fff3e6;
        color: #e38d00;
        border-color: #ffcc99;
    }

    /* Button and Control Styling (ALIGNED) */
    .create-btn {
        background-color: var(--primary-blue);
        color: white;
        padding: 10px 18px;
        font-size: 15px;
        border-radius: 8px;
        border: none;
        cursor: pointer;
        transition: background-color 0.3s, transform 0.1s;
        font-weight: 600;
    }
    .create-btn:hover { background-color: var(--secondary-blue); }

    .control-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
        padding: 15px;
        background: var(--widget-bg);
        border-radius: 10px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    }
    .filter-group {
        display: flex;
        gap: 15px;
        align-items: center;
    }
    #roleFilter, #searchInput {
        padding: 10px 15px;
        border: 1px solid #dcdcdc;
        border-radius: 6px;
        font-size: 14px;
        transition: border-color 0.3s;
    }
    #searchInput:focus, #roleFilter:focus {
        border-color: var(--primary-blue);
        outline: none;
    }
    .search-container {
        position: relative;
        width: 300px;
    }
    #clearSearch {
        position: absolute;
        right: 15px;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        color: var(--text-light);
        cursor: pointer;
        display: none; 
        transition: color 0.2s;
    }
    #clearSearch:hover {
        color: var(--alert-red);
    }

    /* Table Styling (ALIGNED) */
    .account-table {
        background: var(--widget-bg);
        padding: 20px;
        border-radius: 10px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    }
    .account-table h2 {
        font-size: 1.2rem;
        color: var(--text-dark);
        margin-top: 0;
        margin-bottom: 15px;
        border-bottom: 1px solid #eee;
        padding-bottom: 10px;
    }
    table {
        width: 100%;
        border-collapse: collapse;
    }
    th, td {
        padding: 12px 15px;
        border-bottom: 1px solid #f0f0f0;
        text-align: left;
        font-size: 14px;
    }
    th {
        background: var(--bg-page);
        color: var(--text-dark);
        font-weight: 600;
        text-transform: uppercase;
    }
    tr:hover { background: #f8f9fa; }
    
    /* Action Buttons */
    .edit, .delete {
        border: none;
        padding: 8px 12px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 13px;
        transition: background-color 0.3s, color 0.3s;
        margin-right: 5px;
    }
    .edit { background: var(--accent-orange); color: var(--text-dark); }
    .edit:hover { background: #ffdf7c; }
    .delete { background: var(--alert-red); color: white; }
    .delete:hover { background: #ff6b5a; }

    /* Modal Styling (Cleaned up, kept existing structure) */
    .modal {
        display: none; position: fixed; top: 0; left: 0;
        width: 100%; height: 100%;
        background: rgba(0,0,0,0.6);
        justify-content: center; align-items: center;
        z-index: 1000;
    }
    .modal-content {
        background: var(--widget-bg);
        padding: 30px;
        border-radius: 10px;
        width: 450px;
        max-width: 90%;
        position: relative;
        box-shadow: 0 10px 30px rgba(0,0,0,0.3);
    }
    .modal-content h2 {
        color: var(--primary-blue);
        margin-bottom: 20px;
        border-bottom: 1px solid #eee;
        padding-bottom: 10px;
    }
    .close-modal {
        position: absolute;
        right: 15px; top: 10px;
        font-size: 24px;
        color: var(--text-light);
        cursor: pointer;
        transition: color 0.2s;
    }
    .close-modal:hover { color: var(--alert-red); }
    
    label { display: block; margin-top: 10px; font-weight: 600; color: var(--text-dark); }
    input[type="text"], input[type="email"], input[type="password"], select {
        padding: 10px;
        font-size: 15px;
        border: 1px solid #ccc;
        border-radius: 6px;
        width: 100%;
        margin-top: 5px;
        box-sizing: border-box;
    }
    .save-btn {
        background: var(--success-green);
        color: white;
        padding: 10px 16px;
        border-radius: 6px;
        border: none;
        font-weight: 600;
        cursor: pointer;
        transition: background-color 0.2s;
    }
    .save-btn:hover { background: #3ccf5d; }
    .cancel-btn {
        background: #ccc;
        color: black;
        padding: 10px 16px;
        border-radius: 6px;
        border: none;
        font-weight: 600;
        cursor: pointer;
        transition: background-color 0.2s;
    }
    .cancel-btn:hover { background: #b3b3b3; }
    .clearfix { display: flex; justify-content: flex-end; margin-top: 20px; gap: 10px; }


    /* --- MOBILE SPECIFIC HEADER (ALIGNED) --- */
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

    /* Responsive Design (for smaller screens) (ALIGNED) */
    @media (max-width: 992px) {
        /* Show mobile header */
        .mobile-header {
            display: flex; 
        }

        /* Sidebar full screen */
        .sidebar {
            width: 80%;
            max-width: 300px;
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

        /* Adjust control bar layout for smaller screens */
        .control-bar {
            flex-direction: column;
            align-items: stretch;
            gap: 15px;
            padding: 15px;
        }
        .filter-group {
            flex-direction: column;
            align-items: stretch;
            gap: 10px;
        }
        .filter-group label {
            text-align: left;
            margin-top: 5px;
        }
        .search-container {
            width: 100%;
        }
        
        /* Make table columns readable */
        table {
            display: block;
            overflow-x: auto;
            white-space: nowrap;
        }
        .account-table {
            padding: 10px;
        }
    }
</style>
</head>

<body>
    
    <div id="loadingOverlay" class="loading-overlay">
        <i class="fas fa-spinner fa-spin"></i>
    </div>
    
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
        <nav class="sidebar-nav">
            <a href="admin_dashboard.php" ><i class="fas fa-home"></i> Dashboard</a>
            <a href="manage_accounts.php" class="active"><i class="fas fa-users-cog"></i> Manage Accounts</a>
            <a href="clinic_services_admin.php"><i class="fas fa-tools"></i> Clinic Services</a>
            <a href="generate_reports.php"><i class="fas fa-chart-line"></i> Generate Reports</a>
            <a href="payment_module.php"><i class="fas fa-money-check-dollar"></i> Payment Module</a>
            <a href="clinic_schedule_admin.php"><i class="fas fa-calendar-check"></i> Clinic Schedule</a>
            <a href="admin_settings.php"><i class="fas fa-gear"></i> System Settings</a>
        </nav>
        <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Log Out</a>
    </nav>

    <main class="main-content">
        <header>
            <h1><i class="fas fa-users-cog"></i> Manage Accounts</h1>
        </header>
        <p class="welcome">Welcome, <strong><?= htmlspecialchars($admin_username) ?></strong></p>

        <?php if ($alertMessage): 
            $icon = (strpos($alertMessage, '✅') !== false) ? 'fas fa-check-circle' : 'fas fa-exclamation-triangle';
            ?>
            <div class="alert" style="<?= (strpos($alertMessage, '✅') !== false) ? 'background: #e6ffed; color: var(--success-green); border-color: #b8e9c6;' : 'background: #fff3e6; color: #e38d00; border-color: #ffcc99;' ?>">
                <i class="<?= $icon ?>"></i> <?= str_replace(['✅', '⚠️'], '', $alertMessage) ?>
            </div>
        <?php endif; ?>

        <div class="control-bar">
            <button class="create-btn" onclick="openCreateModal()"><i class="fas fa-user-plus"></i> Create New Account</button>
            
            <div class="filter-group">
                <label for="roleFilter" class="hidden-on-mobile">Filter by Role:</label>
                <select id="roleFilter" onchange="searchAndFilter()">
                    <option value="all">All Users</option>
                    <option value="admin">Admins</option>
                    <option value="doctor">Doctors</option>
                    <option value="secretary">Secretaries</option>
                    <option value="patient">Patients</option>
                </select>

                <div class="search-container">
                    <input type="text" id="searchInput" onkeyup="searchAndFilter()" placeholder="Search username, email, or role..." title="Type to search accounts">
                    <button type="button" id="clearSearch" onclick="clearSearch()"><i class="fas fa-times-circle"></i></button>
                </div>
            </div>
        </div>

        <div class="account-table">
            <h2>System Accounts</h2>
            <?php if (count($all_users) > 0): ?>
                <table id="userTable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Created</th>
                            <th>Updated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="userTableBody">
                        <?php foreach ($all_users as $user): ?>
                            <tr data-role="<?= htmlspecialchars($user['role']) ?>">
                                <td><?= $user['user_id'] ?></td>
                                <td><?= htmlspecialchars($user['username']) ?></td>
                                <td><?= htmlspecialchars($user['email'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($user['role']) ?></td>
                                <td><?= htmlspecialchars($user['created_at']) ?></td>
                                <td><?= htmlspecialchars($user['updated_at'] ?? '—') ?></td>
                                <td>
                                    <button class="edit" onclick="openEditModal(
                                        <?= $user['user_id'] ?>, 
                                        '<?= htmlspecialchars(addslashes($user['username'])) ?>', 
                                        '<?= htmlspecialchars(addslashes($user['email'] ?? '')) ?>', 
                                        '<?= htmlspecialchars($user['role']) ?>'
                                    )">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <button class="delete" onclick="confirmDelete(<?= $user['user_id'] ?>)">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No user accounts found in the system.</p>
            <?php endif; ?>
        </div>

        <div id="createModal" class="modal">
            <div class="modal-content">
                <span class="close-modal" onclick="closeCreateModal()">&times;</span>
                <h2>Create Account</h2>
                <form id="createForm" action="save_account.php" method="POST">
                    <label>Username:</label>
                    <input type="text" name="username" required>
                    <label>Email:</label>
                    <input type="email" name="email">
                    <label>Password:</label>
                    <input type="password" name="password" required>
                    <label>Confirm Password:</label>
                    <input type="password" name="confirm_password" required>
                    <label>Role:</label>
                    <select name="role" id="roleSelect" required>
                        <option value="">Select Role</option>
                        <option value="admin">Admin</option>
                        <option value="doctor">Doctor</option>
                        <option value="secretary">Secretary</option>
                        <option value="patient">Patient</option>
                    </select>

                    <div class="clearfix">
                        <button type="button" class="cancel-btn" onclick="closeCreateModal()">Cancel</button>
                        <button type="submit" class="save-btn">Save</button>
                    </div>
                </form>
            </div>
        </div>

        <div id="editModal" class="modal">
            <div class="modal-content">
                <span class="close-modal" onclick="closeEditModal()">&times;</span>
                <h2>Edit Account</h2>
                <form id="editForm" action="edit_account.php" method="POST">
                    <input type="hidden" name="user_id" id="edit-user_id">
                    
                    <label>Username:</label>
                    <input type="text" name="username" id="edit-username" required>
                    
                    <label>Email:</label>
                    <input type="email" name="email" id="edit-email">
                    
                    <label>Role:</label>
                    <select name="role" id="edit-roleSelect" required>
                        <option value="admin">Admin</option>
                        <option value="doctor">Doctor</option>
                        <option value="secretary">Secretary</option>
                        <option value="patient">Patient</option>
                    </select>

                    <div class="clearfix">
                        <button type="button" class="cancel-btn" onclick="closeEditModal()">Cancel</button>
                        <button type="submit" class="save-btn">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </main>

<script>
    const sidebar = document.getElementById('sidebar');
    const menuToggleOpen = document.getElementById('menu-toggle-open');
    const menuToggleClose = document.getElementById('menu-toggle-close');
    
    // --- LOADING OVERLAY LOGIC ---
    window.onload = function() {
        const overlay = document.getElementById('loadingOverlay');
        if (overlay) {
            // Apply fade-out class first
            overlay.classList.add('fade-out');
            // Remove the overlay from DOM after transition (0.5s + 0.1s safety)
            setTimeout(() => {
                overlay.remove();
            }, 600);
        }
    };


    // --- MOBILE MENU TOGGLE LOGIC (ALIGNED) ---
    function setupMobileMenu() {
        const isMobile = window.innerWidth < 992;
        
        if (isMobile) {
            // Show close button in the sidebar header
            if (menuToggleClose) menuToggleClose.style.display = 'block'; 

            // Open functionality
            if (menuToggleOpen) {
                menuToggleOpen.onclick = function() {
                    sidebar.classList.add('open');
                    document.body.style.overflow = 'hidden'; 
                };
            }
            
            // Close functionality
            if (menuToggleClose) {
                menuToggleClose.onclick = function() {
                    sidebar.classList.remove('open');
                    document.body.style.overflow = ''; 
                };
            }

            // Auto-close menu when a link is clicked
            document.querySelectorAll('.sidebar a').forEach(link => {
                link.onclick = function() {
                    setTimeout(() => {
                        sidebar.classList.remove('open');
                        document.body.style.overflow = '';
                    }, 300);
                };
            });

        } else {
            // Desktop view
            if (menuToggleClose) menuToggleClose.style.display = 'none';
            sidebar.classList.remove('open');
            document.body.style.overflow = '';
        }
    }

    setupMobileMenu();
    window.addEventListener('resize', setupMobileMenu); 


    // --- FILTER/SEARCH LOGIC (Kept original logic, adjusted function name in event listeners) ---
    const userTableBody = document.getElementById('userTableBody');
    const roleFilter = document.getElementById('roleFilter');
    const searchInput = document.getElementById('searchInput');
    const clearSearchBtn = document.getElementById('clearSearch');

    function searchAndFilter() {
        const filterText = searchInput.value.toUpperCase().trim();
        const selectedRole = roleFilter.value;
        const rows = userTableBody.getElementsByTagName('tr');

        // Show/hide clear search button
        clearSearchBtn.style.display = filterText.length > 0 ? 'block' : 'none';

        for (let i = 0; i < rows.length; i++) {
            const row = rows[i];
            const role = row.getAttribute('data-role');
            
            const roleMatches = selectedRole === 'all' || selectedRole === role;
            const rowText = row.textContent || row.innerText;
            const textMatches = rowText.toUpperCase().indexOf(filterText) > -1;

            if (roleMatches && textMatches) {
                row.style.display = ''; // Show row
            } else {
                row.style.display = 'none'; // Hide row
            }
        }
    }
    
    // Attach single function to both events
    roleFilter.onchange = searchAndFilter;
    searchInput.onkeyup = searchAndFilter;
    
    function clearSearch() {
        searchInput.value = '';
        searchAndFilter(); 
    }

    // Modal functions (Create)
    function openCreateModal() {
        document.getElementById('createModal').style.display = 'flex';
    }
    function closeCreateModal() {
        document.getElementById('createModal').style.display = 'none';
        document.getElementById('createForm').reset();
    }

    // Modal functions (Edit)
    function openEditModal(userId, username, email, role) {
        document.getElementById('edit-user_id').value = userId;
        document.getElementById('edit-username').value = username;
        document.getElementById('edit-email').value = email;
        document.getElementById('edit-roleSelect').value = role;
        
        document.getElementById('editModal').style.display = 'flex';
    }
    function closeEditModal() {
        document.getElementById('editModal').style.display = 'none';
        document.getElementById('editForm').reset();
    }

    function confirmDelete(userId) {
        if (confirm('Are you sure you want to delete this account? This action cannot be undone.')) {
            window.location.href = 'delete_account.php?user_id=' + userId;
        }
    }
    
    // Global click handler to close modals when clicking outside
    window.onclick = function(e) {
        if (e.target.classList.contains('modal')) {
            e.target.style.display = 'none';
        }
    }
</script>
</body>
</html>