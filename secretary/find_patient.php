<?php
session_start();
// 1. Security Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'secretary') {
    header('Location: ../public/login.php');
    exit();
}

// 2. Database Connection
if (file_exists('../config/db_pdo.php')) {
    include '../config/db_pdo.php'; 
} else {
    die("Error: Could not find database config file at ../config/db_pdo.php");
}
$conn = $pdo; 

$secretary_username = $_SESSION['username'] ?? 'Secretary';
$message = '';
$errors = [];

// 3. Handle Update Submission (From the Modal)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_patient'])) {
    $patient_id = $_POST['patient_id'];
    $email = trim($_POST['email']);
    $contact_number = trim($_POST['contact_number']);
    $address = trim($_POST['address']);
    $emergency_name = trim($_POST['emergency_contact_name']);
    $emergency_num = trim($_POST['emergency_contact_number']);
    $medical_history = trim($_POST['medical_history']);
    $allergies = trim($_POST['allergies']);
    $notes = trim($_POST['notes']);

    try {
        $sql = "UPDATE patient SET 
                email=:email, 
                contact_number=:contact_number, 
                address=:address, 
                emergency_contact_name=:ec_name,
                emergency_contact_number=:ec_num,
                medical_history=:med_hist,
                allergies=:allergies,
                notes=:notes, 
                updated_at=NOW() 
                WHERE patient_id=:patient_id";

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':email' => $email,
            ':contact_number' => $contact_number,
            ':address' => $address,
            ':ec_name' => $emergency_name,
            ':ec_num' => $emergency_num,
            ':med_hist' => $medical_history,
            ':allergies' => $allergies,
            ':notes' => $notes,
            ':patient_id' => $patient_id
        ]);
        $message = "Patient record updated successfully!";
    } catch(PDOException $e){
        $errors[] = "Database error: ".$e->getMessage();
    }
}

// 4. Search Logic
$search = $_GET['search'] ?? '';
$patients = [];

try {
    $query = "SELECT * FROM patient 
              WHERE fullname LIKE :search 
              OR email LIKE :search 
              OR contact_number LIKE :search 
              OR patient_id LIKE :search
              ORDER BY patient_id ASC"; 
              
    $stmt = $conn->prepare($query);
    $stmt->execute([':search'=>"%$search%"]);
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e){
    $errors[] = "Database error: ".$e->getMessage();
}

// 5. AJAX HANDLER (Outputs table rows only)
if (isset($_GET['ajax_request'])) {
    if ($patients) {
        foreach($patients as $p) {
            renderPatientRow($p);
        }
    } else {
        echo '<tr><td colspan="8" style="text-align:center; padding: 20px;">No patients found.</td></tr>';
    }
    exit(); 
}

// Helper function to render a row
function renderPatientRow($p) {
    // Format the date nicely
    $dateCreated = date("M d, Y", strtotime($p['created_at']));
    $timeCreated = date("h:i A", strtotime($p['created_at']));
    ?>
    <tr>
        <td><?php echo htmlspecialchars($p['patient_id']); ?></td>
        <td>
            <strong><?php echo htmlspecialchars($p['fullname']); ?></strong><br>
            <small style="color:#6c757d;"><?php echo htmlspecialchars($p['email']); ?></small>
        </td>
        <td>
            <?php echo htmlspecialchars($p['dob']); ?><br>
            <small style="color:#6c757d;"><?php echo htmlspecialchars($p['gender']); ?></small>
        </td>
        <td><?php echo htmlspecialchars($p['contact_number']); ?></td>
        <td><?php echo htmlspecialchars($p['address']); ?></td>
        
        <td>
            <?php echo $dateCreated; ?><br>
            <small style="color:#999;"><?php echo $timeCreated; ?></small>
        </td>

        <td style="font-weight:bold; color: <?php echo ($p['outstanding_balance'] > 0) ? '#dc3545' : '#28a745'; ?>">
            ₱<?php echo number_format($p['outstanding_balance'], 2); ?>
        </td>
        <td>
            <button class="btn-edit" 
                onclick="openModal(
                    '<?php echo $p['patient_id']; ?>',
                    '<?php echo htmlspecialchars($p['fullname'], ENT_QUOTES); ?>',
                    '<?php echo htmlspecialchars($p['email'], ENT_QUOTES); ?>',
                    '<?php echo htmlspecialchars($p['contact_number'], ENT_QUOTES); ?>',
                    '<?php echo htmlspecialchars($p['address'], ENT_QUOTES); ?>',
                    '<?php echo htmlspecialchars($p['emergency_contact_name'], ENT_QUOTES); ?>',
                    '<?php echo htmlspecialchars($p['emergency_contact_number'], ENT_QUOTES); ?>',
                    '<?php echo htmlspecialchars($p['medical_history'], ENT_QUOTES); ?>',
                    '<?php echo htmlspecialchars($p['allergies'], ENT_QUOTES); ?>',
                    '<?php echo htmlspecialchars($p['notes'], ENT_QUOTES); ?>'
                )">
                <i class="fas fa-edit"></i> Edit
            </button>
        </td>
    </tr>
    <?php
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Find Patient - DentiTrack</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous">
<style>
    /* CSS Variables for a clean, consistent color scheme */
    :root {
        --sidebar-width: 240px;
        --primary-blue: #007bff;
        --secondary-blue: #0056b3;
        --text-dark: #343a40;
        --text-light: #6c757d;
        --bg-light: #f8f9fa;
        --bg-page: #eef2f8; /* Light blue/gray background */
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

    /* --- Sidebar Styling (Stable & Fixed) --- */
    .sidebar { 
        width: var(--sidebar-width); 
        background: var(--widget-bg); 
        padding: 0; 
        color: var(--text-dark); 
        box-shadow: 2px 0 10px rgba(0,0,0,0.05); 
        display: flex; 
        flex-direction: column; 
        position: fixed; /* Makes the sidebar stable/fixed */
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
        flex-grow: 1; /* Allows navigation links to fill space */
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
        background-color: rgba(0, 123, 255, 0.08); /* Light hover effect */
        color: var(--primary-blue);
    }
    .sidebar a.active { 
        background-color: var(--primary-blue);
        color: white; 
        font-weight: 600;
        box-shadow: 0 4px 8px rgba(0, 123, 255, 0.2);
    }
    .sidebar a.active i {
        color: white; /* Ensures icon is white when link is active */
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
    .sidebar .logout i {
        color: var(--text-light);
        transition: color 0.3s ease;
    }
    .sidebar .logout:hover i {
        color: var(--alert-red);
    }


    /* --- Main Content Styling --- */
    .main-content { 
        flex: 1; 
        margin-left: var(--sidebar-width); /* Compensate for the fixed sidebar */
        padding: 40px; 
        background: var(--bg-page); 
        overflow-y: auto; 
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
    /* Message/Error Boxes */
    .message, .error-list{
        padding:12px 20px;
        margin-bottom:20px;
        border-radius:8px;
        font-weight:600;
        text-align:center;
        display: flex;
        align-items: center;
        gap: 10px;
        justify-content: center;
    }
    .message{
        background:#e6f7ff; /* Light blue */
        border:1px solid #91d5ff;
        color:#0050b3;
    }
    .error-list{
        background:#fff1f0; /* Light red */
        border:1px solid #ffa39e;
        color:#a8071a;
    }
    .error-list ul {
        list-style: none;
        padding: 0;
        margin: 0;
        display: inline;
    }
    
    /* Search Box */
    .search-container { position: relative; display: block; margin-bottom: 25px; }
    input[type="text"].search-input{
        padding:12px 15px; 
        font-size:16px; 
        border:1px solid #ced4da;
        border-radius:8px; 
        width:400px; 
        max-width: 100%;
        transition: all 0.3s ease;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }
    input[type="text"].search-input:focus { 
        border-color: var(--primary-blue); 
        outline: none; 
        box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.25);
    }
    
    /* Table Styles */
    table{
        width:100%;
        border-collapse:separate;
        border-spacing: 0;
        margin-top:20px; 
        font-size: 0.9rem;
        background: var(--widget-bg);
        border-radius: 10px;
        overflow: hidden; /* Ensures rounded corners */
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    }
    th, td{
        padding:15px;
        text-align:left;
        border-bottom: 1px solid #f0f0f0;
    }
    th{
        background: var(--primary-blue);
        color:white; 
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.8rem;
    }
    /* Rounding corners for header */
    th:first-child { border-top-left-radius: 10px; }
    th:last-child { border-top-right-radius: 10px; }

    tr:last-child td { border-bottom: none; }
    tr:hover {background-color: #f5f8fc;} 
    
    /* Buttons */
    button.btn-edit{
        padding:8px 15px;
        background: var(--accent-orange);
        color:white;
        border:none;
        border-radius:6px;
        font-weight:600;
        cursor:pointer;
        transition:.3s; 
        font-size: 0.85rem;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }
    button.btn-edit:hover{background: #e0a800;}

    /* MODAL STYLES */
    .modal-overlay {
        display: none; 
        position: fixed; z-index: 1000; left: 0; top: 0;
        width: 100%; height: 100%;
        background-color: rgba(0,0,0,0.6);
        justify-content: center; align-items: center;
    }
    .modal-content {
        background-color: var(--widget-bg);
        padding: 30px;
        border-radius: 12px;
        width: 650px;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 10px 30px rgba(0,0,0,0.4);
        position: relative;
        animation: slideDown 0.3s ease-out;
    }
    
    .close-btn { 
        position: absolute; 
        top: 15px; right: 20px; 
        font-size: 30px; 
        cursor: pointer; 
        color: var(--text-light); 
    }
    .close-btn:hover { color: var(--alert-red); }
    
    .form-row { display: flex; gap: 20px; margin-bottom: 15px; }
    .form-group { flex: 1; }
    .form-group label { 
        display: block; 
        margin-bottom: 6px; 
        font-weight: 600; 
        color: var(--text-dark); 
        font-size: 0.9rem;
    }
    .form-group input, .form-group textarea { 
        width: 100%; 
        padding: 10px 12px; 
        border: 1px solid #ced4da; 
        border-radius: 6px; 
        box-sizing: border-box;
        transition: border-color 0.3s;
    }
    .form-group input:focus, .form-group textarea:focus {
        border-color: var(--primary-blue);
        outline: none;
    }
    .section-title {
        border-bottom: 1px solid #eee; 
        padding-bottom: 5px; 
        margin-bottom: 15px; 
        margin-top: 25px;
        color: var(--primary-blue); 
        font-weight: 700;
        font-size: 1.1rem;
    }
    .modal-actions { text-align: right; margin-top: 30px; }
    .btn-save { 
        padding: 10px 25px; 
        background: var(--success-green); 
        color: white; 
        border: none; 
        border-radius: 8px; 
        cursor: pointer; 
        font-weight: bold;
        transition: background d 0.3s;
    }
    .btn-save:hover { background: #1e7e34; }

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
        .mobile-header {
            display: flex; 
        }
        .main-content {
            margin-left: 0;
            padding: 20px;
            padding-top: 80px;
        }
        
        /* Sidebar full screen overlay */
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
            border-bottom: 1px solid #e9ecef;
        }
        .sidebar-header button {
            display: block !important;
            background: none;
            border: none;
            color: var(--text-light);
            font-size: 24px;
        }
        .sidebar-nav {
            padding: 0;
            /* Ensure mobile sidebar scrolling works without cluttering the screen */
            overflow-y: auto !important; 
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
        /* Table scroll */
        table {
            display: block;
            overflow-x: auto;
            white-space: nowrap;
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

    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <i class="fas fa-tooth"></i> DentiTrack
            <button id="menu-toggle-close" style="display: none;"><i class="fas fa-times"></i></button>
        </div>
        <nav class="sidebar-nav">
            <a href="secretary_dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
            <a href="find_patient.php" class="active"><i class="fas fa-search"></i> Find Patient</a>
            <a href="view_patients.php"><i class="fas fa-users"></i> Patients List</a>
            <a href="online_bookings.php" ><i class="fas fa-calendar-check"></i> Booking Mgmt</a>
            <a href="appointments.php"><i class="fas fa-calendar-alt"></i> Consultations</a>
            <a href="services_list.php"><i class="fas fa-briefcase-medical"></i> Services</a>
            <a href="inventory.php"><i class="fas fa-boxes"></i> Inventory Stock</a>
            <a href="payments.php"><i class="fas fa-money-bill-wave"></i> Process Payments</a>
            <a href="pending_installments.php"><i class="fas fa-credit-card"></i> Pending Installments</a>
            <a href="pending_payments.php"><i class="fas fa-credit-card"></i> Pending Payments</a>
            <a href="payment_logs.php"><i class="fas fa-file-invoice-dollar"></i> Payments Log</a>
            <a href="create_announcements.php"><i class="fas fa-bullhorn"></i> Announcements</a>
        </nav>
        <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Log Out</a>
    </div>

<main class="main-content">
    <header>
        <h1><i class="fas fa-user-friends"></i> Patient Records</h1>
      
    </header>

    <?php if($message):?><div class="message"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message);?></div><?php endif;?>
    <?php if($errors):?><div class="error-list"><i class="fas fa-exclamation-triangle"></i> Error:<ul><?php foreach($errors as $err):?><li><?php echo htmlspecialchars($err);?></li><?php endforeach;?></ul></div><?php endif;?>

    <div class="search-container">
        <input type="text" id="search_input" class="search-input" placeholder="Search Patient Name, ID, Email, or Contact..." value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 5%;">ID</th>
                <th style="width: 18%;">Full Name & Email</th>
                <th style="width: 10%;">DOB / Gender</th>
                <th style="width: 12%;">Contact</th>
                <th style="width: 15%;">Address</th>
                <th style="width: 10%;">Date Added</th>
                <th style="width: 12%;">Balance</th>
                <th style="width: 8%;">Action</th>
            </tr>
        </thead>
        <tbody id="patient_table_body">
        <?php if($patients): ?>
            <?php foreach($patients as $p): ?>
                <?php renderPatientRow($p); ?>
            <?php endforeach;?>
        <?php else: ?>
            <tr><td colspan="8" style="text-align:center; padding: 20px; color:var(--text-light);">No patients found matching the search criteria.</td></tr>
        <?php endif;?>
        </tbody>
    </table>
</main>

<div id="editModal" class="modal-overlay">
    <div class="modal-content">
        <span class="close-btn" onclick="closeModal()">&times;</span>
        <h2 style="margin-top:0; color:var(--primary-blue);">Edit Patient Details</h2>
        <p id="modal_patient_name" style="color:var(--text-light); margin-bottom:20px; border-bottom: 1px solid #eee; padding-bottom: 10px;"></p>
        
        <form method="post">
            <input type="hidden" name="patient_id" id="modal_patient_id">
            
            <div class="section-title"><i class="fas fa-phone-square-alt"></i> Contact Information</div>
            <div class="form-row">
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="text" name="email" id="modal_email">
                </div>
                <div class="form-group">
                    <label>Contact Number</label>
                    <input type="text" name="contact_number" id="modal_contact">
                </div>
            </div>
            <div class="form-group" style="margin-bottom:15px;">
                <label>Home Address</label>
                <input type="text" name="address" id="modal_address">
            </div>

            <div class="section-title"><i class="fas fa-user-shield"></i> Emergency Contact</div>
            <div class="form-row">
                <div class="form-group">
                    <label>Contact Person</label>
                    <input type="text" name="emergency_contact_name" id="modal_em_name">
                </div>
                <div class="form-group">
                    <label>Emergency Number</label>
                    <input type="text" name="emergency_contact_number" id="modal_em_num">
                </div>
            </div>

            <div class="section-title"><i class="fas fa-notes-medical"></i> Medical Information</div>
            <div class="form-row">
                <div class="form-group">
                    <label>Medical History</label>
                    <textarea name="medical_history" id="modal_med_history" rows="2"></textarea>
                </div>
                <div class="form-group">
                    <label>Allergies</label>
                    <textarea name="allergies" id="modal_allergies" rows="2"></textarea>
                </div>
            </div>

            <div class="form-group">
                <label>General Notes</label>
                <textarea name="notes" id="modal_notes" rows="2"></textarea>
            </div>
            
            <div class="modal-actions">
                <button type="submit" name="update_patient" class="btn-save"><i class="fas fa-save"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
    // 1. MODAL LOGIC (UNCHANGED)
    const modal = document.getElementById('editModal');

    function openModal(id, name, email, contact, address, em_name, em_num, med_hist, allergies, notes) {
        // Unescape HTML entities before setting to form fields
        function unescapeHtml(str) {
            return str.replace(/&quot;/g, '"')
                        .replace(/&#039;/g, "'")
                        .replace(/&amp;/g, '&')
                        .replace(/&lt;/g, '<')
                        .replace(/&gt;/g, '>');
        }

        document.getElementById('modal_patient_id').value = id;
        document.getElementById('modal_patient_name').innerText = "Editing: " + unescapeHtml(name) + " (ID: " + id + ")";
        
        document.getElementById('modal_email').value = unescapeHtml(email);
        document.getElementById('modal_contact').value = unescapeHtml(contact);
        document.getElementById('modal_address').value = unescapeHtml(address);
        
        document.getElementById('modal_em_name').value = unescapeHtml(em_name);
        document.getElementById('modal_em_num').value = unescapeHtml(em_num);
        
        document.getElementById('modal_med_history').value = unescapeHtml(med_hist);
        document.getElementById('modal_allergies').value = unescapeHtml(allergies);
        document.getElementById('modal_notes').value = unescapeHtml(notes);
        
        modal.style.display = 'flex';
    }

    function closeModal() {
        modal.style.display = 'none';
    }

    window.onclick = function(event) {
        if (event.target == modal) {
            closeModal();
        }
    }

    // 2. SEARCH LOGIC (AJAX - UNCHANGED)
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('search_input');
        const tableBody = document.getElementById('patient_table_body');
        let timeout = null;

        searchInput.addEventListener('input', function() {
            clearTimeout(timeout);
            timeout = setTimeout(function() {
                const query = searchInput.value;
                const url = `?ajax_request=1&search=${encodeURIComponent(query)}`;

                fetch(url)
                    .then(response => {
                        if (!response.ok) { throw new Error('Network response was not ok'); }
                        return response.text();
                    })
                    .then(html => {
                        tableBody.innerHTML = html;
                        // Since new HTML is inserted, the 'openModal' function must be globally available
                    })
                    .catch(error => console.error('Error:', error));
            }, 300); 
        });

        // 3. MOBILE MENU TOGGLE LOGIC (NEW)
        const sidebar = document.getElementById('sidebar');
        const mobileHeader = document.getElementById('mobileHeader');
        const menuToggleOpen = document.getElementById('menu-toggle-open');
        const menuToggleClose = document.getElementById('menu-toggle-close');

        if (window.innerWidth < 992) {
            
            if (mobileHeader && sidebar) {
                // Ensure the correct buttons are visible/hidden on mobile load
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