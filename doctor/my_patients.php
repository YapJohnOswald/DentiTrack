<?php
session_start();

// 1. Authentication and Authorization Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'doctor') {
    header('Location: doctor_login.php');
    exit();
}

// 2. Database Connection Includes
require_once '../config/db_pdo.php'; 
$conn = $pdo;

$doctor_username = $_SESSION['username'] ?? '';

// --- HELPER FUNCTION FOR REDIRECT ---
function redirect_with_patient_id($patient_id, $message_key, $message_value, $tab = 'recommendations') {
    $url = "my_patients.php?" . $message_key . "=" . urlencode($message_value) . "&refetch_patient_id=" . urlencode($patient_id) . "&tab=" . urlencode($tab);
    header("Location: " . $url);
    exit();
}

// --- HANDLE FILE UPLOAD ---
if (isset($_POST['upload_file']) && isset($_FILES['patient_file'])) {
    $patient_id = $_POST['patient_id'];
    $file = $_FILES['patient_file'];

    $uploadDir = '../uploads/patient_files/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    $fileName = time() . '_' . basename($file['name']);
    $targetFile = $uploadDir . $fileName;

    if (move_uploaded_file($file['tmp_name'], $targetFile)) {
        $stmt = $pdo->prepare("INSERT INTO patient_files (patient_id, file_name, file_path, uploaded_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$patient_id, $file['name'], $targetFile]);

        redirect_with_patient_id($patient_id, 'uploadSuccess', 'File uploaded successfully.', 'files');
    } else {
        redirect_with_patient_id($patient_id, 'uploadError', 'Failed to upload file.', 'files');
    }
}

// --- HANDLE SAVE NEW RECOMMENDATION ---
if (isset($_POST['save_recommendation'])) {
    $patient_id = $_POST['patient_id'];
    $recommendation = trim($_POST['recommendation'] ?? '');

    if ($recommendation !== '') {
        $stmt = $pdo->prepare("INSERT INTO patient_recommendations (patient_id, doctor_username, recommendation, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$patient_id, $doctor_username, $recommendation]);
        
        redirect_with_patient_id($patient_id, 'recSuccess', 'Recommendation saved.', 'recommendations');
    } else {
        redirect_with_patient_id($patient_id, 'recError', 'Recommendation cannot be empty.', 'recommendations');
    }
}

// --- HANDLE EDIT RECOMMENDATION SUBMISSION ---
if (isset($_POST['edit_recommendation'])) {
    $rec_id = $_POST['rec_id'];
    $patient_id = $_POST['patient_id'];
    $recommendation = trim($_POST['recommendation'] ?? '');

    if ($recommendation !== '' && is_numeric($rec_id)) {
        $stmt = $pdo->prepare("UPDATE patient_recommendations SET recommendation = ? WHERE id = ? AND doctor_username = ?");
        $stmt->execute([$recommendation, $rec_id, $doctor_username]);
        
        if ($stmt->rowCount()) {
            redirect_with_patient_id($patient_id, 'recSuccess', 'Recommendation updated successfully.', 'recommendations');
        } else {
            redirect_with_patient_id($patient_id, 'recError', 'Update failed or recommendation not found/owned.', 'recommendations');
        }
    } else {
        $patient_id = $_POST['patient_id'] ?? 0;
        redirect_with_patient_id($patient_id, 'recError', 'Invalid recommendation ID or empty recommendation.', 'recommendations');
    }
}

// --- HANDLE DELETE RECOMMENDATION SUBMISSION ---
if (isset($_POST['delete_recommendation']) && isset($_POST['rec_id_to_delete'])) {
    $rec_id = $_POST['rec_id_to_delete'];
    $patient_id = $_POST['patient_id_to_delete'];

    if (is_numeric($rec_id) && is_numeric($patient_id)) {
        $stmt = $pdo->prepare("DELETE FROM patient_recommendations WHERE id = ? AND doctor_username = ?");
        $stmt->execute([$rec_id, $doctor_username]);
        
        if ($stmt->rowCount()) {
            redirect_with_patient_id($patient_id, 'recSuccess', 'Recommendation deleted successfully.', 'recommendations');
        } else {
            redirect_with_patient_id($patient_id, 'recError', 'Deletion failed or recommendation not found/owned.', 'recommendations');
        }
    } else {
        $patient_id = $_POST['patient_id_to_delete'] ?? 0;
        redirect_with_patient_id($patient_id, 'recError', 'Invalid recommendation ID for deletion.', 'recommendations');
    }
}

// --- RETRIEVE MESSAGES FROM URL ---
$uploadSuccess = $_GET['uploadSuccess'] ?? '';
$uploadError = $_GET['uploadError'] ?? '';
$recSuccess = $_GET['recSuccess'] ?? '';
$recError = $_GET['recError'] ?? '';
$activeTab = $_GET['tab'] ?? 'details';

// --- FETCH INITIAL PATIENTS LIST ---
$search_term = $_GET['search'] ?? '';
$patients = [];
try {
    $search_like = "%$search_term%";
    $stmt = $pdo->prepare("SELECT * FROM patient WHERE fullname LIKE :search OR email LIKE :search OR contact_number LIKE :search OR patient_id LIKE :search ORDER BY fullname ASC");
    $stmt->bindParam(':search', $search_like);
    $stmt->execute();
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $patients = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Doctor: My Patients - DentiTrack</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous" />
<style>
    /* CSS Variables for a clean, consistent color scheme (Same as Doctor Dashboard) */
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
        transition: all 0.3s ease;
    }
    .sidebar a i {
        font-size: 18px;
        color: var(--text-light);
        transition: color 0.3s ease;
    }
    .sidebar a:hover { background: rgba(0, 123, 255, 0.08); color: var(--primary-blue); }
    .sidebar a.active { background: var(--primary-blue); color: white; font-weight: 600; box-shadow: 0 4px 8px rgba(0, 123, 255, 0.2); }
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
    .sidebar .logout:hover { background-color: #fce8e8; color: var(--alert-red); }


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
        align-items: flex-start; 
        margin-bottom: 20px; 
    }
    header h1 { 
        font-size: 1.8rem; 
        color: var(--secondary-blue); 
        font-weight: 700; 
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 2px solid #e0e0e0;
        padding-bottom: 10px;
    }
    .message-container {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
    }
    .message-container div {
        padding: 8px 15px;
        border-radius: 6px;
        font-weight: 600;
        margin-bottom: 5px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        color: white; 
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    /* Search input */
    #patientSearch { 
        padding: 10px 15px; 
        width: 350px; 
        max-width: 100%;
        margin-bottom: 25px; 
        border: 1px solid #ced4da; 
        border-radius: 8px; 
        font-size: 1rem;
        transition: all 0.3s ease;
    }
    #patientSearch:focus { 
        border-color: var(--primary-blue); 
        outline: none; 
        box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.25); 
    }

    /* Table styling */
    .table-container {
        overflow-x: auto;
    }
    .table { 
        width: 100%; 
        min-width: 800px;
        border-collapse: separate;
        border-spacing: 0;
        margin-top: 10px; 
        box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        border-radius: 10px;
        overflow: hidden;
        background: var(--widget-bg);
    }
    .table th, .table td { 
        padding: 12px 15px; 
        border-bottom: 1px solid #f0f0f0; 
        text-align: left; 
        font-size: 0.9rem;
    }
    .table th { 
        background: var(--primary-blue); 
        color: white; 
        font-weight: 600; 
        text-transform: uppercase;
        font-size: 0.8rem;
    }
    .table tr:hover { background: #f5f8fc; }
    .table tr:last-child td { border-bottom: none; }

    /* Action button */
    .view-btn { 
        cursor: pointer; 
        background-color: var(--accent-orange); 
        color: white; 
        font-weight: 600; 
        border: none; 
        padding: 8px 15px; 
        border-radius: 6px; 
        transition: background-color 0.3s ease; 
        font-size: 0.85rem;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }
    .view-btn:hover { background-color: #e0a800; }

    /* Modal styling */
    .modal { 
        display: none; 
        position: fixed; 
        z-index: 9999; 
        left: 0; top: 0; 
        width: 100%; height: 100%; 
        overflow: auto; 
        background-color: rgba(0,0,0,0.6); 
        backdrop-filter: blur(3px);
        align-items: flex-start;
        justify-content: center;
        padding-top: 50px;
    }
    .modal-content { 
        background-color: var(--widget-bg); 
        margin: 2% auto; 
        padding: 30px; 
        border-radius: 12px; 
        width: 90%; 
        max-width: 800px; 
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 10px 30px rgba(0,0,0,0.2); 
        position: relative; 
    }
    .close-btn { color: var(--text-dark); position: absolute; top: 15px; right: 20px; font-size: 28px; font-weight: bold; cursor: pointer; transition: color 0.3s; }
    .close-btn:hover { color: var(--alert-red); }

    /* --- Tab Navigation Styles --- */
    .tab-nav {
        display: flex;
        margin-bottom: 20px;
        border-bottom: 2px solid #eee;
        overflow-x: auto;
    }
    .tab-button {
        padding: 10px 20px;
        background: #f0f0f0;
        border: none;
        cursor: pointer;
        font-weight: 600;
        color: var(--text-light);
        border-radius: 8px 8px 0 0;
        margin-right: 5px;
        transition: all 0.2s;
        white-space: nowrap;
    }
    .tab-button.active {
        background: var(--primary-blue);
        color: white;
        border-bottom: 2px solid var(--primary-blue);
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    .tab-pane {
        padding-top: 10px;
        display: none;
    }

    /* Patient details (Enhanced Grid Layout) */
    #patientDetailsGrid { 
        display: block; 
        padding: 10px;
    }
    .patient-detail-group {
        border: 1px solid #eee;
        padding: 15px;
        border-radius: 8px;
        background: var(--bg-light);
        margin-bottom: 15px;
    }
    .patient-detail-group h4 {
        color: var(--secondary-blue);
        font-size: 1.1rem;
        margin: 0 0 10px 0;
        padding-bottom: 5px;
        border-bottom: 1px dashed #e0e0e0;
    }
    .detail-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 10px 20px;
    }
    .full-width { grid-column: 1 / -1; }

    .patient-label { font-weight: 600; color: var(--primary-blue); font-size: 0.9rem; }
    .patient-value { color: var(--text-dark); font-weight: 500; font-size: 0.95rem; display: block; margin-top: 3px; }
    .detail-item p { margin: 0; }


    /* File upload zone */
    .file-drop-zone {
        padding: 20px;
        border: 2px dashed var(--primary-blue);
        border-radius: 10px;
        cursor: pointer;
        transition: all 0.3s ease;
        margin-bottom: 10px;
        text-align: center;
        background: #f7faff;
        display: flex;
        flex-direction: column;
        align-items: center;
    }
    .file-drop-zone:hover { background: #e6f0ff; border-color: var(--secondary-blue); }
    .file-drop-zone i { color: var(--primary-blue); margin-bottom: 5px;}
    .file-drop-zone input[type="file"] { display: none; }
    #fileDropText { margin-top: 8px; font-weight: 600; color: var(--text-dark); font-size: 0.9rem; }
    
    /* Upload and Save buttons in modal */
    #uploadForm button, #recForm button {
        background-color: var(--success-green);
        color: white;
        font-weight: 600;
        border: none;
        padding: 10px 20px;
        border-radius: 8px;
        cursor: pointer;
        transition: background-color 0.3s;
        font-size: 0.95rem;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }
    #uploadForm button:hover, #recForm button:hover {
        background-color: #1e7e34;
    }

    /* File list and Recommendation styles */
    .file-list, #recList { 
        margin-top: 15px; 
        padding: 15px;
        border-radius: 8px;
        background: #f8f9fa;
        border: 1px solid #eee;
    }
    .file-list h3, #recList h3 {
        color: var(--text-dark);
        font-size: 1.1rem;
        border-bottom: 1px dashed #eee;
        padding-bottom: 5px;
        margin-top: 0;
    }
    .file-list .file-item { 
        margin-bottom: 8px; 
        font-size: 0.95rem;
    }
    .file-item a { color: var(--primary-blue); text-decoration: none; }
    .file-item a:hover { text-decoration: underline; }
    .file-item span { color: var(--text-light); font-size: 0.85rem;}

    .recommendation-box { margin-top: 25px; padding: 20px; background: #eef6ff; border-radius: 10px; border: 1px solid #cce0ff; }
    .recommendation-box h3 { color: var(--secondary-blue); }
    .recommendation-list .rec-item { margin-bottom: 15px; padding: 15px; background: #fff; border-radius: 8px; border: 1px solid #e6f0ff; }
    .recommendation-list .rec-item .meta { color: var(--text-light); font-size: 0.9rem; margin-bottom:6px; display: flex; justify-content: space-between; align-items: center;}
    .rec-actions button { margin-left: 5px; padding: 6px 10px; border: none; border-radius: 6px; cursor: pointer; font-size: 0.8rem; font-weight: 600; transition: background-color 0.3s; }
    .edit-rec-btn { background-color: var(--accent-orange); color: var(--text-dark); }
    .edit-rec-btn:hover { background-color: #e0a800; }
    .delete-rec-btn { background-color: var(--alert-red); color: white; }
    .delete-rec-btn:hover { background-color: #cc0000; }
    
    /* NEW: Edit Modal Styling */
    #editRecModal { z-index: 10000; }
    #editRecModal .modal-content { margin: 15% auto; max-width: 500px; padding: 25px; }


    /* --- MOBILE SPECIFIC HEADER --- */
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

    /* Responsive Design (for smaller screens) */
    @media (max-width: 992px) {
        /* Show mobile header */
        .mobile-header { display: flex; }
        .main-content { margin-left: 0; padding: 20px; padding-top: 80px; }
        
        /* Sidebar full screen overlay */
        .sidebar { width: 80%; max-width: 300px; position: fixed; transform: translateX(-100%); z-index: 1050; height: 100vh; }
        .sidebar.open { transform: translateX(0); box-shadow: 5px 0 15px rgba(0,0,0,0.2); }

        .sidebar-header { justify-content: space-between; border-bottom: 1px solid #e9ecef; }
        .sidebar-header button { display: block !important; background: none; border: none; color: var(--text-dark); font-size: 24px; }
        .sidebar-nav { padding: 0; overflow-y: auto !important; }
        .sidebar a { margin: 0; border-radius: 0; padding: 15px 30px; border-bottom: 1px solid #f0f0f0; }
        .sidebar .logout { padding: 15px 30px; margin: 0; margin-top: 10px; border-radius: 0; }
        
        /* Table scroll */
        .table-container { overflow-x: auto; }
        .table { min-width: 700px; }
        header { flex-direction: column; align-items: flex-start; }
        header h1 { width: 100%; padding-bottom: 5px; margin-bottom: 10px; }
        .message-container { align-items: flex-start; width: 100%; margin-top: 10px; }
        
        /* Modal grid stacking */
        .detail-grid { grid-template-columns: 1fr; }
    }
</style>
</head>
<body>
    <div class="mobile-header" id="mobileHeader">
        <button id="menu-toggle-open"><i class="fas fa-bars"></i></button>
        <div class="mobile-logo">DentiTrack</div>
        <div style="width: 24px;"></div> 
    </div>

    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <i class="fas fa-tooth"></i> DentiTrack
            <button id="menu-toggle-close" style="display: none;"><i class="fas fa-times"></i></button>
        </div>
        <nav class="sidebar-nav">
            <a href="doctor_dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
            <a href="my_patients.php" class="active"><i class="fas fa-users"></i> My Patients</a>
            <a href="my_appointments.php"><i class="fas fa-calendar-check"></i> My Appointments</a>
           
        </nav>
        <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

<main class="main-content">
<header>
    <h1><i class="fas fa-users"></i> My Patients</h1>
    <div class="message-container">
        <?php if (!empty($uploadSuccess)) echo "<div style='background-color:var(--success-green);'><i class='fas fa-check-circle'></i> $uploadSuccess</div>"; ?>
        <?php if (!empty($uploadError)) echo "<div style='background-color:var(--alert-red);'><i class='fas fa-exclamation-triangle'></i> $uploadError</div>"; ?>
        <?php if (!empty($recSuccess)) echo "<div style='background-color:var(--success-green);'><i class='fas fa-check-circle'></i> $recSuccess</div>"; ?>
        <?php if (!empty($recError)) echo "<div style='background-color:var(--alert-red);'><i class='fas fa-exclamation-triangle'></i> $recError</div>"; ?>
    </div>
</header>

<h2 style="font-size: 1.5rem; color: var(--text-dark); margin-top: 0;">Patient List Overview</h2>

<input type="text" id="patientSearch" placeholder="Search by name, email, or contact number..." value="<?= htmlspecialchars($search_term) ?>">

<div class="table-container">
<?php
if (isset($pdo)) {
    try {
        if (!empty($patients)) {
            echo "<table class='table' id='patientTable'>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Contact No.</th>
                                <th>DOB</th>
                                <th>Gender</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>";

            foreach ($patients as $p) {
                $patientData = htmlspecialchars(urlencode(json_encode($p)), ENT_QUOTES, 'UTF-8');
                
                echo "<tr data-patient-id='{$p['patient_id']}'>
                                <td>{$p['patient_id']}</td>
                                <td>{$p['fullname']}</td>
                                <td>{$p['email']}</td>
                                
                                <td>{$p['contact_number']}</td>
                                <td>{$p['dob']}</td>
                                <td>{$p['gender']}</td>
                                <td><button class='view-btn' onclick='showPatient(\"{$patientData}\")' data-patient-json='{$patientData}'><i class=\"fas fa-eye\"></i> View</button></td>
                            </tr>";
            }

            echo "</tbody></table>";
        } else {
            echo "<p style='padding: 20px; text-align: center; background: var(--widget-bg); border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);'>No patients found.</p>";
        }
    } catch (PDOException $e) {
        echo "<p style='color: var(--alert-red); padding: 20px; background: #ffebeb; border-radius: 8px;'>Error loading patients: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}
?>
</div>
</main>

<div id="patientModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" onclick="closeModal('patientModal')">&times;</span>
        <h2 style="color:var(--secondary-blue); margin-top:0;">Patient Record: <span id="modalPatientName"></span></h2>

        <div class="tab-nav">
            <button class="tab-button active" data-tab="details"><i class="fas fa-info-circle"></i> Details</button>
            <button class="tab-button" data-tab="files"><i class="fas fa-paperclip"></i> Files & Upload</button>
            <button class="tab-button" data-tab="recommendations"><i class="fas fa-comment-medical"></i> Recommendations</button>
        </div>
        
        <div id="tab-details" class="tab-pane active">
            <div id="patientDetailsGrid"></div>
        </div>

        <div id="tab-files" class="tab-pane">
             <h4 style="color: var(--primary-blue); border-bottom: 1px dashed #e9ecef; padding-bottom: 5px; margin-top: 0;"><i class="fas fa-file-upload"></i> Upload New File</h4>
             <form method="POST" enctype="multipart/form-data" id="uploadForm">
                <input type="hidden" name="patient_id" id="modalPatientId">

                <label for="patientFile" class="file-drop-zone" id="fileDropZone">
                    <i class="fas fa-upload" style="font-size: 28px;"></i>
                    <p id="fileDropText">Choose a file or drag it here (Max 10MB)</p>
                    <input type="file" name="patient_file" id="patientFile" required>
                </label>

                <button type="submit" name="upload_file" class="view-btn" style="background-color: var(--success-green);">Upload File</button>
            </form>

            <h4 style="color: var(--primary-blue); border-bottom: 1px dashed #e9ecef; padding-bottom: 5px; margin-top: 20px;"><i class="fas fa-folder-open"></i> Previous Files</h4>
            <div class="file-list" id="fileList"></div>
        </div>

        <div id="tab-recommendations" class="tab-pane">
            <div class="recommendation-box">
                <h4 style="color: var(--secondary-blue); margin-top: 0; border-bottom: 1px dashed #cce0ff; padding-bottom: 5px;"><i class="fas fa-pen-nib"></i> Add New Recommendation</h4>
                <form method="POST" id="recForm">
                    <input type="hidden" name="patient_id" id="recPatientId">
                    <textarea name="recommendation" id="recommendation" required placeholder="Write your new recommendation/notes here..." style="width:100%; height:100px; padding:10px; border:1px solid #ced4da; border-radius:8px; resize:vertical;"></textarea>
                    <div style="margin-top:10px; text-align: right;">
                        <button type="submit" name="save_recommendation" class="view-btn" style="background-color: var(--primary-blue);">Save Recommendation</button>
                    </div>
                </form>
            </div>

            <h4 style="color: var(--primary-blue); border-bottom: 1px dashed #e9ecef; padding-bottom: 5px; margin-top: 20px;"><i class="fas fa-history"></i> Recommendation History</h4>
            <div id="recList" class="recommendation-list"></div>
        </div>
    </div>
</div>

<div id="editRecModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" onclick="closeModal('editRecModal')">&times;</span>
        <h3 style="color:var(--secondary-blue);">Edit Recommendation</h3>
        <form method="POST" id="editRecForm">
            <input type="hidden" name="rec_id" id="editRecId">
            <input type="hidden" name="patient_id" id="editRecPatientId">
            <textarea name="recommendation" id="editRecommendationText" required style="width:100%; height:150px; padding:10px; border:1px solid #ced4da; border-radius:8px; resize:vertical;"></textarea>
            <div style="margin-top:15px; text-align: right;">
                <button type="button" class="view-btn" onclick="closeModal('editRecModal')" style="background-color: var(--text-light); margin-right: 10px;">Cancel</button>
                <button type="submit" name="edit_recommendation" class="view-btn" style="background-color: var(--success-green);">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<form id="deleteRecForm" method="POST" style="display:none;">
    <input type="hidden" name="delete_recommendation" value="1">
    <input type="hidden" name="rec_id_to_delete" id="recIdToDelete">
    <input type="hidden" name="patient_id_to_delete" id="patientIdToDelete">
</form>

<script>
const doctorUsername = "<?php echo $doctor_username; ?>";
const patientTable = document.getElementById('patientTable');
const MODAL_ID = 'patientModal';

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

function showTab(tabName) {
    document.querySelectorAll('.tab-pane').forEach(pane => {
        pane.style.display = 'none';
    });
    document.getElementById('tab-' + tabName).style.display = 'block';

    document.querySelectorAll('.tab-button').forEach(btn => {
        btn.classList.remove('active');
    });
    document.querySelector(`.tab-button[data-tab="${tabName}"]`).classList.add('active');
}

function showEditModal(recId, recText, patientId) {
    document.getElementById('editRecId').value = recId;
    const textArea = document.getElementById('editRecommendationText');
    const tempElement = document.createElement('textarea');
    tempElement.innerHTML = recText;
    textArea.value = tempElement.value;
    
    document.getElementById('editRecPatientId').value = patientId;
    document.getElementById('editRecModal').style.display = 'flex';
}

function deleteRecommendation(recId, patientId) {
    if (confirm("Are you sure you want to delete this recommendation?")) {
        document.getElementById('recIdToDelete').value = recId;
        document.getElementById('patientIdToDelete').value = patientId; 
        document.getElementById('deleteRecForm').submit();
    }
}

function escapeHtml(text) {
    if (!text) return '';
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;")
        .replace(/\n/g, "<br/>");
}

function formatPatientDetails(patient) {
    const detailsContainer = document.getElementById('patientDetailsGrid');
    let html = '';

    // Helper function that formats a single detail item HTML
    const detail = (label, value) => `
        <div class="detail-item">
            <span class='patient-label'>${label}:</span> 
            <span class='patient-value'>${value || 'N/A'}</span>
        </div>`;
    
    // Set the modal title patient name
    document.getElementById('modalPatientName').textContent = patient.fullname || 'N/A';

    // --- 1. PERSONAL & CONTACT INFO ---
    html += '<div class="patient-detail-group"><h4><i class="fas fa-id-badge"></i> Personal & Contact Info</h4><div class="detail-grid">';
    
    // Group 1: Standard Patient Details
    html += detail('Patient ID', patient.patient_id);
    html += detail('Full Name', patient.fullname);
    html += detail('DOB', patient.dob);
    html += detail('Gender', patient.gender);
    html += detail('Email', patient.email);
    html += detail('Contact Number', patient.contact_number);
    html += detail('Address', patient.address);
    html += detail('Outstanding Balance', `<span style="color: ${parseFloat(patient.outstanding_balance) > 0 ? 'var(--alert-red)' : 'var(--success-green)'};">₱${parseFloat(patient.outstanding_balance || 0).toFixed(2)}</span>`);
    
    html += '</div></div>'; // Close detail-grid and detail-group

    // --- 2. EMERGENCY CONTACTS ---
    html += '<div class="patient-detail-group"><h4><i class="fas fa-user-shield"></i> Emergency Contact</h4><div class="detail-grid">';
    html += detail('Contact Person', patient.emergency_contact_name);
    html += detail('Emergency Number', patient.emergency_contact_number);
    html += '</div></div>';

    // --- 3. MEDICAL HISTORY & NOTES (Full Width) ---
    html += '<div class="patient-detail-group"><h4><i class="fas fa-notes-medical"></i> Medical History & Notes</h4><div class="detail-grid full-width">';
    
    html += `<div class="detail-item full-width"><span class='patient-label'>Medical History:</span> <span class='patient-value'>${patient.medical_history || 'None recorded'}</span></div>`;
    html += `<div class="detail-item full-width"><span class='patient-label'>Allergies:</span> <span class='patient-value'>${patient.allergies || 'None recorded'}</span></div>`;
    html += `<div class="detail-item full-width"><span class='patient-label'>General Notes:</span> <span class='patient-value'>${patient.notes || 'None'}</span></div>`;

    html += '</div></div>'; // Close detail-grid and detail-group

    detailsContainer.innerHTML = html;
}


// Function to populate the modal with patient data and open it
function showPatient(encodedPatientData) {
    const decodedData = decodeURIComponent(encodedPatientData);
    let patient;
    try {
        patient = JSON.parse(decodedData);
    } catch (e) {
        console.error("Error parsing patient JSON:", e);
        alert("Error loading patient data. Check console.");
        return;
    }
    
    document.getElementById('modalPatientId').value = patient.patient_id;
    document.getElementById('recPatientId').value = patient.patient_id;
    
    // 1. Load Data
    formatPatientDetails(patient); 
    fetchFiles(patient.patient_id);
    fetchRecommendations(patient.patient_id);

    // 2. Reset and Show Tab
    showTab('details'); 

    // 3. Reset File Input
    document.getElementById('fileDropText').innerHTML = "Choose a file or drag it here (Max 10MB)";
    document.getElementById('patientFile').value = ''; 
    document.getElementById('recommendation').value = '';

    // 4. Open Modal
    document.getElementById(MODAL_ID).style.display = 'flex';
}

function fetchFiles(patientId) {
    fetch(`fetch_patient_files.php?patient_id=${patientId}`)
        .then(res => res.json())
        .then(data => {
            let fileHtml = '';
            if (data.length > 0) {
                data.forEach(f => {
                    const fileName = f.file_name || f.file_path.split('/').pop() || 'Untitled File';
                    const fileLink = f.file_path.replace('../', ''); 
                    
                    fileHtml += `
                        <div class="file-item">
                            <a href="${fileLink}" target="_blank">
                                <i class="fas fa-file-alt" style="margin-right: 5px;"></i> ${fileName}
                            </a>
                            <span style="color:var(--text-light); font-size: 0.85rem;"> — Uploaded: ${f.uploaded_at}</span>
                        </div>`;
                });
            } else {
                fileHtml += '<p style="font-style: italic; color: var(--text-light);">No files uploaded yet.</p>';
            }
            document.getElementById('fileList').innerHTML = fileHtml;
        }).catch(err => {
            document.getElementById('fileList').innerHTML = '<p style="color: var(--alert-red);">Error loading files.</p>';
        });
}

function fetchRecommendations(patientId) {
    fetch(`fetch_recommendations.php?patient_id=${patientId}`)
        .then(res => res.json())
        .then(recs => {
            let recHtml = "";
            if (recs.length > 0) {
                recs.forEach(r => {
                    const isDoctorRec = r.doctor_username === doctorUsername;
                    const recTextRaw = r.recommendation.replace(/'/g, "\\'").replace(/"/g, "&quot;"); 
                    const recTextEscaped = escapeHtml(r.recommendation);

                    recHtml += `
                        <div class="rec-item">
                            <div class="meta">
                                <span><strong>${r.doctor_username}</strong> — <span>${r.created_at}</span></span>
                                ${isDoctorRec ? `
                                    <span class="rec-actions">
                                        <button class="edit-rec-btn" onclick="showEditModal(${r.id}, '${recTextRaw}', ${patientId})">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <button class="delete-rec-btn" onclick="deleteRecommendation(${r.id}, ${patientId})">
                                            <i class="fas fa-trash-alt"></i> Delete
                                        </button>
                                    </span>` : ''}
                            </div>
                            <div class="body">${recTextEscaped}</div>
                        </div>
                    `;
                });
            } else {
                recHtml = "<p style='font-style: italic; color: var(--text-light);'>No recommendations have been recorded for this patient.</p>";
            }
            document.getElementById('recList').innerHTML = recHtml;
        }).catch(err => {
            document.getElementById('recList').innerHTML = '<p style="color: var(--alert-red);">Error loading recommendations.</p>';
        });
}


// --- REOPEN MODAL AFTER REDIRECT FOR MESSAGE DISPLAY ---
function fetchPatientDetails(patientId) {
    const tableRow = document.querySelector(`tr[data-patient-id='${patientId}']`);
    if (tableRow) {
        const viewButton = tableRow.querySelector('.view-btn');
        const patientDataJson = viewButton ? viewButton.getAttribute('data-patient-json') : null;
        
        if (patientDataJson) {
            showPatient(patientDataJson);
        }
    }
}

// Event Listeners on DOM Load
document.addEventListener('DOMContentLoaded', () => {
    // 1. TAB SWITCHING LOGIC
    document.querySelectorAll('.tab-button').forEach(button => {
        button.addEventListener('click', function() {
            showTab(this.dataset.tab);
        });
    });

    // 2. RE-OPEN MODAL ON REDIRECT
    const urlParams = new URLSearchParams(window.location.search);
    const refetchPatientId = urlParams.get('refetch_patient_id');
    const tabToActivate = urlParams.get('tab') || 'details';

    if (refetchPatientId) {
        setTimeout(() => {
            fetchPatientDetails(refetchPatientId);
            showTab(tabToActivate);
        }, 100); 
    }
    
    // Clean up the URL to prevent double submission on refresh
    history.replaceState(null, null, window.location.pathname);


    // 3. MOBILE MENU TOGGLE LOGIC
    if (window.innerWidth < 992) {
        const sidebar = document.getElementById('sidebar');
        const menuToggleOpen = document.getElementById('menu-toggle-open');
        const menuToggleClose = document.getElementById('menu-toggle-close');

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