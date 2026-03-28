<?php
// clinic_services_admin.php
// Master Services CRUD UI (Standalone - No Doctor Assignment Logic)

session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: admin_login.php'); // <-- FIX APPLIED HERE
    exit();
}

include '../../config/db.php'; // mysqli $conn
include '../../config/settings_loader.php';

$message = '';
$search_term = $_GET['service_search'] ?? ''; // Get the search term
$upload_dir_base = __DIR__ . '/../uploads/service_images/';
$web_upload_path = 'uploads/service_images/'; // Relative path for web access

// --- Helper Functions ---
/**
 * Prepares a mysqli statement or throws an exception on failure.
 * @param string $sql The SQL query string.
 * @return mysqli_stmt The prepared statement object.
 * @throws Exception If preparation fails.
 */
function prepare_or_throw($sql) {
    global $conn;
    $stmt = $conn->prepare($sql);
    if ($stmt === false) throw new Exception("MySQL prepare() failed: " . $conn->error . " -- SQL: " . $sql);
    return $stmt;
}

/**
 * Checks if a given table exists in the database.
 * @param string $table The name of the table to check.
 * @return bool True if the table exists, false otherwise.
 */
function table_exists($table) {
    global $conn;
    $t = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$t}'");
    return ($res && $res->num_rows > 0);
}

// --- Load and Filter services master (NO doctor assignments) ---
$services_master = [];

if (table_exists('services')) {
    try {
        // Query now selects the new 'image_path' column
        $sql = "
            SELECT 
                service_id, service_name, category, description, price, duration, image_path, status, created_at
            FROM services s
        ";
        
        $params = [];
        $types = "";
        
        // Add WHERE clause if a search term is present
        if (!empty($search_term)) {
            $sql .= " WHERE service_name LIKE ? OR category LIKE ? OR description LIKE ?";
            $search_like = "%" . $search_term . "%";
            $params = [$search_like, $search_like, $search_like];
            $types = "sss";
        }
        
        $sql .= " ORDER BY service_id ASC";

        $stmt = prepare_or_throw($sql);
        
        if (!empty($types)) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $res = $stmt->get_result();

        // Load services directly without doctor aggregation
        while ($r = $res->fetch_assoc()) {
            $services_master[] = $r;
        }

        $stmt->close();
    } catch (Exception $e) {
        $message = "Error loading services: " . htmlspecialchars($e->getMessage());
    }
}

// --- POST handling (services CRUD + duplication check) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['service_action'])) {
        $action = $_POST['service_action'];
        
        if ($action === 'save_master_service') {
            $sid = isset($_POST['master_service_id']) ? (int)$_POST['master_service_id'] : 0;
            $name = trim($_POST['master_service_name'] ?? '');
            $category = trim($_POST['master_category'] ?? '');
            $description = trim($_POST['master_description'] ?? '');
            $price = isset($_POST['master_price']) ? (float)$_POST['master_price'] : 0.0;
            $duration = trim($_POST['master_duration'] ?? '');
            $status = (($_POST['master_status'] ?? 'active') === 'active') ? 'active' : 'inactive';
            $delete_image = isset($_POST['delete_current_image']) ? true : false;
            
            $image_path = ''; // Will hold the final path to save to DB

            if ($name === '') {
                $message = "Service name required.";
            } else {
                try {
                    // --- DUPLICATE CHECK LOGIC ---
                    $duplicate_check_sql = "SELECT service_id FROM services WHERE service_name = ? AND service_id != ?";
                    $stmt_check = prepare_or_throw($duplicate_check_sql);
                    $stmt_check->bind_param("si", $name, $sid);
                    $stmt_check->execute();
                    $stmt_check->store_result();
                    
                    if ($stmt_check->num_rows > 0) {
                        $message = "❌ Error: A service with the name **" . htmlspecialchars($name) . "** already exists. Please choose a unique name.";
                        $stmt_check->close();
                    } else {
                        $stmt_check->close();

                        // 1. Fetch existing image path if updating or deleting
                        $existing_image_path = '';
                        if ($sid > 0) {
                            $stmt_old_img = prepare_or_throw("SELECT image_path FROM services WHERE service_id = ?");
                            $stmt_old_img->bind_param("i", $sid);
                            $stmt_old_img->execute();
                            $stmt_old_img->bind_result($existing_image_path);
                            $stmt_old_img->fetch();
                            $stmt_old_img->close();
                            $image_path = $existing_image_path; // Keep existing path by default
                        }

                        // 2. Handle image deletion request
                        if ($delete_image) {
                            // Prepend base directory for filesystem operations
                            $full_old_path = $upload_dir_base . basename($existing_image_path);
                            if (!empty($existing_image_path) && file_exists($full_old_path)) {
                                @unlink($full_old_path); // Use @ to suppress file permission errors
                            }
                            $image_path = ''; // Clear path in DB
                        }

                        // 3. Handle new file upload
                        $uploaded_file = $_FILES['master_service_image'] ?? null;
                        
                        if ($uploaded_file && $uploaded_file['error'] === UPLOAD_ERR_OK) {
                            // Ensure upload directory exists and is writable
                            if (!is_dir($upload_dir_base)) {
                                if (!mkdir($upload_dir_base, 0777, true)) {
                                    throw new Exception("Failed to create upload directory: " . $upload_dir_base);
                                }
                            }

                            $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
                            if (in_array($uploaded_file['type'], $allowed_types) && $uploaded_file['size'] <= 5000000) { // 5MB limit
                                
                                // Delete old image if a new one is replacing it AND it wasn't already deleted via checkbox
                                if ($sid > 0 && !empty($existing_image_path) && !$delete_image) {
                                    $full_old_path = $upload_dir_base . basename($existing_image_path);
                                    if (file_exists($full_old_path)) {
                                        @unlink($full_old_path); // Use @ to suppress file permission errors
                                    }
                                }
                                
                                // Generate a unique file name
                                $extension = pathinfo($uploaded_file['name'], PATHINFO_EXTENSION);
                                $file_name = uniqid('svc_') . '.' . $extension;
                                $target_file = $upload_dir_base . $file_name;
                                
                                if (move_uploaded_file($uploaded_file['tmp_name'], $target_file)) {
                                    // Store the path relative to the web root in the DB
                                    $image_path = $web_upload_path . $file_name; 
                                } else {
                                    throw new Exception("File upload failed to move file. Check permissions.");
                                }
                            } else {
                                throw new Exception("Invalid file type or size. Only JPG, PNG, WEBP up to 5MB allowed.");
                            }
                        } // End file upload logic

                        // 4. Save/Update Service in DB
                        if ($sid > 0) {
                            // Update existing service
                            $stmt = prepare_or_throw("UPDATE services SET service_name=?, category=?, description=?, price=?, duration=?, status=?, image_path=? WHERE service_id=?");
                            $stmt->bind_param("sssdsssi", $name, $category, $description, $price, $duration, $status, $image_path, $sid);
                            if ($stmt->execute()) $message = "✅ Master service updated successfully.";
                            else $message = "Error updating service: ".$stmt->error;
                            $stmt->close();
                        } else {
                            // Insert new service
                            $stmt = prepare_or_throw("INSERT INTO services (service_name, category, description, price, duration, status, image_path, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                            $stmt->bind_param("sssdsss", $name, $category, $description, $price, $duration, $status, $image_path);
                            if ($stmt->execute()) $message = "✅ Master service created successfully.";
                            else $message = "Error creating service: ".$stmt->error;
                            $stmt->close();
                        }
                    }
                } catch (Exception $e) {
                    $message = "❌ Service save error: " . htmlspecialchars($e->getMessage());
                }
            }
        }

        if ($action === 'delete_master_service') {
            $sid = isset($_POST['master_service_id']) ? (int)$_POST['master_service_id'] : 0;
            if ($sid > 0) {
                try {
                    // Fetch image path before deletion
                    $stmt_old_img = prepare_or_throw("SELECT image_path FROM services WHERE service_id = ?");
                    $stmt_old_img->bind_param("i", $sid);
                    $stmt_old_img->execute();
                    $stmt_old_img->bind_result($existing_image_path);
                    $stmt_old_img->fetch();
                    $stmt_old_img->close();

                    // Delete from main services table
                    $stmt = prepare_or_throw("DELETE FROM services WHERE service_id = ?");
                    $stmt->bind_param("i",$sid);
                    
                    if ($stmt->execute()) {
                        // Delete the physical image file
                        $full_old_path = $upload_dir_base . basename($existing_image_path);
                        if (!empty($existing_image_path) && file_exists($full_old_path)) {
                            @unlink($full_old_path); // Use @ to suppress file permission errors
                        }

                        // Cleanup assignments in doctor_services table (if it exists)
                        if (table_exists('doctor_services')) {
                            $stmt2 = prepare_or_throw("DELETE FROM doctor_services WHERE service_id = ?");
                            $stmt2->bind_param("i",$sid);
                            $stmt2->execute();
                            $stmt2->close();
                        }
                        $message = "✅ Master service deleted.";
                    } else $message = "Error deleting service: ".$stmt->error;
                    $stmt->close();
                } catch (Exception $e) {
                    $message = "DB error deleting service: " . htmlspecialchars($e->getMessage());
                }
            }
        }
    }
    
    // Redirect after POST to prevent form resubmission and reflect changes
    header("Location: clinic_services_admin.php" . (!empty($search_term) ? "?service_search=" . urlencode($search_term) : ""));
    exit();
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Services - Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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
    
    /* --- Global & Layout Styles --- */
    html { scroll-behavior: smooth; }
    body { 
        margin:0; 
        font-family: 'Inter', sans-serif; 
        background: var(--bg-page); 
        color: var(--text-dark); 
        display:flex; 
        min-height:100vh; 
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

    
    main.main-content { 
        flex:1; 
        padding:40px; /* Increased padding for desktop look */
        margin-left: var(--sidebar-width);
        background: var(--bg-page); 
        overflow-y:auto; 
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
    
    .card-panel { 
        background: var(--widget-bg); 
        padding: 25px; /* Adjusted padding */
        border-radius: 10px; /* Aligned radius */
        box-shadow: 0 4px 15px rgba(0,0,0,0.05); /* Soft shadow */
    }

    /* --- Form & Input Styles --- */
    #masterServiceForm {
        border: 1px solid #e9ecef !important;
        border-radius: 10px !important;
        box-shadow: none !important;
        background: #fcfcfc;
    }
    #masterServiceForm h5 {
        color: var(--primary-blue);
        font-weight: 700;
        border-bottom: 1px solid #eee;
        padding-bottom: 10px;
        margin-bottom: 15px !important;
    }
    .form-control, .form-select {
        border-radius: 6px !important;
        border-color: #ced4da !important;
    }
    .btn-primary, .btn-danger {
        font-weight: 600 !important;
        border-radius: 6px !important;
    }
    .btn-primary { background: var(--primary-blue) !important; border-color: var(--primary-blue) !important; }
    .btn-primary:hover { background: var(--secondary-blue) !important; border-color: var(--secondary-blue) !important; }
    
    .btn-outline-secondary {
        color: var(--text-dark) !important;
        border-color: var(--text-light) !important;
    }
    .btn-outline-secondary:hover {
        background: var(--bg-page) !important;
    }

    /* Message styling */
    .alert-info {
        background-color: #e6f7ff;
        border-color: #91d5ff;
        color: #0050b3;
        font-weight: 600;
        border-radius: 6px;
    }

    /* --- Table Styles --- */
    .table-responsive-wrapper {
        overflow-x: auto;
        /* Hiding vertical scrollbar to ensure only table content scrolls vertically */
        overflow-y: hidden; 
    }

    .table-responsive-wrapper > div {
        max-height: 70vh; /* Controlled max height for table body */
        overflow-y: auto;
        -ms-overflow-style: none;
        scrollbar-width: none;
    }
    .table-responsive-wrapper > div::-webkit-scrollbar { display: none; }
    
    .table-clean {
        width: 100%;
        min-width: 950px; /* Ensure table scrolls horizontally on small screens */
        border-collapse: separate; 
        border-spacing: 0 6px; 
    }
    .table-clean thead th {
        background-color: var(--primary-blue); 
        color: white;
        border: none;
        padding: 12px 15px; 
        font-size: 0.8rem; 
        text-transform: uppercase;
        letter-spacing: 0.05em;
        position: sticky; 
        top: 0;
        z-index: 10;
        white-space: nowrap; 
    }
    .table-clean tbody tr {
        background-color: #ffffff;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        border-radius: 6px; 
        transition: box-shadow 0.2s;
        font-size: 0.85rem;
    }
    .table-clean tbody tr:hover {
        background-color: #f1f7ff; 
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
    }
    .table-clean tbody td {
        padding: 10px 15px;
        border: none;
        vertical-align: middle;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        max-width: 150px; 
    }
    /* Description column max-width */
    .table-clean tbody td:nth-child(5) { 
        max-width: 250px; 
        white-space: normal; 
        line-height: 1.3;
        max-height: 40px;
        overflow: hidden;
    }

    .table-clean tbody tr td:first-child { border-top-left-radius: 6px; border-bottom-left-radius: 6px; }
    .table-clean tbody tr td:last-child { border-top-right-radius: 6px; border-bottom-right-radius: 6px; }
    
    .price-highlight {
        font-weight: 700;
        color: var(--success-green); 
        font-size: 1em;
    }
    
    .action-buttons-group .btn {
        padding: 6px 10px;
        font-size: 0.8rem;
    }
    
    /* Image preview in form */
    #currentImagePreview {
        max-width: 100%;
        height: auto;
        border: 1px solid #ddd;
        border-radius: 6px;
        margin-bottom: 10px;
        max-height: 150px; 
        object-fit: contain;
    }
    .service-img-thumb {
        width: 40px;
        height: 40px;
        object-fit: cover;
        border-radius: 4px;
        border: 1px solid #ddd; 
    }
    
    /* --- MOBILE SPECIFIC HEADER/RESPONSIVENESS --- */
    .mobile-header { 
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 60px;
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
    .sidebar-header button {
        display: none !important; /* Hide close button on desktop sidebar */
    }

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

        /* Content layout stacking */
        .row { display: flex; flex-direction: column-reverse; } /* Stack columns, put form first for mobile scroll */
        .col-md-4, .col-md-8 { width: 100%; padding: 0 !important; margin-bottom: 20px; }
        .col-md-8 { order: 1; } 
        .col-md-4 { order: 2; margin-top: 20px; } /* Ensure form is easily accessible */
        
        .action-buttons-group { justify-content: flex-start; }
        .table-responsive-wrapper { overflow-x: auto; } 
        .table-clean { min-width: 900px; } 
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
        <button id="menu-toggle-close" style="display: none; background:none; border:none; font-size:24px; color:var(--text-dark); cursor:pointer;"><i class="fas fa-times"></i></button>
    </div>
    <div class="sidebar-nav">
        <a href="../Dashboard/admin_dashboard.php" ><i class="fas fa-home"></i> Dashboard</a>
        <a href="../Manage_accounts/Manage_accounts.php"><i class="fas fa-users-cog"></i> Manage Accounts</a>
        <a href="../Clinic_Services/clinic_services_admin.php" class="active"><i class="fas fa-tools"></i> Clinic Services</a>
        <a href="../Generate_Reports/generate_reports.php"><i class="fas fa-chart-line"></i> Generate Reports</a>
        <a href="../Payment_Module/payment_module.php"><i class="fas fa-money-check-dollar"></i> Payment Module</a>
        <a href="../Clinic_Scedule/clinic_schedule_admin.php"><i class="fas fa-calendar-check"></i> Clinic Schedule</a>
        <a href="../System_Settings/admin_settings.php"><i class="fas fa-gear"></i> System Settings</a>
    </div>
    <a href="../logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
</nav>

<main class="main-content">
    <header>
        <h1><i class="fas fa-tools"></i> Clinic Services Management</h1>
    </header>

    <div class="card-panel">
        <div class="d-flex mb-3 align-items-center">
            <h5 class="me-3" style="color: var(--secondary-blue); font-weight: 700;"><i class="fas fa-clipboard-list"></i> Service Catalog Editor</h5> 
            <a class="btn btn-sm btn-outline-secondary ms-auto" href="manage_accounts.php" style="font-size:0.9rem; border-radius:6px;"><i class="fas fa-users-cog"></i> Go to Accounts</a>
        </div>

        <?php if ($message): ?><div class="alert alert-info"><?= $message ?></div><?php endif; ?>

        <div class="row">
            <div class="col-md-4">
                <form method="POST" id="masterServiceForm" class="p-3 border rounded shadow-sm" enctype="multipart/form-data">
                    <h5 class="mb-3"><i class="fas fa-plus-circle"></i> Add / Edit Service</h5>
                    <input type="hidden" name="service_action" value="save_master_service">
                    <input type="hidden" name="master_service_id" id="master_service_id" value="">
                    
                    <div class="mb-2"><label class="form-label small fw-bold">Service Name <span class="text-danger">*</span></label><input type="text" name="master_service_name" id="master_service_name" class="form-control form-control-sm" required></div>
                    <div class="mb-2"><label class="form-label small fw-bold">Category</label><input type="text" name="master_category" id="master_category" class="form-control form-control-sm" placeholder="e.g., General, Orthodontics"></div>
                    <div class="mb-2"><label class="form-label small fw-bold">Description</label><textarea name="master_description" id="master_description" class="form-control form-control-sm" rows="3"></textarea></div>
                    
                    <div class="row g-2">
                        <div class="col-4 mb-2"><label class="form-label small fw-bold">Price (₱)</label><input type="number" step="0.01" name="master_price" id="master_price" class="form-control form-control-sm" value="0.00"></div>
                        <div class="col-4 mb-2"><label class="form-label small fw-bold">Duration</label><input type="text" name="master_duration" id="master_duration" class="form-control form-control-sm" placeholder="e.g., 30 min"></div>
                        <div class="col-4 mb-2"><label class="form-label small fw-bold">Status</label><select name="master_status" id="master_status" class="form-select form-select-sm"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                    </div>

                    <div id="imageDisplayContainer" style="display:none; margin-top: 10px;">
                        <label class="form-label small fw-bold">Current Image</label>
                        <img id="currentImagePreview" src="" alt="Service Image">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="1" name="delete_current_image" id="delete_current_image">
                            <label class="form-check-label small" for="delete_current_image">
                                Delete current image on save.
                            </label>
                        </div>
                    </div>

                    <div class="mb-2 mt-3">
                        <label class="form-label small fw-bold">Service Image (JPG/PNG/WEBP, Max 5MB)</label>
                        <input type="file" name="master_service_image" id="master_service_image" class="form-control form-control-sm">
                        <div id="imageHelp" class="form-text small">Upload a new image. Will replace the existing one if present, unless 'Delete current image' is checked.</div>
                    </div>
                    
                    <div class="d-flex gap-2 mt-3">
                        <button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-save"></i> Save Service</button>
                        <button class="btn btn-outline-secondary btn-sm" type="button" onclick="clearMasterForm()"><i class="fas fa-eraser"></i> New / Clear</button>
                        <button class="btn btn-danger btn-sm ms-auto" type="button" id="deleteMasterBtn" style="display:none;" onclick="deleteMasterService()"><i class="fas fa-trash"></i> Delete</button>
                    </div>
                </form>
            </div>

            <div class="col-md-8">
                <h5 class="mb-3" style="color: var(--secondary-blue); font-weight: 700;"><i class="fas fa-list-alt"></i> Services Catalog</h5>
                
                <form method="GET" class="mb-3">
                    <div class="input-group input-group-sm">
                        <input type="text" name="service_search" class="form-control" placeholder="Search by name, category, or description..." value="<?= htmlspecialchars($search_term) ?>">
                        <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i> Search</button>
                        <?php if (!empty($search_term)): ?>
                            <a href="clinic_services_admin.php" class="btn btn-danger"><i class="fas fa-times"></i> Clear</a>
                        <?php endif; ?>
                    </div>
                </form>
                
                <div class="table-responsive-wrapper">
                    <div>
                        <table class="table table-clean"> 
                            <thead>
                                <tr>
                                    <th style="width: 5%;">ID</th> 
                                    <th style="width: 15%;">Service Name</th>
                                    <th style="width: 10%;">Category</th>
                                    <th style="width: 5%;">Image</th> 
                                    <th style="width: 25%;">Description</th>
                                    <th style="width: 10%;">Price</th>
                                    <th style="width: 10%;">Duration</th>
                                    <th style="width: 10%;">Status</th>
                                    <th style="width: 10%;">Action</th> 
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                if (empty($services_master)): ?>
                                    <tr><td colspan="9" class="text-center small text-muted p-4">No services found.</td></tr>
                                <?php else: foreach ($services_master as $svc): 
                                    $status_class = $svc['status'] == 'active' ? 'success' : 'secondary';
                                    // Correct image path for display on admin page (it needs to go up one directory from /admin/)
                                    $image_url = !empty($svc['image_path']) ? '../' . $svc['image_path'] : 'https://placehold.co/40x40/cccccc/333333?text=N/A';
                                ?>
                                    <tr>
                                        <td><?= htmlspecialchars($svc['service_id']) ?></td> 
                                        <td><?= htmlspecialchars($svc['service_name']) ?></td>
                                        <td><?= htmlspecialchars($svc['category']) ?></td>
                                        <td><img src="<?= htmlspecialchars($image_url) ?>" class="service-img-thumb" alt="Service Image" onerror="this.onerror=null;this.src='https://placehold.co/40x40/cccccc/333333?text=ERR';"></td>
                                        <td><?= htmlspecialchars($svc['description']) ?></td>
                                        <td class="price-highlight">₱<?= number_format($svc['price'],2) ?></td>
                                        <td><?= htmlspecialchars($svc['duration']) ?></td>
                                        <td><span class="badge bg-<?= $status_class ?>"><?= htmlspecialchars(ucfirst($svc['status'])) ?></span></td>
                                        
                                        <td class="action-col">
                                            <div class="action-buttons-group">
                                                <button class="btn btn-sm btn-outline-primary" type="button" 
                                                    onclick='populateMaster(<?= json_encode($svc, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)'
                                                    title="Edit Service Details">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                
                                                <button class="btn btn-sm btn-outline-danger" type="button" 
                                                    onclick="if(confirm('Are you sure you want to delete the service: <?= htmlspecialchars($svc['service_name']) ?>? This action cannot be undone.')){ 
                                                                    document.getElementById('deleteForm_<?= (int)$svc['service_id'] ?>').submit();
                                                                }"
                                                    title="Delete Service">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                            
                                            <form method="POST" id="deleteForm_<?= (int)$svc['service_id'] ?>" style="display:none;">
                                                <input type="hidden" name="service_action" value="delete_master_service">
                                                <input type="hidden" name="master_service_id" value="<?= (int)$svc['service_id'] ?>">
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const webUploadPath = '<?= $web_upload_path ?>';

function populateMaster(s){
    // Function to safely decode HTML entities from PHP's aggressive encoding
    function unescapeHtml(text) {
        if (!text) return text;
        const textarea = document.createElement('textarea');
        textarea.innerHTML = text;
        return textarea.value;
    }
    
    // PHP encodes the JSON output (JSON_HEX_*) so we need to decode and parse
    const serviceData = JSON.parse(unescapeHtml(JSON.stringify(s)));

    // Populate the form fields with data from the selected service (s)
    document.getElementById('master_service_id').value = serviceData.service_id || '';
    document.getElementById('master_service_name').value = serviceData.service_name || '';
    document.getElementById('master_category').value = serviceData.category || '';
    document.getElementById('master_description').value = serviceData.description || '';
    document.getElementById('master_price').value = serviceData.price !== null ? parseFloat(serviceData.price).toFixed(2) : '0.00';
    document.getElementById('master_duration').value = serviceData.duration || '';
    document.getElementById('master_status').value = serviceData.status || 'active';
    
    // --- Image Handling in Edit Form ---
    const imageContainer = document.getElementById('imageDisplayContainer');
    const imagePreview = document.getElementById('currentImagePreview');
    const deleteCheckbox = document.getElementById('delete_current_image');
    
    // Clear file input every time
    document.getElementById('master_service_image').value = '';
    deleteCheckbox.checked = false;

    if (serviceData.image_path) {
        // Construct the full URL for the preview (from admin/ to ../uploads/service_images/file.ext)
        const imageUrl = '../' + serviceData.image_path; 
        imagePreview.src = imageUrl;
        imageContainer.style.display = 'block';
    } else {
        imageContainer.style.display = 'none';
        imagePreview.src = '';
    }
    // -------------------------------------

    // Show delete button when editing
    document.getElementById('deleteMasterBtn').style.display = 'inline-block';
    document.getElementById('deleteMasterBtn').dataset.id = serviceData.service_id;
    
    // Scroll to the form
    window.scrollTo({top:0,behavior:'smooth'});
}

function clearMasterForm(){
    // Clear all form fields for adding a new service
    document.getElementById('master_service_id').value = '';
    document.getElementById('master_service_name').value = '';
    document.getElementById('master_category').value = '';
    document.getElementById('master_description').value = '';
    document.getElementById('master_price').value = '0.00';
    document.getElementById('master_duration').value = '';
    document.getElementById('master_status').value = 'active';

    // Clear image fields
    document.getElementById('master_service_image').value = '';
    document.getElementById('imageDisplayContainer').style.display = 'none';
    document.getElementById('currentImagePreview').src = '';
    document.getElementById('delete_current_image').checked = false;

    document.getElementById('deleteMasterBtn').style.display = 'none';
    
    // Scroll to the top if cleared
    window.scrollTo({top:0,behavior:'smooth'});
}

function deleteMasterService(){
    const id = document.getElementById('master_service_id').value;
    if (!id || id === '') return;
    const serviceName = document.getElementById('master_service_name').value;
    
    if (!confirm('Are you sure you want to delete the master service ' + (serviceName ? `"${serviceName}"` : `(ID: ${id})`) + '? This will permanently delete the associated image file and remove all linked data.')) return;
    
    // Submit the hidden delete form associated with the service ID
    const deleteForm = document.getElementById('deleteForm_' + id); 
    if(deleteForm) {
        deleteForm.submit();
    }
}


// --- MOBILE MENU TOGGLE LOGIC ---
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.querySelector('.sidebar');
    
    // Add mobile toggle elements dynamically if needed
    if (window.innerWidth < 992) {
        let mobileHeader = document.getElementById('mobileHeader');
        if (!mobileHeader) {
             const newMobileHeader = document.createElement('div');
             newMobileHeader.id = 'mobileHeader';
             newMobileHeader.className = 'mobile-header';
             newMobileHeader.innerHTML = '<button id="menu-toggle-open"><i class="fas fa-bars"></i></button><div class="mobile-logo">DentiTrack</div><div style="width: 24px;"></div>';
             document.body.prepend(newMobileHeader);
        }

        let menuToggleClose = document.getElementById('menu-toggle-close');
        if (!menuToggleClose && sidebar) {
            menuToggleClose = document.createElement('button');
            menuToggleClose.id = 'menu-toggle-close';
            menuToggleClose.innerHTML = '<i class="fas fa-times"></i>';
            menuToggleClose.style.cssText = 'background:none; border:none; font-size:24px; color:var(--text-dark); cursor:pointer; margin-left: auto;';
            
            const header = sidebar.querySelector('.sidebar-header');
            if (header) {
                // Ensure the header has both the title and the close button for mobile display
                header.style.display = 'flex';
                header.style.justifyContent = 'space-between';
                header.style.alignItems = 'center';
                header.appendChild(menuToggleClose); 
            }
        }
        
        const menuToggleOpen = document.getElementById('menu-toggle-open');

        if (menuToggleOpen && menuToggleClose && sidebar) {
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