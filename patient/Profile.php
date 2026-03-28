<?php
session_start();

// Check if user is logged in and has the 'patient' role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') {
    header("Location: ../public/login.php");
    exit();
}

// Ensure the correct database connection file (using mysqli) is included
// IMPORTANT: This file must establish a $conn object using mysqli.
include '../config/db.php'; 

$user_id = $_SESSION['user_id'];
$message = "";
$patient = []; // Initialize $patient as an empty array for safety

/* ======================================================
    FETCH USER + PATIENT DETAILS (WITH PROFILE IMAGE)
====================================================== */
// Assuming a mysqli connection object $conn is available from db.php
$sql = "SELECT 
            u.username,
            u.email,
            u.password_hash,
            p.fullname,
            p.contact_number,
            p.address,
            p.dob,
            p.gender,
            p.profile_image,
            p.emergency_contact_name,
            p.emergency_contact_number
        FROM users u
        INNER JOIN patient p ON u.user_id = p.user_id
        WHERE u.user_id = ?";

// Use standard error handling for mysqli prepared statements
if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $patient = $result->fetch_assoc();
    $stmt->close();
} else {
    // Show the actual database error to aid in debugging missing tables/columns
    $message = "<div class='error-msg'>Database Error: Could not fetch profile data. MySQL Error: " . htmlspecialchars($conn->error) . "</div>";
}

// Ensure $patient is always an array so array access doesn't fail later in HTML
if (!is_array($patient)) {
    // Fallback data if the initial fetch failed completely
    $patient = [
        'fullname' => 'N/A', 
        'username' => 'N/A', 
        'email' => 'N/A', 
        'password_hash' => '',
        'contact_number' => 'N/A', 
        'address' => 'N/A', 
        'dob' => 'N/A', 
        'gender' => 'N/A', 
        'profile_image' => '',
        'emergency_contact_name' => 'N/A',
        'emergency_contact_number' => 'N/A'
    ];
}

/* ======================================================
    UPLOAD PROFILE IMAGE
====================================================== */
if (isset($_POST['upload_image'])) {
    // Re-fetch $patient data if not done after initial page load (optional but safer)
    // For simplicity, we trust the $patient array built above is sufficient.
    
    if (!empty($_FILES['profile_image']['name'])) {
        $allowed_ext = ['jpg', 'jpeg', 'png'];
        $file_name = $_FILES['profile_image']['name'];
        $file_tmp = $_FILES['profile_image']['tmp_name'];

        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed_ext)) {
            $message = "<div class='error-msg'>Only JPG, JPEG, PNG files are allowed.</div>";
        } else {
            // Create folder if not exists
            $upload_dir = "../uploads/patient_profile/";
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            // Unique file name
            $new_filename = "patient_" . $user_id . "_" . time() . "." . $ext;
            $upload_path = $upload_dir . $new_filename;

            if (move_uploaded_file($file_tmp, $upload_path)) {
                // Optionally delete previous image
                if (!empty($patient['profile_image']) && $patient['profile_image'] !== 'default_profile.png') {
                    $old = $upload_dir . $patient['profile_image'];
                    if (is_file($old)) {
                        @unlink($old);
                    }
                }

                // Update DB
                $sql = "UPDATE patient SET profile_image = ?, updated_at = NOW() WHERE user_id = ?";
                
                if ($stmt = $conn->prepare($sql)) {
                    $stmt->bind_param("si", $new_filename, $user_id);
                    if ($stmt->execute()) {
                         $message = "<div class='success-msg'>Profile picture uploaded successfully!</div>";
                         // Refresh patient info
                         $patient['profile_image'] = $new_filename;
                    } else {
                        $message = "<div class='error-msg'>Failed to update database record: " . htmlspecialchars($stmt->error) . "</div>";
                    }
                    $stmt->close();
                } else {
                    $message = "<div class='error-msg'>Database error on image update.</div>";
                }
            } else {
                $message = "<div class='error-msg'>Failed to upload file.</div>";
            }
        }
    } else {
        $message = "<div class='error-msg'>Please select a file to upload.</div>";
    }
}

/* ======================================================
    UPDATE USERNAME
====================================================== */
if (isset($_POST['update_username'])) {
    $new_username = trim($_POST['new_username']);
    
    if (empty($new_username)) {
        $message = "<div class='error-msg'>Username cannot be empty.</div>";
    } else {
        $sql = "UPDATE users SET username = ?, updated_at = NOW() WHERE user_id = ?";
        
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("si", $new_username, $user_id);

            if ($stmt->execute()) {
                $_SESSION['username'] = $new_username;
                $patient['username'] = $new_username; // Update local array immediately
                $message = "<div class='success-msg'>Username updated successfully!</div>";
            } else {
                // Handle unique constraint error if username already exists
                if ($conn->errno === 1062) {
                    $message = "<div class='error-msg'>Failed to update username. The chosen username is already taken.</div>";
                } else {
                    $message = "<div class='error-msg'>Failed to update username. " . htmlspecialchars($stmt->error) . "</div>";
                }
            }
            $stmt->close();
        } else {
            $message = "<div class='error-msg'>Database error on username update.</div>";
        }
    }
}

/* ======================================================
    UPDATE PASSWORD
====================================================== */
if (isset($_POST['update_password'])) {
    $current_pass = $_POST['current_password'] ?? '';
    $new_pass = $_POST['new_password'] ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';

    if (!isset($patient['password_hash']) || !password_verify($current_pass, $patient['password_hash'])) {
        $message = "<div class='error-msg'>Incorrect current password.</div>";
    } 
    elseif ($new_pass !== $confirm_pass) {
        $message = "<div class='error-msg'>New passwords do not match.</div>";
    } 
    elseif (strlen($new_pass) < 6) {
        $message = "<div class='error-msg'>New password must be at least 6 characters long.</div>";
    }
    else {
        $hashed_new_pass = password_hash($new_pass, PASSWORD_DEFAULT);

        $sql = "UPDATE users SET password_hash = ?, updated_at = NOW() WHERE user_id = ?";
        
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("si", $hashed_new_pass, $user_id);

            if ($stmt->execute()) {
                $patient['password_hash'] = $hashed_new_pass; // Update local array immediately
                $message = "<div class='success-msg'>Password updated successfully!</div>";
            } else {
                $message = "<div class='error-msg'>Failed to update password. " . htmlspecialchars($stmt->error) . "</div>";
            }
            $stmt->close();
        } else {
            $message = "<div class='error-msg'>Database error on password update.</div>";
        }
    }
}

// Close the database connection
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Patient Profile - DentiTrack</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<style>
/* --- STYLES ALIGNED WITH SECRETARY FIND PATIENT (Responsive & Modern) --- */
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
.sidebar .logout i { color: var(--text-light); transition: color 0.3s ease; }
.sidebar .logout:hover i { color: var(--alert-red); }

/* --- Main Content Styling --- */
.main-content { 
    flex: 1; 
    margin-left: var(--sidebar-width); 
    padding: 40px; 
    background: var(--bg-page); 
    overflow-y: auto; 
    max-width: var(--max-width); /* Added max width for content card */
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
.header .meta {
    color: var(--text-light); 
    font-size: 0.9rem;
}

/* Message/Error Boxes */
.success-msg, .error-msg {
    padding: 12px 20px;
    margin-bottom: 20px;
    border-radius: 8px;
    font-weight: 600;
    text-align: center;
    display: flex;
    align-items: center;
    gap: 10px;
    justify-content: center;
}
.success-msg {
    background: #e6ffed; /* Light green */
    border: 1px solid #95de64;
    color: #092b00;
}
.error-msg {
    background: #fff1f0; /* Light red */
    border: 1px solid #ffa39e;
    color: #a8071a;
}

/* --- Profile Card Styling --- */
.profile-container { 
    display: flex; 
    gap: 24px; 
    align-items: flex-start; 
    flex-wrap: wrap; 
}
.profile-card {
    background: var(--widget-bg);
    padding: 30px;
    border-radius: var(--radius);
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    flex: 1 1 450px;
}
.profile-media { 
    display: flex; 
    gap: 20px; 
    align-items: center; 
    margin-bottom: 20px;
    padding-bottom: 20px;
    border-bottom: 1px solid #eee;
}
.profile-img {
    width: 100px; 
    height: 100px; 
    border-radius: 50%; 
    object-fit: cover; 
    border: 5px solid rgba(0, 123, 255, 0.1); 
    box-shadow: 0 4px 10px rgba(0,0,0,0.08);
}
.profile-info h2 { 
    margin: 0 0 8px 0; 
    font-size: 1.5rem; 
    color: var(--primary-blue); 
}
.profile-info p { 
    margin: 4px 0; 
    color: var(--text-light); 
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    gap: 8px;
}
.profile-info p i {
    color: var(--primary-blue);
}

/* Details Grid */
.details-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px 30px;
    margin-top: 15px;
}
.detail-item .label {
    color: var(--text-light);
    font-size: 0.85rem;
    font-weight: 600;
    margin-bottom: 4px;
    display: block;
}
.detail-item .value {
    font-size: 1rem;
    color: var(--text-dark);
    font-weight: 500;
}

/* Actions and Buttons */
.actions { 
    margin-top: 20px; 
    display: flex; 
    gap: 10px; 
    flex-wrap: wrap; 
}
.btn {
    display: inline-flex; 
    align-items: center; 
    gap: 10px; 
    border: 0; 
    padding: 10px 18px; 
    border-radius: 8px; 
    cursor: pointer;
    font-weight: 600; 
    color: #fff;
    font-size: 0.9rem;
    transition: background-color 0.3s;
}
.btn-primary { 
    background: var(--primary-blue); 
    box-shadow: 0 4px 8px rgba(0, 123, 255, 0.2);
}
.btn-primary:hover { background: var(--secondary-blue); }
.btn-ghost { 
    background: transparent; 
    color: var(--primary-blue); 
    border: 2px solid rgba(0, 123, 255, 0.2); 
    font-weight: 600; 
}
.btn-ghost:hover { 
    background: rgba(0, 123, 255, 0.05); 
    border-color: var(--primary-blue); 
}
.btn-secondary{ 
    background: var(--accent-orange); 
    color: var(--text-dark);
}
.btn-secondary:hover { background: #e0a800; }


/* --- Side Card (Quick Info) --- */
.side-card {
    width: 300px;
    background: var(--widget-bg);
    padding: 25px;
    border-radius: var(--radius);
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    flex-shrink: 0;
}
.side-card h3 { 
    margin: 0 0 15px 0; 
    font-size: 1.1rem; 
    color: var(--primary-blue); 
    border-bottom: 1px solid #eee;
    padding-bottom: 10px;
}
.side-list { 
    display: flex; 
    flex-direction: column; 
    gap: 12px; 
}
.side-item { 
    background: var(--bg-page); 
    padding: 12px; 
    border-radius: 8px; 
    display: flex; 
    justify-content: space-between; 
    align-items: center; 
}
.side-item .small-muted { 
    color: var(--text-light); 
    font-size: 0.8rem; 
    font-weight: 500;
}
.side-item .value {
    font-weight: 600;
    color: var(--text-dark);
}

/* --- MODAL STYLES (Aligned with Find Patient) --- */
.modal { 
    display: none; 
    position: fixed; 
    z-index: 9999; 
    left: 0; 
    top: 0; 
    width: 100%; 
    height: 100%; 
    background: rgba(0,0,0,0.6); 
    align-items: center; 
    justify-content: center; 
    padding: 20px;
}
.dialog{
    /* Set a maximum height for the modal container */
    max-height: 90%; 
    /* Use flex to allow the modal body to grow and handle overflow */
    display: flex; 
    flex-direction: column;
}
.modal .dialog { 
    width: 50%; 
    max-width: 760px; 
    background: var(--widget-bg); 
    border-radius: var(--radius); 
    overflow: hidden; 
    box-shadow: 0 20px 60px rgba(2,6,23,0.5); 
    animation: pop .18s ease; 
}
@keyframes pop { from{transform:translateY(8px) scale(.98);opacity:0} to{transform:none;opacity:1} }
.modal .dialog .head { 
    display: flex; 
    align-items: center; 
    justify-content: space-between; 
    padding: 18px 20px; 
    border-bottom: 1px solid #e9ecef; 
    /* Prevent shrinking */
    flex-shrink: 0;
}
.modal .dialog .head h3 { 
    margin: 0; 
    font-size: 1.1rem; 
    color: var(--primary-blue); 
}
.modal .dialog .head .close { 
    background: transparent; 
    border: 0; 
    font-size: 24px; 
    cursor: pointer; 
    color: var(--text-light); 
}
.modal .dialog .head .close:hover { color: var(--alert-red); }

/* tabs */
.tabs { 
    display: flex; 
    gap: 8px; 
    padding: 12px 20px; 
    background: #f8f9fa; 
    border-bottom: 1px solid #e9ecef; 
    flex-shrink: 0;
}
.tab-btn { 
    padding: 10px 14px; 
    border-radius: 8px; 
    cursor: pointer; 
    background: transparent; 
    border: 1px solid transparent; 
    font-weight: 600; 
    color: var(--text-light); 
    transition: all 0.2s ease;
}
.tab-btn:hover { color: var(--primary-blue); }
.tab-btn.active { 
    background: var(--primary-blue); 
    color: #fff; 
    box-shadow: 0 2px 5px rgba(0, 123, 255, 0.2);
}

/* modal body - SCROLLING IMPLEMENTED HERE */
.modal-body { 
    padding: 10px; 
    display: grid; 
    grid-template-columns: 1fr; 
    gap: 10px; 
    /* Key additions for scrolling: */
    overflow-y: auto; /* Enable vertical scrolling when content exceeds height */
    flex-grow: 1; /* Allow the body to take up available height */
    -ms-overflow-style: none; /* IE and Edge */
    scrollbar-width: none; /* Firefox */
}
/* Webkit (Chrome, Safari) specific scrollbar removal */
.modal-body::-webkit-scrollbar { 
    display: none; 
}

/* file preview */
.preview { 
    display: flex; 
    flex-direction: column; 
    align-items: center; 
    gap: 15px; 
    margin-bottom: 15px;
}
.preview img { 
    width: 120px; 
    height: 120px; 
    border-radius: 50%; 
    object-fit: cover; 
    border: 4px solid var(--primary-blue);
    box-shadow: 0 6px 15px rgba(0, 123, 255, 0.1); 
}

/* forms inside modal */
.modal form { 
    display: flex; 
    flex-direction: column; 
    gap: 12px; 
}
.modal label { 
    font-size: 0.8rem; 
    color: var(--text-dark); 
    font-weight: 700; 
}
.modal input[type="text"], 
.modal input[type="password"], 
.modal input[type="file"] { 
    padding: 10px; 
    border-radius: 6px; 
    border: 1px solid #ced4da; 
    font-size: 0.9rem; 
    width: 100%; 
}
.modal button { 
    padding: 10px 15px; 
    border-radius: 8px; 
    border: 0; 
    cursor: pointer;
    font-weight: 600;
}
.modal button.btn-primary { background: var(--primary-blue); color: white; }
.modal button.btn-secondary { background: var(--accent-orange); color: var(--text-dark); }
.modal button.btn-ghost { 
    background: transparent; 
    color: var(--text-light); 
    border: 1px solid #ced4da;
}
.modal button:hover { opacity: 0.9; }

/* responsive adjustments for profile page */
@media (max-width: 992px) {
    .sidebar { 
        width: 80%;
        max-width: 300px;
        transform: translateX(-100%);
    }
    .sidebar.open {
        transform: translateX(0);
        box-shadow: 5px 0 15px rgba(0,0,0,0.2);
    }
    .main-content {
        margin-left: 0;
        padding: 20px;
    }
    .profile-container { 
        flex-direction: column; 
        gap: 20px;
    }
    .profile-card, .side-card {
        width: 100%;
        flex-basis: auto;
    }
    .details-grid {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    .profile-media {
        flex-direction: column;
        text-align: center;
    }
    .profile-info {
        text-align: center;
    }
    .profile-info p {
        justify-content: center;
    }
    .actions {
        justify-content: center;
    }
    .modal-body { 
        grid-template-columns: 1fr; 
    }

    /* Mobile Header/Menu */
    .mobile-header { 
        display: flex;
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
    .sidebar-header button {
        display: none !important; /* Hide close button on desktop style */
    }
    .sidebar.open .sidebar-header button {
        display: block !important;
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

<aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <div class="sidebar-header">
        <i class="fas fa-tooth"></i> DentiTrack
        <button id="menu-toggle-close" style="display: none;"><i class="fas fa-times"></i></button>
    </div>
    <nav class="sidebar-nav">
        <a class="nav-link" href="patient_dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
        <a class="nav-link" href="patient_appointments.php"><i class="fas fa-calendar-alt"></i> Appointments</a>
        <a class="nav-link" href="patient_records.php"><i class="fas fa-file-medical"></i> Records</a>
        <a href="patient_payment.php"><i class="fas fa-credit-card"></i> Payments</a>
        <a class="nav-link active" href="patient_profile.php"><i class="fas fa-user"></i> Profile</a>
    </nav>
    <a class="logout" href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
</aside>

<main class="main-content" role="main">
    <header>
        <h1><i class="fas fa-user-circle"></i> Patient Profile</h1>
        <div class="meta">Welcome back, <strong><?= htmlspecialchars($patient['fullname'] ?? $_SESSION['username']); ?></strong></div>
    </header>

    <div class="message">
        <?= $message; ?>
    </div>

    <div class="profile-container">
        <section class="profile-card" aria-labelledby="profile-heading">
            <div class="profile-media">
                <?php
                    // Use the initialized $patient array safely
                    $img_path = (!empty($patient['profile_image'])) ? "../uploads/patient_profile/" . htmlspecialchars($patient['profile_image']) : "../assets/default_profile.png";
                ?>
                <img src="<?= $img_path ?>" alt="Profile image" class="profile-img" id="currentProfileImg">

                <div class="profile-info">
                    <h2 id="profile-heading"><?= htmlspecialchars($patient['fullname'] ?? $patient['username']); ?></h2>
                    <p><i class="fas fa-user"></i> <?= htmlspecialchars($patient['username']); ?></p>
                    <p><i class="fas fa-envelope"></i> <?= htmlspecialchars($patient['email']); ?></p>
                    <div class="actions">
                        <button class="btn btn-primary" onclick="openModal()"><i class="fas fa-edit"></i> Edit Account</button>
                        <a href='patient_appointments.php' class="btn btn-ghost"><i class="fas fa-calendar-plus"></i> Book Appointment</a>
                    </div>
                </div>
            </div>
            
            <h3 style="color:var(--text-dark); border-bottom:1px solid #eee; padding-bottom:10px; margin-top:0;"><i class="fas fa-id-card"></i> Personal Details</h3>
            <div class="details-grid">
                <div class="detail-item">
                    <span class="label">Contact Number</span>
                    <span class="value"><?= htmlspecialchars($patient['contact_number']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Date of Birth</span>
                    <span class="value"><?= htmlspecialchars($patient['dob']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Gender</span>
                    <span class="value"><?= htmlspecialchars($patient['gender']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Address</span>
                    <span class="value"><?= htmlspecialchars($patient['address']); ?></span>
                </div>
            </div>

            <h3 style="color:var(--text-dark); border-bottom:1px solid #eee; padding-bottom:10px; margin-top:30px;"><i class="fas fa-exclamation-triangle"></i> Emergency Contact</h3>
            <div class="details-grid">
                 <div class="detail-item">
                    <span class="label">Contact Person</span>
                    <span class="value"><?= htmlspecialchars($patient['emergency_contact_name'] ?? 'N/A'); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Contact Number</span>
                    <span class="value"><?= htmlspecialchars($patient['emergency_contact_number'] ?? 'N/A'); ?></span>
                </div>
            </div>
        </section>

        <aside class="side-card" aria-label="Quick info">
            <h3><i class="fas fa-info-circle"></i> Account Info</h3>
            <div class="side-list">
                <div class="side-item">
                    <div>
                        <div class="small-muted">Patient ID</div>
                        <div class="value"><?= htmlspecialchars($user_id); ?></div>
                    </div>
                    <div><i class="fas fa-id-badge" style="color:var(--primary-blue)"></i></div>
                </div>

                <div class="side-item">
                    <div>
                        <div class="small-muted">Account Role</div>
                        <div class="value"><?= htmlspecialchars($_SESSION['role']); ?></div>
                    </div>
                    <div><i class="fas fa-user-tag" style="color:var(--primary-blue)"></i></div>
                </div>
            </div>
        </aside>
    </div>

</main>

<div id="editModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="editTitle">
    <div class="dialog" role="document">
        <div class="head">
            <h3 id="editTitle"><i class="fas fa-user-edit"></i> Edit Profile</h3>
            <button class="close" aria-label="Close" onclick="closeModal()">&times;</button>
        </div>

        <div class="tabs" role="tablist" aria-label="Edit sections">
            <button class="tab-btn active" data-tab="tab-photo" role="tab" aria-selected="true">Profile Photo</button>
            <button class="tab-btn" data-tab="tab-account" role="tab" aria-selected="false">Account (Username/Password)</button>
        </div>

        <div class="modal-body">
            <div id="tab-photo" class="form-card" role="tabpanel">
                <div class="preview" aria-hidden="false">
                    <img id="previewImg" src="<?= $img_path ?>" alt="Current profile preview">
                    <small style="color: var(--text-light); font-size: 0.8rem;">Accepted: JPG, PNG • Max 2MB recommended</small>
                </div>

                <form method="POST" enctype="multipart/form-data" onsubmit="return confirmUpload()">
                    <label for="profile_image">Select new profile picture</label>
                    <input id="profile_image" type="file" name="profile_image" accept="image/*" required onchange="previewFile(event)">
                    <div style="display:flex; gap:10px; margin-top:8px;">
                        <button type="submit" name="upload_image" class="btn btn-primary"><i class="fas fa-upload"></i> Upload</button>
                        <button type="button" class="btn btn-ghost" onclick="resetPreview()">Cancel</button>
                    </div>
                </form>
            </div>

            <div id="tab-account" class="form-card" role="tabpanel" aria-hidden="true" style="display:none;">
                <form method="POST" style="margin-bottom:20px; border-bottom: 1px solid #eee; padding-bottom: 20px;">
                    <h4 style="margin: 0 0 10px 0; color:var(--primary-blue);">Update Username</h4>
                    <label for="new_username">New Username</label>
                    <input id="new_username" type="text" name="new_username" required placeholder="<?= htmlspecialchars($patient['username']); ?>">
                    <div style="display:flex; gap:10px; margin-top:12px;">
                        <button type="submit" name="update_username" class="btn btn-primary"><i class="fas fa-user-edit"></i> Update Username</button>
                        <button type="button" class="btn btn-ghost" onclick="fillUsername()">Fill current</button>
                    </div>
                </form>

                <form method="POST">
                    <h4 style="margin: 0 0 10px 0; color:var(--primary-blue);">Update Password</h4>
                    <label for="current_password">Current Password</label>
                    <input id="current_password" type="password" name="current_password" required>

                    <label for="new_password">New Password</label>
                    <input id="new_password" type="password" name="new_password" required>

                    <label for="confirm_password">Confirm New Password</label>
                    <input id="confirm_password" type="password" name="confirm_password" required>

                    <div style="display:flex; gap:10px; margin-top:12px;">
                        <button type="submit" name="update_password" class="btn btn-secondary"><i class="fas fa-lock"></i> Update Password</button>
                        <button type="button" class="btn btn-ghost" onclick="clearPasswordFields()">Clear</button>
                    </div>
                </form>
            </div>
        </div>

    </div>
</div>

<script>
// 1. MODAL CONTROLS
function openModal(){
    document.getElementById('editModal').style.display = 'flex';
    // default to photo tab
    activateTab(document.querySelector('.tab-btn.active'));
}
function closeModal(){
    document.getElementById('editModal').style.display = 'none';
}

// 2. TAB SWITCHING LOGIC
document.querySelectorAll('.tab-btn').forEach(btn=>{
    btn.addEventListener('click', ()=> activateTab(btn));
});
function activateTab(btn){
    document.querySelectorAll('.tab-btn').forEach(b=>{
        b.classList.remove('active');
        b.setAttribute('aria-selected','false');
    });
    btn.classList.add('active');
    btn.setAttribute('aria-selected','true');

    // hide all panels
    document.querySelectorAll('[role="tabpanel"]').forEach(p=>{
        p.style.display = 'none';
        p.setAttribute('aria-hidden','true');
    });

    const id = btn.getAttribute('data-tab');
    const panel = document.getElementById(id);
    if(panel){ panel.style.display = 'block'; panel.setAttribute('aria-hidden','false'); }
}

// click outside to close
window.addEventListener('click', (e)=>{
    const modal = document.getElementById('editModal');
    if(e.target === modal) closeModal();
});

// 3. IMAGE PREVIEW AND RESET
function previewFile(event){
    const input = event.target;
    const file = input.files && input.files[0];
    if(!file) return;
    
    // Check file size (Client-side limit)
    if(file.size > 10 * 1024 * 1024){ 
        alert('File is too large. Please choose a smaller file (<10MB).');
        input.value = '';
        document.getElementById('previewImg').src = "<?= $img_path ?>";
        return;
    }
    
    const reader = new FileReader();
    reader.onload = function(e){
        document.getElementById('previewImg').src = e.target.result;
    };
    reader.readAsDataURL(file);
}

function resetPreview(){
    document.getElementById('profile_image').value = "";
    document.getElementById('previewImg').src = "<?= $img_path ?>";
}

function confirmUpload(){
    // You can add further client-side validation here
    return true;
}

// 4. FORM HELPER FUNCTIONS
function fillUsername(){
    document.getElementById('new_username').value = "<?= htmlspecialchars($patient['username']); ?>";
}

function clearPasswordFields(){
    document.getElementById('current_password').value = '';
    document.getElementById('new_password').value = '';
    document.getElementById('confirm_password').value = '';
}

// 5. MOBILE MENU TOGGLE LOGIC
document.addEventListener('DOMContentLoaded', function() {
    if (window.innerWidth < 992) {
        const sidebar = document.getElementById('sidebar');
        const menuToggleOpen = document.getElementById('menu-toggle-open');
        const menuToggleClose = document.getElementById('menu-toggle-close');

        if (menuToggleOpen && sidebar) {
             // Show the close button inside the sidebar-header on mobile
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
