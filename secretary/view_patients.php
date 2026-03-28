<?php
session_start();
// --- SECURITY CHECK ---
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'secretary') {
    header('Location: ../public/login.php');
    exit();
}

// --- PHP Fix: Standardize Database Connection (Using PDO) ---
if (file_exists('../config/db_pdo.php')) {
    require_once '../config/db_pdo.php'; 
    $conn = $pdo; // Standardize variable name
} else {
    die("Error: Database configuration file not found.");
}

// Ensure PDO attributes are set and handle potential errors
try {
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    // This rarely happens if the initial PDO connection was successful
    die("Database Configuration Error: " . $e->getMessage());
}

// --- AUTO-ARCHIVE PATIENTS ---
try {
    // Using PDO exec for simple queries without results
    $sql_auto_archive = "UPDATE patient
                         SET is_archived = 1, archived_date = NOW()
                         WHERE is_archived = 0
                         AND updated_at <= DATE_SUB(NOW(), INTERVAL 6 MONTH)";
    $conn->exec($sql_auto_archive);
} catch (PDOException $e) {
    // Suppress auto-archive errors but log them if necessary
}

// --- Get search term and filter ---
$search_term = trim($_GET['search'] ?? '');
$filter_status = $_GET['status'] ?? 'all'; // all, active, archived

function calculateAge($dob) {
    if (!$dob) return '';
    try {
        $dobDate = new DateTime($dob);
        $now = new DateTime();
        return $now->diff($dobDate)->y;
    } catch (Exception $e) {
        return '';
    }
}

// --- Fetch Patient Details for Modal (NEW AJAX ENDPOINT) ---
if (isset($_GET['fetch_patient_details']) && is_numeric($_GET['fetch_patient_details'])) {
    $patient_id = (int)$_GET['fetch_patient_details'];

    $sql_details = "SELECT * FROM patient WHERE patient_id = :id";
    $stmt_details = $conn->prepare($sql_details);
    $stmt_details->bindParam(':id', $patient_id, PDO::PARAM_INT);
    $stmt_details->execute();
    $patient_details = $stmt_details->fetch(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');
    if ($patient_details) {
        // Add Age for display
        $patient_details['age'] = calculateAge($patient_details['dob']);

        // Format dates
        $patient_details['created_at_formatted'] = date('F j, Y, h:i A', strtotime($patient_details['created_at']));

        echo json_encode(['success' => true, 'data' => $patient_details]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Patient not found.']);
    }
    exit;
}
// --- END NEW AJAX ENDPOINT ---


// --- Fetch patients for table display ---
$patients = [];
$search_like = "%$search_term%";

$sql = "SELECT * FROM patient
        WHERE (fullname LIKE :search1 OR contact_number LIKE :search2)";

if ($filter_status === 'active') {
    $sql .= " AND is_archived = 0";
} elseif ($filter_status === 'archived') {
    $sql .= " AND is_archived = 1";
}

$sql .= " ORDER BY patient_id DESC";

try {
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':search1', $search_like, PDO::PARAM_STR);
    $stmt->bindParam(':search2', $search_like, PDO::PARAM_STR);
    $stmt->execute();
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle patient query error
    // Log the error and proceed without patients list
    error_log("Patient fetch error: " . $e->getMessage());
}


// --- AJAX request for table data (Renders table rows only) ---
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    if (empty($patients)) {
        echo '<tr><td colspan="6" style="text-align:center; padding: 20px; color:#6c757d;">No patients found.</td></tr>';
        exit;
    }
    $counter = 1;
    foreach ($patients as $patient) {
        echo '<tr>';
        echo '<td>'.$counter.'</td>';
        echo '<td>'.htmlspecialchars($patient['fullname']).'</td>';
        echo '<td>'.htmlspecialchars(calculateAge($patient['dob'])).'</td>';
        echo '<td>'.htmlspecialchars($patient['contact_number']).'</td>';
        echo '<td><span class="status-indicator status-'.($patient['is_archived'] ? 'archived' : 'active').'">'.($patient['is_archived'] ? 'Archived' : 'Active').'</span></td>';
        echo '<td>';
        
        echo '<button data-id="'. (int)$patient['patient_id'] .'" class="action-btn view-btn open-modal-btn"><i class="fas fa-eye"></i> View</button>';

        if (!$patient['is_archived']) {
            echo '<a href="archive_patients.php?id='. (int)$patient['patient_id'] .'&mode=archive" class="action-btn archive-btn" onclick="return confirm(\'Are you sure you want to archive this patient?\');"><i class="fas fa-archive"></i> Archive</a>';
        } else {
            echo '<a href="archive_patients.php?id='. (int)$patient['patient_id'] .'&mode=restore" class="action-btn restore-btn" onclick="return confirm(\'Are you sure you want to restore this patient?\');"><i class="fas fa-undo"></i> Restore</a>';
        }
        echo '</td>';
        echo '</tr>';
        $counter++;
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>View Patients - DentiTrack</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous" />
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

/* --- Sidebar Styling (Fixed & Aligned) --- */
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
    margin-left: var(--sidebar-width); 
    padding: 40px; 
    background: var(--bg-page); 
    overflow-y: auto; 
}
header { 
    display: flex; 
    justify-content: space-between; 
    align-items: center; 
    margin-bottom: 30px; 
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
.search-box { 
    margin-bottom: 25px; 
    display: flex; 
    gap: 15px; 
    align-items: center; 
}
.search-box input[type="text"], .search-box select { 
    padding: 10px 15px; 
    border-radius: 8px; 
    border: 1px solid #ced4da; 
    font-size: 16px; 
    transition: all 0.3s ease;
    box-shadow: 0 2px 4px rgba(0,0,0,0.03);
}
.search-box input[type="text"]:focus, .search-box select:focus { 
    border-color: var(--primary-blue); 
    outline: none; 
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.2); 
}

/* Table Styles */
table { 
    width: 100%; 
    border-collapse: separate; 
    border-spacing: 0;
    border-radius: 10px; 
    overflow: hidden; 
    box-shadow: 0 4px 15px rgba(0,0,0,0.05); 
    background: var(--widget-bg); 
}
th, td { 
    padding: 15px 18px; 
    text-align: left; 
    border-bottom: 1px solid #f0f0f0;
}
th { 
    background: var(--primary-blue); 
    color: white; 
    user-select: none; 
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.8rem;
}
th:first-child { border-top-left-radius: 10px; }
th:last-child { border-top-right-radius: 10px; }
tr:last-child td { border-bottom: none; }
tr:hover { background-color: #f5f8fc; }

/* Status Indicator */
.status-indicator {
    padding: 4px 8px;
    border-radius: 5px;
    font-size: 0.8rem;
    font-weight: 700;
}
.status-active {
    background-color: #e6ffed;
    color: var(--success-green);
}
.status-archived {
    background-color: #fffbe6;
    color: var(--accent-orange);
}

/* Action Buttons */
.action-btn { 
    display: inline-flex; 
    align-items: center;
    gap: 5px;
    margin-right: 10px; 
    margin-bottom: 5px; 
    font-weight: 600; 
    text-decoration: none; 
    padding: 8px 15px; 
    border: 1px solid; 
    border-radius: 8px; 
    cursor: pointer; 
    transition: all 0.3s ease; 
    font-size: 0.85rem;
}
.action-btn i { font-size: 14px; }

.view-btn { 
    border-color: var(--primary-blue); 
    color: var(--primary-blue); 
}
.view-btn:hover { 
    background-color: var(--primary-blue); 
    color: white; 
}
.archive-btn { 
    border-color: var(--alert-red); 
    color: var(--alert-red); 
}
.archive-btn:hover { 
    background-color: var(--alert-red); 
    color: white; 
}
.restore-btn { 
    border-color: var(--success-green); 
    color: var(--success-green); 
}
.restore-btn:hover { 
    background-color: var(--success-green); 
    color: white; 
}

/* --- MODAL STYLES --- */
.modal {
    display: none; 
    position: fixed; 
    z-index: 1000; 
    left: 0;
    top: 0;
    width: 100%; 
    height: 100%; 
    overflow: auto; 
    background-color: rgba(0,0,0,0.6); 
    padding-top: 50px;
}
.modal-content {
    background-color: var(--widget-bg);
    margin: 5% auto; 
    padding: 30px;
    border-radius: 12px;
    width: 90%; 
    max-width: 650px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.4);
    animation: fadeIn 0.3s;
}
@keyframes fadeIn {
    from {opacity: 0; transform: translateY(-20px);}
    to {opacity: 1; transform: translateY(0);}
}
.close {
    color: var(--text-light);
    float: right;
    font-size: 30px;
    font-weight: bold;
    transition: color 0.3s;
}
.close:hover,
.close:focus {
    color: var(--alert-red);
    text-decoration: none;
    cursor: pointer;
}
.modal-content h2 {
    color: var(--primary-blue);
    border-bottom: 1px solid #e9ecef;
    padding-bottom: 10px;
    margin-top: 0;
    font-size: 1.8rem;
}
.modal-details p {
    margin: 12px 0;
    font-size: 16px;
    line-height: 1.5;
    border-bottom: 1px dashed #eee;
    padding-bottom: 5px;
}
.modal-details p:last-child {
    border-bottom: none;
}
.modal-details strong {
    color: var(--text-dark);
    display: inline-block;
    width: 150px; 
    font-weight: 600;
}
.modal-details span {
    font-weight: 500;
    color: var(--text-light);
}

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
    /* Hide scroll on table view in mobile */
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
            <a href="find_patient.php" ><i class="fas fa-search"></i> Find Patient</a>
            <a href="view_patients.php" class="active"><i class="fas fa-users"></i> Patients List</a>
            <a href="online_bookings.php"><i class="fas fa-calendar-check"></i> Booking Mgmt</a>
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
            <h1><i class="fas fa-users"></i> Patient Records</h1>
        </header>

        <div class="search-box">
            <input type="text" id="searchInput" placeholder="Search by name or contact" value="<?= htmlspecialchars($search_term) ?>" />
            <select id="statusFilter">
                <option value="all" <?= $filter_status==='all' ? 'selected' : '' ?>>All Statuses</option>
                <option value="active" <?= $filter_status==='active' ? 'selected' : '' ?>>Active Only</option>
                <option value="archived" <?= $filter_status==='archived' ? 'selected' : '' ?>>Archived Only</option>
            </select>
        </div>

        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Age</th>
                    <th>Contact</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="patientsTable">
                <?php
                $counter = 1;
                if (!empty($patients)) {
                    foreach ($patients as $patient) {
                        echo '<tr>';
                        echo '<td>'.$counter.'</td>';
                        echo '<td>'.htmlspecialchars($patient['fullname']).'</td>';
                        echo '<td>'.htmlspecialchars(calculateAge($patient['dob'])).'</td>';
                        echo '<td>'.htmlspecialchars($patient['contact_number']).'</td>';
                        echo '<td><span class="status-indicator status-'.($patient['is_archived'] ? 'archived' : 'active').'">'.($patient['is_archived'] ? 'Archived' : 'Active').'</span></td>';
                        echo '<td>';
                        
                        echo '<button data-id="'. (int)$patient['patient_id'] .'" class="action-btn view-btn open-modal-btn"><i class="fas fa-eye"></i> View</button>';

                        if (!$patient['is_archived']) {
                            echo '<a href="archive_patients.php?id='. (int)$patient['patient_id'] .'&mode=archive" class="action-btn archive-btn" onclick="return confirm(\'Are you sure you want to archive this patient?\');"><i class="fas fa-archive"></i> Archive</a>';
                        } else {
                            // Using the new restore-btn class
                            echo '<a href="archive_patients.php?id='. (int)$patient['patient_id'] .'&mode=restore" class="action-btn restore-btn" onclick="return confirm(\'Are you sure you want to restore this patient?\');"><i class="fas fa-undo"></i> Restore</a>';
                        }
                        echo '</td>';
                        echo '</tr>';
                        $counter++;
                    }
                } else {
                    echo '<tr><td colspan="6" style="text-align:center; padding: 20px; color:var(--text-light);">No patients found.</td></tr>';
                }
                ?>
            </tbody>
        </table>
    </main>

    <div id="patientModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2 id="modalTitle">Patient Details</h2>
            <div id="modalLoading" style="text-align:center; padding: 20px; display:none;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
            <div id="modalDetails" class="modal-details" style="display:none;">
                <p><strong><i class="fas fa-user"></i> Name:</strong> <span id="modalName"></span></p>
                <p><strong><i class="fas fa-id-badge"></i> ID:</strong> <span id="modalId"></span></p>
                <p><strong><i class="fas fa-birthday-cake"></i> Age:</strong> <span id="modalAge"></span></p>
                <p><strong><i class="far fa-calendar-alt"></i> DOB:</strong> <span id="modalDob"></span></p>
                <p><strong><i class="fas fa-venus-mars"></i> Gender:</strong> <span id="modalGender"></span></p>
                <p><strong><i class="fas fa-phone"></i> Contact:</strong> <span id="modalContact"></span></p>
                <p><strong><i class="fas fa-map-marker-alt"></i> Address:</strong> <span id="modalAddress"></span></p>
                <p><strong><i class="fas fa-user-check"></i> Status:</strong> <span id="modalStatus"></span></p>
                <p><strong><i class="fas fa-clock"></i> Registered:</strong> <span id="modalRegistered"></span></p>
            </div>
        </div>
    </div>
    <script>
    const searchInput = document.getElementById('searchInput');
    const statusFilter = document.getElementById('statusFilter');
    const tableBody = document.getElementById('patientsTable');

    // --- Existing AJAX for Table Filtering/Search ---
    function fetchPatients() {
        const query = searchInput.value.trim();
        const status = statusFilter.value;

        const xhr = new XMLHttpRequest();
        xhr.open('GET', 'view_patients.php?ajax=1&search=' + encodeURIComponent(query) + '&status=' + encodeURIComponent(status), true);
        xhr.onload = function() {
            if (xhr.status === 200) {
                tableBody.innerHTML = xhr.responseText;
                // IMPORTANT: Re-attach the event listener after the table content is updated
                attachModalListeners();
            }
        };
        xhr.send();
    }

    searchInput.addEventListener('input', fetchPatients);
    statusFilter.addEventListener('change', fetchPatients);
    // --- End Existing AJAX ---


    // --- NEW MODAL JAVASCRIPT ---
    const modal = document.getElementById("patientModal");
    const spanClose = document.getElementsByClassName("close")[0];

    // Function to close the modal
    spanClose.onclick = function() {
        modal.style.display = "none";
    }

    // Close the modal when the user clicks anywhere outside of the modal
    window.onclick = function(event) {
        if (event.target == modal) {
            modal.style.display = "none";
        }
    }

    function showLoading(show) {
        document.getElementById('modalLoading').style.display = show ? 'block' : 'none';
        document.getElementById('modalDetails').style.display = show ? 'none' : 'block';
    }

    function fetchPatientDetails(patientId) {
        showLoading(true); // Show loading indicator

        const xhr = new XMLHttpRequest();
        // Use the new AJAX endpoint defined in PHP
        xhr.open('GET', 'view_patients.php?fetch_patient_details=' + patientId, true);
        
        // Setting responseType to 'json' handles the parsing automatically
        // Note: We switch back to text and parse manually to avoid cross-browser issues with responseType='json'
        // and handle potential non-JSON responses from PHP (like "Error: Database...")
        
        xhr.onload = function() {
            showLoading(false); // Hide loading indicator
            
            let response;
            try {
                response = JSON.parse(xhr.responseText);
            } catch (e) {
                alert('An internal server error occurred while parsing patient data.');
                console.error('Parsing error:', e, 'Response:', xhr.responseText);
                return;
            }

            if (xhr.status === 200 && response && response.success) {
                const data = response.data;
                document.getElementById('modalTitle').textContent = `Patient Details: ${data.fullname}`;
                document.getElementById('modalName').textContent = data.fullname || 'N/A';
                document.getElementById('modalId').textContent = data.patient_id || 'N/A';
                document.getElementById('modalAge').textContent = data.age || 'N/A';
                document.getElementById('modalDob').textContent = data.dob || 'N/A';
                document.getElementById('modalContact').textContent = data.contact_number || 'N/A';
                document.getElementById('modalGender').textContent = data.gender || 'N/A';
                document.getElementById('modalAddress').textContent = data.address || 'N/A';
                document.getElementById('modalStatus').textContent = data.is_archived == 1 ? 'Archived' : 'Active';
                document.getElementById('modalRegistered').textContent = data.created_at_formatted || 'N/A';

                modal.style.display = "block"; // Show the modal
            } else {
                alert(response.message || 'Could not fetch patient details.');
            }
        };

        xhr.onerror = function() {
            showLoading(false);
            alert('An error occurred during the network request.');
        };

        xhr.send();
    }

    function attachModalListeners() {
        // Select all buttons with the class 'open-modal-btn'
        const viewButtons = document.querySelectorAll('.open-modal-btn');

        viewButtons.forEach(button => {
            // Remove existing listener to prevent duplicates after AJAX reload
            button.removeEventListener('click', modalOpenHandler);
            // Add a new listener
            button.addEventListener('click', modalOpenHandler);
        });
    }

    function modalOpenHandler(event) {
        const patientId = event.currentTarget.getAttribute('data-id');
        if (patientId) {
            fetchPatientDetails(patientId);
        }
    }

    // --- 4. MOBILE MENU TOGGLE LOGIC ---
    const sidebar = document.getElementById('sidebar');
    const menuToggleOpen = document.getElementById('menu-toggle-open');
    const menuToggleClose = document.getElementById('menu-toggle-close');

    function setupMobileToggle() {
        if (window.innerWidth < 992) {
            if (menuToggleOpen && menuToggleClose) {
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

    // Initial calls
    document.addEventListener('DOMContentLoaded', function() {
        attachModalListeners();
        setupMobileToggle();
    });
    </script>

    </body>
    </html>