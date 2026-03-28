<?php
/**
 * DentiTrack - Secretary Announcement Creation Module
 * Handles Create, Read, Update, Delete (CRUD) operations for announcements.
 */
session_start();

// Ensure these paths are correct for your file structure
// Assuming 'db_pdo.php' handles the PDO connection setup and assigns it to $pdo
require_once '../config/db_pdo.php'; 
// Assuming 'db_conn.php' might contain redundant or non-PDO specific setup; keeping if intended.
require_once '../config/db_conn.php'; 

// Check authentication and role immediately (Early Exit)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'secretary') {
    header('Location: ../public/login.php');
    exit;
}

// Ensure $pdo is available for PDO operations
if (!isset($pdo) && isset($conn) && $conn instanceof PDO) {
    $pdo = $conn;
} elseif (!isset($pdo) || !$pdo instanceof PDO) {
    // Handle case where PDO connection failed to initialize
    $error = "Database connection object is not initialized correctly.";
    $pdo = null; // Ensure $pdo is null if connection failed
}

// Initialize variables
$error = '';
$success = '';
$title = '';
$content = '';
$post_date = ''; 
$posted_by_id = $_SESSION['user_id']; 
$is_editing = false; // Flag for form mode
$current_announcement_id = null; // ID of the announcement being edited
$imageName = null; // Holds the filename for insertion/update

// CSRF Token Generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];


// ===============================================
// 1. DELETE LOGIC (Handles requests from the history table)
// ===============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $error = "Security error: Invalid request token.";
    } elseif ($pdo) {
        $id_to_delete = filter_var($_POST['announcement_id'], FILTER_VALIDATE_INT);
        if ($id_to_delete) {
            try {
                // First, fetch the image path to delete the file
                $stmt = $pdo->prepare("SELECT image FROM announcements WHERE announcement_id = ?");
                $stmt->execute([$id_to_delete]);
                $old_image = $stmt->fetchColumn();

                // Delete the record from the database
                $stmt = $pdo->prepare("DELETE FROM announcements WHERE announcement_id = ?");
                $stmt->execute([$id_to_delete]);

                // Delete the file from the server
                if (!empty($old_image)) {
                    // Check if old_image is a valid filename before attempting to unlink
                    if (preg_match('/^[a-zA-Z0-9_\-]+\.(jpg|jpeg|png)$/i', $old_image)) {
                        $imagePath = __DIR__ . '/../uploads/announcements/' . $old_image;
                        if (file_exists($imagePath)) {
                            unlink($imagePath);
                        }
                    }
                }
                
                $_SESSION['success_message'] = "Announcement deleted successfully.";
                header('Location: create_announcements.php');
                exit;

            } catch (Exception $e) {
                // Display the specific database error for debugging
                $_SESSION['error_message'] = "Failed to delete announcement. **DEBUG INFO:** " . htmlspecialchars($e->getMessage());
                header('Location: create_announcements.php');
                exit;
            }
        }
    }
}


// ===============================================
// 2. EDIT FETCH LOGIC (Populates form when edit button is clicked)
// ===============================================
if (isset($_GET['edit_id']) && $pdo) {
    $edit_id = filter_var($_GET['edit_id'], FILTER_VALIDATE_INT);
    if ($edit_id) {
        try {
            $stmt = $pdo->prepare("SELECT title, content, posted_at, image FROM announcements WHERE announcement_id = ?");
            $stmt->execute([$edit_id]);
            $announcement = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($announcement) {
                $is_editing = true;
                $current_announcement_id = $edit_id;
                $title = $announcement['title'];
                $content = $announcement['content'];
                // Format date for input type="date" (YYYY-MM-DD)
                $post_date = date('Y-m-d', strtotime($announcement['posted_at']));
                // Store the existing image name
                $imageName = $announcement['image'];
            } else {
                $error = "Announcement not found.";
            }
        } catch (Exception $e) {
            $error = "Error fetching announcement for edit. DEBUG INFO: " . htmlspecialchars($e->getMessage());
        }
    }
}

// Check for and display session messages (from successful delete/update redirect)
if (isset($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}


// ===============================================
// 3. MAIN POST LOGIC (Handles CREATE or UPDATE)
// ===============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] !== 'delete') && $pdo) {
    
    // Check if we are editing
    $oldImageName = null;
    if (isset($_POST['announcement_id'])) {
        $current_announcement_id = filter_var($_POST['announcement_id'], FILTER_VALIDATE_INT);
        if ($current_announcement_id) {
            $is_editing = true;
            // Fetch old image name if updating
            try {
                $stmt = $pdo->prepare("SELECT image FROM announcements WHERE announcement_id = ?");
                $stmt->execute([$current_announcement_id]);
                $oldImageName = $stmt->fetchColumn();
            } catch (Exception $e) {
                error_log("Error fetching old image for update: " . $e->getMessage());
            }
            // If editing, use the old image name as default unless a new file is uploaded
            $imageName = $oldImageName;
        }
    }


    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $error = "Security error: Invalid request token.";
    } else {
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $post_date = trim($_POST['post_date'] ?? ''); 
        $imageFile = $_FILES['image'] ?? null;

        if (empty($title) || empty($content)) {
            $error = "Please fill in both the title and content fields.";
        }
        
        // --- Image Upload Logic (Handles new upload, keeps old, or deletes old) ---
        if ($imageFile && $imageFile['error'] === UPLOAD_ERR_OK) {
            // New file is being uploaded. Process it.
            $allowedTypes = ['image/jpeg', 'image/png'];
            $maxFileSize = 2 * 1024 * 1024; // 2MB
            $imageTmpName = $imageFile['tmp_name'];
            
            // Secure MIME type checking
            $imageType = 'unknown';
            if (function_exists('mime_content_type')) {
                $imageType = mime_content_type($imageTmpName);
            } elseif (class_exists('finfo')) {
                // Requires the fileinfo extension to be enabled
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $imageType = finfo_file($finfo, $imageTmpName);
                finfo_close($finfo);
            }

            $imageSize = $imageFile['size'];

            if (!in_array($imageType, $allowedTypes)) {
                $error = "Only JPG and PNG images are allowed (Detected: " . $imageType . ").";
            } elseif ($imageSize > $maxFileSize) {
                $error = "Image size must be less than 2MB.";
            } else {
                // Valid file. Generate name, move it, and delete the old one if editing.
                $ext = pathinfo($imageFile['name'], PATHINFO_EXTENSION);
                $newImageName = uniqid('ann_') . '.' . $ext;
                $uploadDir = __DIR__ . '/../uploads/announcements/';
                
                // Create directory if it doesn't exist
                if (!is_dir($uploadDir)) {
                    if (!mkdir($uploadDir, 0755, true)) { 
                        $error = "Failed to create upload directory. Check system permissions.";
                    }
                }
                
                $uploadPath = $uploadDir . $newImageName;

                // Move uploaded file and check for success
                if (empty($error) && move_uploaded_file($imageTmpName, $uploadPath)) {
                    $imageName = $newImageName;
                    
                    // If successfully uploaded a new image while editing, delete the old one
                    if ($is_editing && !empty($oldImageName)) {
                        // Check if oldImageName is a valid filename before attempting to unlink
                        if (preg_match('/^[a-zA-Z0-9_\-]+\.(jpg|jpeg|png)$/i', $oldImageName)) {
                            $oldImagePath = $uploadDir . $oldImageName;
                            if (file_exists($oldImagePath)) {
                                unlink($oldImagePath);
                            }
                        }
                    }
                } else {
                    $error = "Failed to upload image. Check folder permissions.";
                }
            }
        } elseif ($is_editing && isset($_POST['clear_image']) && $_POST['clear_image'] === '1') {
            // User explicitly chose to clear the existing image
            $imageName = null;
            if (!empty($oldImageName)) {
                 // Check if oldImageName is a valid filename before attempting to unlink
                 if (preg_match('/^[a-zA-Z0-9_\-]+\.(jpg|jpeg|png)$/i', $oldImageName)) {
                    $oldImagePath = __DIR__ . '/../uploads/announcements/' . $oldImageName;
                    if (file_exists($oldImagePath)) {
                        unlink($oldImagePath);
                    }
                }
            }
        }
        // --- End Image Upload Logic ---

        if (empty($error)) {
            
            // LOGIC: Determine the final posted_at timestamp
            $date_to_insert = date('Y-m-d H:i:s'); // Default: current time

            if (!empty($post_date)) {
                // Validate and format date
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $post_date)) {
                    $date_to_insert = $post_date . ' 00:00:00'; 
                } else {
                    $error = "Invalid date format submitted.";
                }
            }

            // Ensure posted_by_id is a valid integer before use
            $posted_by_id_int = filter_var($posted_by_id, FILTER_VALIDATE_INT);
            if ($posted_by_id_int === false || $posted_by_id_int === null) {
                 $error = "Security Error: Invalid session user ID. Please log in again.";
            }


            if (empty($error)) {
                try {
                    if ($is_editing) {
                        // UPDATE QUERY
                        $sql = "UPDATE announcements SET title = ?, content = ?, posted_at = ?, image = ? WHERE announcement_id = ?";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([$title, $content, $date_to_insert, $imageName, $current_announcement_id]);
                        
                        $_SESSION['success_message'] = "Announcement updated successfully.";

                    } else {
                        // INSERT QUERY (Create New)
                        $sql = "INSERT INTO announcements (title, content, posted_by, posted_at, image) VALUES (?, ?, ?, ?, ?)";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([$title, $content, $posted_by_id_int, $date_to_insert, $imageName]);
                        
                        $display_date = date('M d, Y H:i A', strtotime($date_to_insert));
                        $_SESSION['success_message'] = "Announcement created successfully. Scheduled for: " . $display_date . ".";
                    }
                    
                    // Redirect to clear POST data and show message
                    header('Location: create_announcements.php');
                    exit;
                    
                } catch (Exception $e) {
                    error_log("Announcement DB Error: " . $e->getMessage());
                    $error = "A system error occurred while saving the announcement. **DEBUG INFO:** " . htmlspecialchars($e->getMessage());
                }
            }
        }
    }
}

// Fetch announcements for history table
try {
    if ($pdo) {
        $sql = "SELECT 
                    A.*, 
                    U.username AS poster_name   
                FROM announcements A
                JOIN users U ON A.posted_by = U.user_id
                ORDER BY posted_at DESC";
        $stmt = $pdo->query($sql);
        $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $announcements = [];
    }
} catch (Exception $e) {
    $announcements = [];
    $error = $error ?: "Failed to load announcement history. " . htmlspecialchars($e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Announcement | Secretary</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous" />
    <style>
        /* --- DESIGN TO BE MAINTAINED (PREMIUM COLORS) --- */
        :root {
            --color-primary: #004080; /* Deep Navy Blue */
            --color-accent: #DAA520; /* Goldenrod/Brass */
            --color-background: #fcfcfc; /* Off-White/Clean Background */
            --color-text: #333333;
            /* Added variables for consistent sidebar design */
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

        body { 
            margin: 0; 
            background: var(--bg-page); /* Use consistent background */
            display: flex; 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            color: var(--color-text);
            min-height: 100vh;
        }
        
        /* SIDEBAR (Replaced with consistent styles) */
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
        }
        .sidebar-header {
            padding: 25px;
            text-align: center;
            margin-bottom: 20px;
            font-size: 22px;
            font-weight: 700;
            color: var(--primary-blue);
            border-bottom: 1px solid #e9ecef;
            display: flex; justify-content: center; align-items: center; gap: 10px;
        }
        .sidebar-nav {
            flex-grow: 1; 
            padding: 0 15px;
            overflow-y: auto; /* Enables automatic scrolling */
            
            /* --- HIDDEN SCROLLBAR CSS --- */
            -ms-overflow-style: none;  /* IE and Edge */
            scrollbar-width: none;     /* Firefox */
        }
        /* Chrome, Safari, and Opera scrollbar hiding */
        .sidebar-nav::-webkit-scrollbar {
            display: none;
        }
        
        .sidebar a {
            display: flex; align-items: center; gap: 12px;
            padding: 12px 15px; margin: 8px 0;
            color: var(--text-dark); font-weight: 500;
            text-decoration: none;
            border-radius: 8px;
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        .sidebar a:hover {
            background-color: rgba(0, 123, 255, 0.08);
            color: var(--primary-blue);
        }
        .sidebar a.active {
            background-color: var(--primary-blue);
            color: white; font-weight: 600;
            box-shadow: 0 4px 8px rgba(0, 123, 255, 0.2);
        }
        .sidebar a i {
            font-size: 18px; color: var(--text-light); transition: color 0.3s ease;
        }
        .sidebar a.active i { color: white; }
        
        /* LOGOUT ALIGNMENT CSS (from previous step) */
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
        .sidebar .logout i {
            color: var(--text-light);
            transition: color 0.3s ease;
        }
        .sidebar .logout:hover i {
            color: var(--alert-red);
        }
        /* End of Sidebar Styles */


        /* --- LAYOUT AND CLEANLINESS IMPROVEMENTS --- */
        
        main.main-content { 
            flex-grow: 1; 
            margin-left: var(--sidebar-width); 
            padding: 50px 80px; 
            min-height: 100vh; 
            box-sizing: border-box; 
        }
        
        h1 { 
            color: var(--color-primary); 
            font-size: 2.2rem; 
            font-weight: 800;
            margin-bottom: 40px;
        }

        /* Layout Container */
        .page-container {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 40px;
            background: white;
            padding: 40px;
            border-radius: 15px; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.1); 
            max-width: 1200px;
            margin: 0 auto 50px;
            border-top: 5px solid var(--color-primary);
        }

        .form-section {
            padding-right: 20px;
            border-right: 1px solid #eee;
        }
        
        .preview-section {
            padding-left: 20px;
        }

        .preview-section h3 {
            color: var(--color-primary);
            margin-top: 0;
            margin-bottom: 15px;
            font-weight: 600;
            font-size: 1.5rem;
            border-bottom: 1px solid var(--color-primary);
            padding-bottom: 5px;
        }

        /* Form Controls */
        form label { 
            display: block; 
            margin-bottom: 8px; 
            font-weight: 700; 
            color: var(--color-primary); 
            font-size: 1.1rem; 
        }
        
        form input:not([type="submit"]):not([type="button"]):not([type="file"]), form textarea { 
            width: 100%; 
            padding: 12px 15px; 
            border: 1px solid #ddd; 
            border-radius: 8px; 
            margin-bottom: 25px; 
            font-size: 16px; 
            transition: border-color 0.3s; 
        }
        form input[type="file"] {
            margin-bottom: 25px; 
        }
        form input:focus, form textarea:focus { 
            border-color: var(--color-accent); 
            box-shadow: 0 0 8px rgba(218,165,32,0.2); 
            outline: none; 
        }
        
        /* Publish Button - Accent Colored */
        .submit-button { 
            background-color: var(--color-primary);
            color: white; 
            padding: 14px 30px; 
            border: none; 
            border-radius: 8px; 
            font-size: 1.2rem; 
            font-weight: 700; 
            cursor: pointer; 
            display: block; 
            width: 100%; 
            margin-top: 20px; 
            transition: background-color 0.3s, box-shadow 0.3s;
        }
        .submit-button:hover { 
            background-color: #002e5f;
            box-shadow: 0 4px 10px rgba(0, 64, 128, 0.3);
        }
        
        /* Messages */
        .message { 
            font-weight: 600; 
            padding: 15px; 
            border-radius: 10px; 
            margin-bottom: 30px; 
            text-align: center; 
            font-size: 1.05rem;
        }
        .error { background-color: #fcebeb; color: #a94442; border: 1px solid #ebcccc; }
        .success { background-color: #e0f2f0; color: #3c763d; border: 1px solid #d6e9c6; }
        
        /* Image Preview Box */
        .image-preview-box {
            border: 2px dashed #ccc;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            background: #f9f9f9;
        }

        #imagePreview { 
            display: block;
            width: 100%;
            height: auto;
            max-height: 250px;
            object-fit: cover;
            border-radius: 8px;
            margin-top: 10px;
            border: 1px solid #ddd;
        }
        #imagePreview.hidden {
            display: none;
        }

        /* Current image management UI when editing */
        .current-image-container {
            margin-bottom: 25px;
            padding: 10px;
            border: 1px solid #eee;
            border-radius: 6px;
            background-color: #fff;
        }
        .current-image-container img {
            max-width: 100%;
            height: auto;
            display: block;
            margin: 5px 0 10px;
            border-radius: 4px;
        }
        .current-image-container label {
            font-weight: normal;
            display: flex;
            align-items: center;
            font-size: 0.9em;
            color: #555;
        }
        .current-image-container input[type="checkbox"] {
            margin-right: 8px;
            width: auto;
            margin-bottom: 0;
        }


        /* History Table - Professional and Light */
        .history-section h2 { 
            margin-top: 60px; 
            color: var(--color-primary); 
            font-size: 1.8rem; 
            border-bottom: 2px solid var(--color-primary);
            padding-bottom: 10px;
        }
        .history-table { 
            width: 100%; 
            border-collapse: separate; 
            border-spacing: 0; 
            margin-top: 25px; 
            background: white; 
            border-radius: 12px; 
            overflow: hidden; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.05); 
        }
        .history-table th, .history-table td { 
            padding: 15px 20px; 
            border-bottom: 1px solid #f0f0f0; 
            text-align: left; 
            vertical-align: middle; 
        }
        .history-table th { 
            background-color: var(--color-primary); 
            color: white; 
            font-weight: 700; 
            text-transform: uppercase;
            font-size: 0.9rem;
        }
        .history-table tbody tr:hover { 
            background-color: #fafafa; 
        }
        .history-table td img { 
            border: 1px solid #eee; 
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        /* Action Buttons */
        .action-link {
            padding: 6px 10px;
            text-decoration: none;
            border-radius: 4px;
            display: inline-block;
            margin-right: 5px;
            font-size: 0.9rem;
            font-weight: 600;
        }
        .edit-btn { background-color: var(--color-accent); color: white; }
        .edit-btn:hover { background-color: #c9961f; }
        
        .delete-btn { 
            background: none; 
            border: none; 
            color: #a94442; 
            cursor: pointer; 
            padding: 0; 
            margin: 0; 
            font-size: 0.9rem;
            font-weight: 600;
            text-decoration: underline;
        }
        .delete-btn:hover { color: #8d3a37; }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-header">
        <i class="fas fa-tooth"></i> DentiTrack
    </div>
    <nav class="sidebar-nav">
        <a href="secretary_dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
        <a href="find_patient.php" ><i class="fas fa-search"></i> Find Patient</a>
        <a href="view_patients.php"><i class="fas fa-users"></i> Patients List</a>
        <a href="online_bookings.php"><i class="fas fa-calendar-check"></i> Booking Mgmt</a>
        <a href="appointments.php"><i class="fas fa-calendar-alt"></i> Consultations</a>
        <a href="services_list.php"><i class="fas fa-briefcase-medical"></i> Services</a>
        <a href="inventory.php"><i class="fas fa-boxes"></i> Inventory Stock</a>
        <a href="payments.php"><i class="fas fa-money-bill-wave"></i> Process Payments</a>
         <a href="pending_installments.php" ><i class="fas fa-credit-card"></i> Pending Installments</a>
         <a href="pending_payments.php"><i class="fas fa-credit-card"></i> Pending Payments</a>
        <a href="payment_logs.php" ><i class="fas fa-file-invoice-dollar"></i> Payments Log</a>
        <a href="create_announcements.php" class = "active" ><i class="fas fa-bullhorn"></i> Announcements</a>
    </nav>
    <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Log Out</a>
</div>
<main class="main-content">
    <h1><i class="fas fa-bullhorn"></i> Clinic Communications & Announcements</h1>

    <?php if ($error): ?>
        <div class="message error"><i class="fas fa-times-circle"></i> <?= htmlspecialchars($error) ?></div>
    <?php elseif ($success): ?>
        <div class="message success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="page-container">
        
        <div class="form-section">
            <h2 style="color: var(--color-primary); font-weight: 600; margin-bottom: 30px; font-size: 1.5rem; margin-top: 0;">
                <i class="fas fa-<?= $is_editing ? 'edit' : 'plus' ?>"></i> 
                <?= $is_editing ? 'Edit Existing Announcement (ID: ' . $current_announcement_id . ')' : 'Publish New Communication' ?>
            </h2>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                
                <?php if ($is_editing): ?>
                    <input type="hidden" name="announcement_id" value="<?= htmlspecialchars($current_announcement_id) ?>">
                <?php endif; ?>


                <label for="title">Announcement Title</label>
                <input type="text" id="title" name="title" value="<?= htmlspecialchars($title) ?>" required placeholder="E.g., Updated Holiday Schedule, New Service Launch" />

                <label for="content">Detailed Content</label>
                <textarea id="content" name="content" rows="10" required placeholder="Provide all necessary details for patients and staff..."><?= htmlspecialchars($content) ?></textarea>
                
                <label for="post_date">Scheduled Post Date (Optional)</label>
                <input type="date" id="post_date" name="post_date" 
                        value="<?= htmlspecialchars($post_date) ?>" 
                        min="<?= date('Y-m-d') ?>" 
                        style="width: calc(100% - 30px);" />

                <?php if ($is_editing && !empty($imageName)): ?>
                    <div class="current-image-container">
                        <label>Current Image:</label>
                        <img src="../uploads/announcements/<?= htmlspecialchars($imageName) ?>" alt="Current Image" style="max-height: 100px; object-fit: cover;">
                        <label>
                            <input type="checkbox" name="clear_image" value="1" />
                            Check to **remove**** this image
                        </label>
                        <p style="font-size: 0.9em; color: #004080; margin-top: 10px;">To **replace** the image, upload a new one below.</p>
                    </div>
                <?php endif; ?>

                <label for="image">Attach Visual (Optional: JPG or PNG, max 2MB)</label>
                <input type="file" id="image" name="image" accept="image/png, image/jpeg" />

                <button type="submit" class="submit-button">
                    <i class="fas fa-upload"></i> 
                    <?= $is_editing ? 'Update Announcement' : 'Publish Communication' ?>
                </button>
            </form>
        </div>

        <div class="preview-section">
            <h3 style="color: var(--color-primary); font-weight: 600; font-size: 1.5rem; margin-top: 0;">Visual Attachment Preview</h3>
            
            <div class="image-preview-box">
                <p style="color: #666; font-size: 0.9em;">Image Preview will appear here after selection.</p>
                <?php 
                // Determine initial preview source if editing
                $preview_src = ($is_editing && !empty($imageName)) ? '../uploads/announcements/' . htmlspecialchars($imageName) : '';
                $preview_class = ($is_editing && !empty($imageName)) ? '' : 'hidden';
                ?>
                <img id="imagePreview" alt="Image Preview" src="<?= $preview_src ?>" class="<?= $preview_class ?>" />
            </div>
            
        </div>

    </div>

    <div class="history-section" style="max-width: 1200px; margin: 0 auto;">
        <h2><i class="fas fa-history"></i> Recent Publication History</h2>
        <div style="overflow-x: auto;">
        <table class="history-table">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Posted By</th>
                    <th>Date & Time</th>
                    <th>Visual</th>
                    <th>Actions</th> </tr>
            </thead>
            <tbody>
                <?php if (empty($announcements)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; color: #666; padding: 20px;">No announcements have been published yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($announcements as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['title']) ?></td>
                            <td><?= htmlspecialchars($row['poster_name'] ?? 'User ID: ' . $row['posted_by']) ?></td>
                            <td><?= date('M d, Y H:i A', strtotime($row['posted_at'])) ?></td>
                            <td>
                                <?php 
                                // Uses the correct 'image' column
                                if (!empty($row['image'])): 
                                ?>
                                    <a href="../uploads/announcements/<?= htmlspecialchars($row['image']) ?>" target="_blank">
                                        <img src="../uploads/announcements/<?= htmlspecialchars($row['image']) ?>" alt="Image" style="max-width: 80px; max-height: 80px; object-fit: cover; border-radius: 6px;" />
                                    </a>
                                <?php else: ?>
                                    <em>None</em>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="?edit_id=<?= $row['announcement_id'] ?>" class="action-link edit-btn"><i class="fas fa-edit"></i> Edit</a>
                                
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete the announcement titled: \'<?= addslashes(htmlspecialchars($row['title'])) ?>\'? This action is irreversible.');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="announcement_id" value="<?= $row['announcement_id'] ?>">
                                    <button type="submit" class="delete-btn"><i class="fas fa-trash"></i> Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</main>

<script>
    // JavaScript for image preview and client-side validation
    const imageInput = document.getElementById('image');
    const imagePreview = document.getElementById('imagePreview');
    const imagePreviewBoxText = document.querySelector('.image-preview-box p');
    const MAX_SIZE_MB = 2;
    const MAX_SIZE_BYTES = MAX_SIZE_MB * 1024 * 1024;

    imageInput.addEventListener('change', () => {
        const file = imageInput.files[0];
        
        if (!file) {
            // Only hide if the preview is for the newly selected file (i.e., not an existing image being edited)
            if (imagePreview.getAttribute('src') === "" || imagePreview.getAttribute('src').endsWith('announcements/')) {
                 imagePreview.classList.add('hidden');
                 imagePreviewBoxText.style.display = 'block';
            }
            return;
        }

        const allowedTypes = ['image/jpeg', 'image/png'];
        
        if (!allowedTypes.includes(file.type)) {
            alert('Error: Only JPG and PNG images are allowed.');
            imageInput.value = ''; 
            imagePreview.classList.add('hidden');
            imagePreviewBoxText.style.display = 'block';
            return;
        }
        
        if (file.size > MAX_SIZE_BYTES) {
            alert(`Error: Image size must be less than ${MAX_SIZE_MB}MB.`);
            imageInput.value = ''; 
            imagePreview.classList.add('hidden');
            imagePreviewBoxText.style.display = 'block';
            return;
        }

        const reader = new FileReader();
        reader.onload = e => {
            imagePreview.src = e.target.result;
            imagePreview.classList.remove('hidden');
            imagePreviewBoxText.style.display = 'none';
        };
        reader.readAsDataURL(file);
    });
</script>

</body>
</html>