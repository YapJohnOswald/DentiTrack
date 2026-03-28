<?php
session_start();
// Assuming your database connection is in a file named db_pdo.php in the parent directory
require_once '../config/db_pdo.php';

// ✅ Restrict to Admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: admin_login.php');
    exit();
}

// ✅ Ensure required tables exist
$pdo->exec("
CREATE TABLE IF NOT EXISTS clinics (
    clinic_id INT AUTO_INCREMENT PRIMARY KEY,
    clinic_name VARCHAR(255) NOT NULL,
    db_name VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS clinic_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    clinic_id INT,
    logo VARCHAR(255),
    description TEXT,
    address VARCHAR(255),
    contact_number VARCHAR(50),
    FOREIGN KEY (clinic_id) REFERENCES clinics(clinic_id) ON DELETE CASCADE
);
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS clinic_services (
    service_id INT AUTO_INCREMENT PRIMARY KEY,
    clinic_id INT,
    service_name VARCHAR(255),
    description TEXT,
    price DECIMAL(10,2),
    image VARCHAR(255),
    FOREIGN KEY (clinic_id) REFERENCES clinics(clinic_id) ON DELETE CASCADE
);
");

// ✅ Fetch existing clinics for dropdown
$stmt = $pdo->query("SELECT clinic_id, clinic_name FROM clinics ORDER BY clinic_name ASC");
$existingClinics = $stmt->fetchAll(PDO::FETCH_ASSOC);

$countries = [
    '+63' => 'Philippines', '+1' => 'USA', '+44' => 'UK', '+91' => 'India',
    '+81' => 'Japan', '+82' => 'South Korea', '+61' => 'Australia',
    '+49' => 'Germany', '+33' => 'France', '+34' => 'Spain'
];

// ✅ Delete Service
if (isset($_GET['delete_service_id'])) {
    $service_id = intval($_GET['delete_service_id']);
    $stmt = $pdo->prepare("SELECT image FROM clinic_services WHERE service_id=?");
    $stmt->execute([$service_id]);
    $service = $stmt->fetch();
    if ($service && !empty($service['image']) && file_exists("../" . $service['image'])) {
        unlink("../" . $service['image']);
    }
    $pdo->prepare("DELETE FROM clinic_services WHERE service_id=?")->execute([$service_id]);
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ✅ Edit Clinic if clinic_id given
$updateClinic = null;
$updateServices = [];
if (isset($_GET['clinic_id'])) {
    $clinic_id = intval($_GET['clinic_id']);
    $stmt = $pdo->prepare("
        SELECT c.clinic_id, c.clinic_name, c.db_name, p.logo, p.description, p.address, p.contact_number
        FROM clinics c LEFT JOIN clinic_profiles p ON c.clinic_id=p.clinic_id
        WHERE c.clinic_id=?
    ");
    $stmt->execute([$clinic_id]);
    $updateClinic = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt2 = $pdo->prepare("SELECT * FROM clinic_services WHERE clinic_id=?");
    $stmt2->execute([$clinic_id]);
    $updateServices = $stmt2->fetchAll(PDO::FETCH_ASSOC);
}

// ✅ Save or Update Clinic
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['save_clinic']) || isset($_POST['update_and_add_new']))) {
    $clinic_id = !empty($_POST['clinic_id']) ? intval($_POST['clinic_id']) : null;
    $clinic_name = trim($_POST['clinic_name']);
    $description = $_POST['description'] ?? '';
    $address = $_POST['address'] ?? '';
    $contact_number = $_POST['contact_number'] ?? '';
    $contact_country_code = $_POST['contact_country_code'] ?? '+63';
    
    // Check if contact number already includes country code, if not, prepend it
    $has_prefix = false;
    foreach($countries as $code => $country) {
        if (str_starts_with($contact_number, $code)) {
            $has_prefix = true;
            break;
        }
    }

    if (!$has_prefix) {
        $contact_number = $contact_country_code . ltrim($contact_number, '0'); // Remove leading zero if present
    }


    // ✅ Upload logo
    $logoPath = null;
    if (!empty($_FILES['logo']['name'])) {
        $targetDir = "../uploads/clinics/";
        if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
        $fileName = time() . "_" . basename($_FILES['logo']['name']);
        $targetFile = $targetDir . $fileName;
        if (move_uploaded_file($_FILES['logo']['tmp_name'], $targetFile)) {
            $logoPath = "uploads/clinics/" . $fileName;
        }
    }
    
    $activeClinic = $clinic_id; // Assume current ID for update

    // ✅ Add new or update existing clinic
    if ($clinic_id) {
        $pdo->prepare("UPDATE clinics SET clinic_name=? WHERE clinic_id=?")->execute([$clinic_name, $clinic_id]);
        $exists = $pdo->prepare("SELECT clinic_id FROM clinic_profiles WHERE clinic_id=?");
        $exists->execute([$clinic_id]);
        if ($exists->fetch()) {
            $pdo->prepare("
                UPDATE clinic_profiles 
                SET logo=COALESCE(?,logo), description=?, address=?, contact_number=? 
                WHERE clinic_id=?
            ")->execute([$logoPath, $description, $address, $contact_number, $clinic_id]);
        } else {
            $pdo->prepare("
                INSERT INTO clinic_profiles (clinic_id, logo, description, address, contact_number) 
                VALUES (?,?,?,?,?)
            ")->execute([$clinic_id, $logoPath, $description, $address, $contact_number]);
        }
        $msg = "✅ Clinic updated successfully!";
    } else {
        // ✅ Create unique DB name for new clinic
        $timestamp = time();
        // Sanitize clinic name for DB use
        $sanitized_clinic_name = strtolower(preg_replace("/[^a-z0-9]/i", "_", $clinic_name));
        $db_name = "dentitrack_" . trim($sanitized_clinic_name, '_') . "_{$timestamp}_db";

        try {
            // Create new database
            $pdo->exec("CREATE DATABASE `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

            // Save clinic record
            $pdo->prepare("INSERT INTO clinics (clinic_name, db_name) VALUES (?, ?)")->execute([$clinic_name, $db_name]);
            $new_id = $pdo->lastInsertId();
            $activeClinic = $new_id;

            // Add profile info
            $pdo->prepare("
                INSERT INTO clinic_profiles (clinic_id, logo, description, address, contact_number) 
                VALUES (?,?,?,?,?)
            ")->execute([$new_id, $logoPath, $description, $address, $contact_number]);

            $msg = "✅ Clinic '$clinic_name' created successfully with new database '$db_name'!";
        } catch (PDOException $e) {
            $msg = "❌ Error creating clinic: " . htmlspecialchars($e->getMessage());
        }
    }

    // ✅ Save / Update Services (Uses $activeClinic which is set above)
    if (!empty($_POST['services_name'])) {
        foreach ($_POST['services_name'] as $i => $sname) {
            if (!empty($sname)) {
                $desc = $_POST['services_desc'][$i] ?? '';
                // Convert price to float after cleaning up input string
             // ✅ Flexible price input — allows any numeric format like "5,000" or "₱1,200.75"
$price_input = $_POST['services_cost'][$i] ?? '0';

// Remove peso signs, commas, spaces, and convert to decimal
$price_clean = str_replace(['₱', ',', ' '], '', $price_input);

// Convert safely to float
$price = is_numeric($price_clean) ? (float)$price_clean : 0.00;

                $imagePath = null;

                if (!empty($_FILES['services_image']['name'][$i])) {
                    $targetDir = "../uploads/services/";
                    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
                    $fileName = time() . "_" . basename($_FILES['services_image']['name'][$i]);
                    $targetFile = $targetDir . $fileName;
                    if (move_uploaded_file($_FILES['services_image']['tmp_name'][$i], $targetFile)) {
                        $imagePath = "uploads/services/" . $fileName;
                    }
                }

                if (!empty($_POST['services_id'][$i])) {
                    $service_id = intval($_POST['services_id'][$i]);
                    $pdo->prepare("
                        UPDATE clinic_services 
                        SET service_name=?, description=?, price=?, image=COALESCE(?, image)
                        WHERE service_id=?
                    ")->execute([$sname, $desc, $price, $imagePath, $service_id]);
                } else {
                    $pdo->prepare("
                        INSERT INTO clinic_services (clinic_id, service_name, description, price, image) 
                        VALUES (?,?,?,?,?)
                    ")->execute([$activeClinic, $sname, $desc, $price, $imagePath]);
                }
            }
        }
    }

    // DETERMINE REDIRECTION
    $redirect_url = $_SERVER['PHP_SELF'];
    $redirect_params = [];

    if (isset($_POST['update_and_add_new'])) {
        // Option 1: Update & Add New. Redirect to base page (Add New Clinic mode).
        // NO MESSAGE PARAMETER INCLUDED.
        
    } else if (isset($activeClinic)) {
        // Option 2: Standard Save/Update. Redirect back to the active clinic for confirmation.
        $redirect_params['clinic_id'] = $activeClinic;
        $redirect_params['msg'] = urlencode($msg);
    } else {
        // Option 3: Should be caught by Option 2 for new clinic creation but added for clarity.
        $redirect_params['clinic_id'] = $activeClinic;
        $redirect_params['msg'] = urlencode($msg);
    }


    // Redirect to prevent form resubmission
    if (!empty($redirect_params)) {
        $redirect_url .= "?" . http_build_query($redirect_params);
    }
    
    header("Location: " . $redirect_url);
    exit;
}

// ✅ Fetch all clinics
$stmt = $pdo->query("
    SELECT c.clinic_id, c.clinic_name, c.db_name, p.logo, p.description, p.address, p.contact_number
    FROM clinics c
    LEFT JOIN clinic_profiles p ON c.clinic_id=p.clinic_id
");
$clinics = $stmt->fetchAll(PDO::FETCH_ASSOC);
$msg = $_GET['msg'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Clinics - DentiTrack</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* --- BASE & TYPOGRAPHY --- */
body {margin:0;font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;background:#f4f6f9;display:flex;color:#333;}
.main-content {flex:1;padding:30px 40px;}
h1 {color:#004080;margin-bottom:20px;font-size:2rem;}
h3 {color:#0077b6;border-bottom:2px solid #e9ecef;padding-bottom:10px;margin-bottom:20px;}
p {line-height:1.6;}

/* --- SIDEBAR (UNCHANGED DESIGN) --- */
.sidebar {width:220px;background:linear-gradient(to bottom,#3399ff,#0066cc);color:#fff;display:flex;flex-direction:column;padding-top:25px;}
.sidebar h2 {text-align:center;margin-bottom:25px;font-size:1.5rem;}
.sidebar a {color:#cce0ff;text-decoration:none;padding:14px 20px;margin:5px 10px;border-radius:6px;font-weight:600;transition:background 0.2s, color 0.2s, border-left 0.2s;}
.sidebar a:hover,.sidebar a.active {background:rgba(255,255,255,0.15);color:#fff;border-left:4px solid #ffcc00;}

/* --- MESSAGES & ALERTS (CSS for the old message) --- */
.msg {
    background:#d4edda;
    color:#155724;
    padding:12px;
    border-radius:8px;
    text-align:center;
    margin-bottom:25px;
    border:1px solid #c3e6cb;
    font-weight:600;
}

/* --- FORM LAYOUT & INPUTS (CLEANED) --- */
.form-container {
    background:#ffffff;
    padding:30px;
    border-radius:12px;
    box-shadow:0 8px 25px rgba(0,0,0,0.1);
    margin-bottom:40px;
}
.form-container label {
    display:block;
    margin-top:15px;
    margin-bottom:5px;
    font-weight:600;
    color:#555;
    font-size:0.95rem;
}
.form-container input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"]),
.form-container select,
.form-container textarea {
    width:100%;
    padding:12px;
    border:1px solid #ced4da;
    border-radius:6px;
    font-size:1rem;
    box-sizing:border-box;
    transition:border-color 0.2s, box-shadow 0.2s;
}
.form-container input:focus,
.form-container select:focus,
.form-container textarea:focus {
    border-color:#007bff;
    box-shadow:0 0 0 0.2rem rgba(0, 123, 255, 0.25);
    outline:none;
}
.contact-group {display:flex;gap:10px;}
.contact-group select {width:130px;flex-shrink:0;}
.contact-group input {flex-grow:1;}
.form-container textarea {resize:vertical;min-height:100px;}

/* --- SERVICE INPUT STYLES --- */
#services-container {
    display:flex;
    flex-direction:column;
    gap:15px;
    margin-top:10px;
}
.service-item {
    display:grid;
    grid-template-columns: 2fr 3fr 1fr 1.5fr 70px; /* Name, Desc, Price, File, Delete */
    gap:10px;
    align-items:center;
    padding:15px;
    background:#f8f9fa;
    border:1px solid #e9ecef;
    border-radius:8px;
}
.service-item input, .service-item textarea {margin:0;} /* Remove extra margin */

/* --- BUTTONS (CLEANED & DISTINCT) --- */
.form-actions {
    display: flex;
    justify-content: flex-end; /* Pushes buttons to the right */
    gap: 15px;
    margin-top: 25px;
}

.main-content button[type="submit"], .add-service, .update-and-new-btn {
    padding:12px 25px;
    border:none;
    border-radius:6px;
    cursor:pointer;
    font-weight:600;
    transition:background 0.2s, box-shadow 0.2s;
    display:inline-flex;
    align-items:center;
    gap:8px;
    margin-top: 20px; /* Default margin for add-service */
}

/* Override the default margin-top for submit button inside form-actions */
.form-actions button[type="submit"], .form-actions .update-and-new-btn {
    margin-top: 0;
}

.main-content button[type="submit"] {
    background:#007bff; /* Primary Action */
    color:white;
    font-size:1rem;
}
.main-content button[type="submit"]:hover {
    background:#0056b3;
    box-shadow:0 4px 10px rgba(0, 123, 255, 0.3);
}

.add-service {
    background:#28a745; /* Success/Add Action */
    color:white;
    margin-right:10px;
}
.add-service:hover {
    background:#1e7e34;
    box-shadow:0 4px 10px rgba(40, 167, 69, 0.3);
}

.delete-service-btn {
    background:#dc3545; /* Danger Action */
    color:white;
    padding:10px;
    border:none;
    border-radius:6px;
    cursor:pointer;
    font-weight:600;
    transition:background 0.2s;
}
.delete-service-btn:hover {background:#c82333;}

.cancel-btn {
    background: #6c757d; /* Secondary/Gray color */
    color: white;
    padding: 12px 25px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    text-decoration: none;
    transition: background 0.2s;
    font-size: 1rem;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.cancel-btn:hover {
    background: #5a6268;
}

/* NEW BUTTON STYLE */
.update-and-new-btn {
    background: #17a2b8; /* A light, informative blue/cyan */
    color: white;
    font-size: 1rem;
}

.update-and-new-btn:hover {
    background: #138496;
    box-shadow: 0 4px 10px rgba(23, 162, 184, 0.3);
}

/* --- CLINIC LISTING (CLEANED) --- */
.clinic-card {
    background:white;
    border-radius:12px;
    padding:30px;
    margin-bottom:30px;
    box-shadow:0 4px 15px rgba(0,0,0,0.08);
    border-left:5px solid #007bff;
}
.clinic-card h3 {
    margin-top:0;
    margin-bottom:15px;
    border-bottom:none;
    font-size:1.5rem;
    color:#004080;
}
.clinic-card p {margin:5px 0;font-size:0.95rem;}
.clinic-card strong {color:#495057;}
.clinic-card h4 {
    margin-top:25px;
    border-bottom:1px solid #dee2e6;
    padding-bottom:5px;
    color:#0077b6;
}

/* --- SERVICE DISPLAY (CLEANED) --- */
.service-list {
    display:grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap:20px;
    margin-top:15px;
}
.service-card {
    background:#fefefe;
    padding:15px;
    border-radius:10px;
    box-shadow:0 2px 8px rgba(0,0,0,0.08);
    display:flex;
    flex-direction:column;
    text-align:left;
    border:1px solid #e9ecef;
}
.service-card img {
    width:100%;
    max-height:140px;
    object-fit:cover;
    border-radius:8px;
    margin-bottom:10px;
    border:1px solid #ddd;
}
.service-card strong {
    font-size:1.1rem;
    color:#343a40;
    margin-bottom:5px;
}
.service-card p:nth-of-type(1) { /* Price */
    font-weight:700;
    color:#28a745;
    margin-bottom:10px;
}
.service-card p:nth-of-type(2) { /* Description */
    font-size:0.85rem;
    color:#6c757d;
    flex-grow:1;
}

/* --- ACTION LINKS (CLEANED) --- */
.action-links {
    margin-top:15px;
    padding-top:10px;
    border-top:1px solid #eee;
    display:flex;
    gap:10px;
    justify-content:flex-end;
}
.update-btn, .delete-btn {
    display:inline-block;
    padding:8px 15px;
    border-radius:6px;
    text-decoration:none;
    font-weight:600;
    transition:opacity 0.2s, background 0.2s;
    font-size:0.9rem;
}
.update-btn {
    background:#ffc107; /* Warning/Edit Color */
    color:#333;
}
.update-btn:hover {background:#e0a800;}

.delete-btn {
    background:#dc3545; /* Danger Color */
    color:white;
}
.delete-btn:hover {background:#c82333;}
</style>
<script>
function addServiceField(name='', desc='', cost='', id=''){
    const container=document.getElementById("services-container");
    const div=document.createElement("div");
    div.className="service-item";
    
    // Format the price for display/input (no currency sign, just number)
    const formattedCost = cost ? parseFloat(cost).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '';

    div.innerHTML=`
        <input type="hidden" name="services_id[]" value="${id}">
        <input type="text" name="services_name[]" placeholder="Service Name" value="${name}" required>
        <textarea name="services_desc[]" placeholder="Description">${desc}</textarea>
       <input type="text" name="services_cost[]" placeholder="₱ e.g., 5,000.00" value="${formattedCost}">
        <input type="file" name="services_image[]">
        <button type="button" class="delete-service-btn" onclick="this.parentNode.remove()">
            <i class="fas fa-trash-alt"></i>
        </button>`;
    container.appendChild(div);
}
window.onload=function(){
    // Existing services for editing
    <?php foreach($updateServices as $s): ?>
    addServiceField(
        <?= json_encode(htmlspecialchars($s['service_name'])) ?>,
        <?= json_encode(htmlspecialchars($s['description'])) ?>,
        <?= json_encode(number_format($s['price'], 2, '.', '')) ?>, /* Pass raw price for script to format if needed */
        <?= json_encode($s['service_id']) ?>
    );
    <?php endforeach; ?>

    // If adding new clinic or no clinic selected, add one blank field
    if (document.getElementById("services-container").children.length === 0) {
        addServiceField();
    }
};
</script>
</head>
<body>
<nav class="sidebar">
    <h2><i class="fas fa-tooth"></i> DentiTrack</h2>
    <a href="admin_dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
    <a href="manage_accounts.php"><i class="fas fa-users-cog"></i> Manage Accounts</a>
    <a href="manage_clinics.php" class="active"><i class="fas fa-clinic-medical"></i> Manage Clinics</a>
    <a href="generate_reports.php"><i class="fas fa-chart-line"></i> Generate Reports</a>
    <a href="payment_module.php"><i class="fas fa-money-check-dollar"></i> Payment Module</a>
    <a href="clinic_schedule_admin.php"><i class="fas fa-calendar-check"></i> Clinic Schedule</a>
    <a href="admin_settings.php" ><i class="fas fa-gear"></i> System Settings</a>
    <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
</nav>

<div class="main-content">
<h1><i class="fas fa-clinic-medical"></i> Clinic Management</h1>
<div class="form-container">
<form method="POST" enctype="multipart/form-data">
<h3><?= $updateClinic ? "Update Clinic / Services: " . htmlspecialchars($updateClinic['clinic_name']) : "Add New Clinic" ?></h3>

<div style="display:grid; grid-template-columns: 1fr 1fr; gap: 20px;">
    <div>
        <label for="clinic_select">Select Clinic to Edit</label>
        <select id="clinic_select" name="clinic_id" onchange="if(this.value) window.location='?clinic_id='+this.value;">
            <option value="">-- Add New Clinic --</option>
            <?php foreach($existingClinics as $ec): ?>
            <option value="<?= $ec['clinic_id'] ?>" <?= ($updateClinic && $updateClinic['clinic_id']==$ec['clinic_id'])?'selected':'' ?>>
                <?= htmlspecialchars($ec['clinic_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>

        <label for="clinic_name">Clinic Name</label>
        <input type="text" id="clinic_name" name="clinic_name" value="<?= htmlspecialchars($updateClinic['clinic_name'] ?? '') ?>" required>

        <label for="logo">Logo</label>
        <input type="file" id="logo" name="logo">
        <?php if ($updateClinic && !empty($updateClinic['logo'])): ?>
            <p style="font-size:0.85rem; color:#6c757d;">Current Logo: <a href="../<?= htmlspecialchars($updateClinic['logo']) ?>" target="_blank">View</a></p>
        <?php endif; ?>

        <label for="address">Address</label>
        <input type="text" id="address" name="address" value="<?= htmlspecialchars($updateClinic['address'] ?? '') ?>">

        <label for="contact_number">Contact Number</label>
        <?php
            // Attempt to separate country code from number for pre-filling
            $full_contact = $updateClinic['contact_number'] ?? '';
            $contact_code = '+63'; // Default
            $contact_num = '';

            foreach($countries as $code => $country) {
                if (str_starts_with($full_contact, $code)) {
                    $contact_code = $code;
                    $contact_num = substr($full_contact, strlen($code));
                    break;
                }
            }
            if(empty($contact_num)) $contact_num = $full_contact;
        ?>
        <div class="contact-group">
            <select name="contact_country_code" style="width:120px;">
                <?php foreach($countries as $code=>$country): ?>
                <option value="<?= $code ?>" <?= $contact_code == $code ? 'selected' : '' ?>>
                    <?= $code ?> (<?= $country ?>)
                </option>
                <?php endforeach; ?>
            </select>
            <input type="text" id="contact_number" name="contact_number" placeholder="9123456789" value="<?= htmlspecialchars($contact_num) ?>">
        </div>
    </div>
    
    <div>
        <label for="description">Description</label>
        <textarea id="description" name="description" placeholder="A brief description of the clinic..."><?= htmlspecialchars($updateClinic['description'] ?? '') ?></textarea>
    </div>
</div>

<label>Services Offered</label>
<div id="services-container">
    </div>
<button type="button" class="add-service" onclick="addServiceField()">
    <i class="fas fa-plus-circle"></i> Add Another Service
</button>

<div class="form-actions">
    <?php if ($updateClinic): ?>
    <a href="manage_clinics.php" class="cancel-btn">
        <i class="fas fa-times-circle"></i> Cancel Editing
    </a>
    <?php else: ?>
    <a href="admin_dashboard.php" class="cancel-btn">
        <i class="fas fa-arrow-circle-left"></i> Go Back
    </a>
    <?php endif; ?>
    
    <?php if ($updateClinic): ?>
    <button type="submit" name="update_and_add_new" class="update-and-new-btn">
        <i class="fas fa-plus-square"></i> Update & Add New
    </button>
    <?php endif; ?>
    
    <button type="submit" name="save_clinic">
        <i class="fas fa-save"></i> <?= $updateClinic ? "Update Clinic" : "Save Clinic" ?>
    </button>
</div>
</form>
</div>

<h2><i class="fas fa-list-alt"></i> Existing Clinics</h2>
<?php foreach($clinics as $c): ?>
<div class="clinic-card">
    <h3><?= htmlspecialchars($c['clinic_name']) ?></h3>
    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; font-size:0.9rem;">
        <div>
            <p><strong><i class="fas fa-database"></i> Database:</strong> <?= htmlspecialchars($c['db_name']) ?></p>
            <p><strong><i class="fas fa-map-marker-alt"></i> Address:</strong> <?= htmlspecialchars($c['address']) ?></p>
            <p><strong><i class="fas fa-phone"></i> Contact:</strong> <?= htmlspecialchars($c['contact_number']) ?></p>
        </div>
        <div style="grid-column: span 2;">
            <p><strong><i class="fas fa-info-circle"></i> About:</strong> <?= htmlspecialchars($c['description']) ?></p>
        </div>
    </div>
    
    <h4>Services Offered</h4>
    <div class="service-list">
        <?php
        $services=$pdo->prepare("SELECT * FROM clinic_services WHERE clinic_id=?");
        $services->execute([$c['clinic_id']]);
        $has_services = $services->rowCount() > 0;
        
        if ($has_services) {
            foreach($services as $s): ?>
            <div class="service-card">
                <?php if(!empty($s['image'])): ?>
                <img src="../<?= htmlspecialchars($s['image']) ?>" alt="Service Image">
                <?php endif; ?>
                <strong><?= htmlspecialchars($s['service_name']) ?></strong>
                <p style="font-size:1.1rem; color:#28a745;">₱<?= number_format($s['price'], 2) ?></p>
                <p><?= htmlspecialchars($s['description']) ?></p>
                <div class="action-links">
                    <a href="?clinic_id=<?= $c['clinic_id'] ?>" class="update-btn"><i class="fas fa-edit"></i> Edit</a>
                    <a href="?delete_service_id=<?= $s['service_id'] ?>" class="delete-btn" onclick="return confirm('Are you sure you want to delete the service: <?= htmlspecialchars($s['service_name']) ?>?')"><i class="fas fa-trash-alt"></i> Delete</a>
                </div>
            </div>
            <?php endforeach; 
        } else {
            echo "<p style='color:#6c757d;'>No services have been added for this clinic yet.</p>";
        }
        ?>
    </div>
    
    <?php if(!$has_services): ?>
    <div style="margin-top:20px;">
        <a href="?clinic_id=<?= $c['clinic_id'] ?>" class="update-btn" style="text-decoration:none;"><i class="fas fa-plus"></i> Add Services Now</a>
    </div>
    <?php endif; ?>

</div>
<?php endforeach; ?>
</div>
</body>
</html>