<?php
session_start();
// Security check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'secretary') {
    header('Location: ../public/login.php');
    exit();
}

$host = 'localhost'; 
$db   = 'dentitrack_main'; // <--- CHANGE THIS TO YOUR DB NAME
$user = 'root';   // <--- CHANGE THIS TO YOUR DB USER
$pass = '';    // <--- CHANGE THIS TO YOUR DB PASSWORD (e.g., '' for XAMPP root)
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     // The $conn variable is now successfully created here.
     $conn = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
     // If connection fails, stop execution and show error
     die("CRITICAL DB ERROR: Connection failed. Check credentials. Error: " . $e->getMessage()); 
}
// ==========================================================
// 🚀 END OF DATABASE CONNECTION SETUP
// ==========================================================

$patient_id = (int)($_GET['id'] ?? 0);
$message = '';
$error = '';

if ($patient_id === 0) {
    header('Location: view_patients.php?msg=invalid_id');
    exit();
}

// --- A. Handle Form Submission (POST Request) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Sanitize and Collect Data
    $id = $_POST['patient_id'];
    
    // Name Fields
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $fullname = trim("$first_name $middle_name $last_name"); // Recalculate fullname
    
    // Demographics
    $dob = $_POST['dob'];
    $contact_number = trim($_POST['contact_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $gender = $_POST['gender'] ?? '';
    $address = trim($_POST['address'] ?? '');

    // NEW FIELDS from add_patient.php
    $emergency_contact_name = trim($_POST['emergency_contact_name'] ?? '');
    $emergency_contact_number = trim($_POST['emergency_contact_number'] ?? '');
    $medical_history = trim($_POST['medical_history'] ?? '');
    $allergies = trim($_POST['allergies'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if (empty($first_name) || empty($last_name) || empty($contact_number)) {
        $error = "First Name, Last Name, and Contact Number are required fields.";
    } else {
        try {
            // 2. Prepare SQL UPDATE statement
            $sql_update = "UPDATE patient SET 
                            first_name = :first_name,
                            middle_name = :middle_name,
                            last_name = :last_name,
                            fullname = :fullname,  /* Keeping this for search compatibility */
                            dob = :dob,
                            contact_number = :contact_number,
                            email = :email,
                            gender = :gender,
                            address = :address,
                            emergency_contact_name = :emergency_contact_name,
                            emergency_contact_number = :emergency_contact_number,
                            medical_history = :medical_history,
                            allergies = :allergies,
                            notes = :notes
                            WHERE patient_id = :id";
            
            $stmt_update = $conn->prepare($sql_update);
            
            // 3. Bind parameters
            $stmt_update->bindParam(':first_name', $first_name);
            $stmt_update->bindParam(':middle_name', $middle_name);
            $stmt_update->bindParam(':last_name', $last_name);
            $stmt_update->bindParam(':fullname', $fullname);
            $stmt_update->bindParam(':dob', $dob);
            $stmt_update->bindParam(':contact_number', $contact_number);
            $stmt_update->bindParam(':email', $email);
            $stmt_update->bindParam(':gender', $gender);
            $stmt_update->bindParam(':address', $address);
            // Binding NEW fields
            $stmt_update->bindParam(':emergency_contact_name', $emergency_contact_name);
            $stmt_update->bindParam(':emergency_contact_number', $emergency_contact_number);
            $stmt_update->bindParam(':medical_history', $medical_history);
            $stmt_update->bindParam(':allergies', $allergies);
            $stmt_update->bindParam(':notes', $notes);
            $stmt_update->bindParam(':id', $id, PDO::PARAM_INT);
            
            // 4. Execute and Check Result
            if ($stmt_update->execute()) {
                $message = "Patient record updated successfully!";
                // Refresh $patient data by re-fetching below
            } else {
                $error = "Failed to update patient record.";
            }

        } catch (PDOException $e) {
            $error = "Database Error during update: " . $e->getMessage();
        }
    }
}

// --- B. Fetch Current Patient Data (GET Request or after POST) ---
try {
    // This fetches the patient's current data to populate the form fields.
    $sql_fetch = "SELECT * FROM patient WHERE patient_id = :id";
    $stmt_fetch = $conn->prepare($sql_fetch);
    $stmt_fetch->bindParam(':id', $patient_id, PDO::PARAM_INT);
    $stmt_fetch->execute();
    $patient = $stmt_fetch->fetch(PDO::FETCH_ASSOC);

    if (!$patient) {
        header('Location: view_patients.php?msg=patient_not_found');
        exit();
    }
} catch (PDOException $e) {
    die("Error fetching patient data: " . $e->getMessage());
}

// Utility function to calculate age (used for display only)
function calculateAge($dob) {
    if (!$dob) return 'N/A';
    try {
        $dobDate = new DateTime($dob);
        $now = new DateTime();
        return $now->diff($dobDate)->y;
    } catch (Exception $e) {
        return 'N/A';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Patient: <?= htmlspecialchars($patient['fullname'] ?? $patient['last_name']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous" />
    <style>
        /* Base styles */
        body { font-family: 'Segoe UI', sans-serif; background: #e6f0ff; color: #003366; }
        main { padding: 40px; max-width: 800px; margin: 20px auto; background: white; border-radius: 15px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
        h1 { color: #004080; margin-bottom: 30px; }
        
        /* Form styles */
        .name-group { display: flex; gap: 20px; margin-bottom: 20px; }
        .name-group .form-group { flex: 1; margin-bottom: 0; }
        
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 5px; color: #0066cc; }
        .form-group input[type="text"],
        .form-group input[type="date"],
        .form-group input[type="email"],
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #b3c6ff;
            border-radius: 8px;
            box-sizing: border-box;
            transition: border-color 0.3s;
        }
        .form-group textarea { resize: vertical; min-height: 100px; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: #3399ff; outline: none; box-shadow: 0 0 5px rgba(51, 153, 255, 0.5); }

        /* Two columns for emergency contacts */
        .contact-group { display: flex; gap: 20px; }
        .contact-group .form-group { flex: 1; }

        /* Message styles */
        .alert { padding: 15px; margin-bottom: 20px; border-radius: 8px; font-weight: 600; }
        .alert-success { background-color: #d4edda; color: #155724; border-color: #c3e6cb; }
        .alert-error { background-color: #f8d7da; color: #721c24; border-color: #f5c6cb; }

        /* Button styles */
        .btn-update {
            background-color: #00a000;
            color: white;
            font-weight: 700;
            padding: 12px 25px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            transition: background-color 0.3s;
            margin-top: 15px;
        }
        .btn-update:hover {
            background-color: #008000;
        }
        .back-link {
            display: inline-block;
            margin-left: 15px;
            color: #0066cc;
            text-decoration: none;
            font-weight: 600;
        }
        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>

<main>
    <h1><i class="fas fa-edit"></i> Edit Patient Record</h1>

    <?php if ($message): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="edit_patient_account.php?id=<?= (int)$patient_id ?>">
        <input type="hidden" name="patient_id" value="<?= (int)$patient['patient_id'] ?>">
        
        <div class="name-group">
            <div class="form-group">
                <label for="first_name">First Name <span style="color:red;">*</span></label>
                <input type="text" id="first_name" name="first_name" 
                       value="<?= htmlspecialchars($patient['first_name'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label for="middle_name">Middle Name</label>
                <input type="text" id="middle_name" name="middle_name" 
                       value="<?= htmlspecialchars($patient['middle_name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="last_name">Last Name <span style="color:red;">*</span></label>
                <input type="text" id="last_name" name="last_name" 
                       value="<?= htmlspecialchars($patient['last_name'] ?? '') ?>" required>
            </div>
        </div>
        <div class="form-group">
            <label for="dob">
                Date of Birth
                <strong style="color: #008000;">(Current Age: <?= calculateAge($patient['dob'] ?? '') ?>)</strong>
            </label>
            <input type="date" id="dob" name="dob" value="<?= htmlspecialchars($patient['dob'] ?? '') ?>">
        </div>
        
        <div class="form-group">
            <label for="gender">Gender</label>
            <select id="gender" name="gender">
                <option value="Male" <?= (($patient['gender'] ?? '') === 'Male') ? 'selected' : '' ?>>Male</option>
                <option value="Female" <?= (($patient['gender'] ?? '') === 'Female') ? 'selected' : '' ?>>Female</option>
                <option value="Other" <?= (($patient['gender'] ?? '') === 'Other') ? 'selected' : '' ?>>Other</option>
                <option value="" <?= (empty($patient['gender'])) ? 'selected' : '' ?>>Not Specified</option>
            </select>
        </div>

        <div class="form-group">
            <label for="contact_number">Contact Number <span style="color:red;">*</span></label>
            <input type="text" id="contact_number" name="contact_number" 
                   value="<?= htmlspecialchars($patient['contact_number'] ?? '') ?>" required>
        </div>

        <div class="form-group">
            <label for="email">Email Address</label>
            <input type="email" id="email" name="email" value="<?= htmlspecialchars($patient['email'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label for="address">Address</label>
            <textarea id="address" name="address"><?= htmlspecialchars($patient['address'] ?? '') ?></textarea>
        </div>
        
        <hr style="width:100%; border-color:#c4d2ff; margin:20px 0;">
        <h2><i class="fas fa-hand-holding-medical" style="color:#0066cc;"></i> Emergency Information</h2>

        <div class="contact-group">
            <div class="form-group">
                <label for="emergency_contact_name">Emergency Contact Name</label>
                <input type="text" id="emergency_contact_name" name="emergency_contact_name" 
                       value="<?= htmlspecialchars($patient['emergency_contact_name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="emergency_contact_number">Emergency Contact Number</label>
                <input type="text" id="emergency_contact_number" name="emergency_contact_number" 
                       value="<?= htmlspecialchars($patient['emergency_contact_number'] ?? '') ?>">
            </div>
        </div>

        <div class="form-group">
            <label for="medical_history">Medical History (Chronic conditions, medications, etc.)</label>
            <textarea id="medical_history" name="medical_history"><?= htmlspecialchars($patient['medical_history'] ?? '') ?></textarea>
        </div>

        <div class="form-group">
            <label for="allergies">Allergies (Drug, food, material allergies)</label>
            <textarea id="allergies" name="allergies"><?= htmlspecialchars($patient['allergies'] ?? '') ?></textarea>
        </div>
        
        <div class="form-group">
            <label for="notes">Internal Notes/Remarks</label>
            <textarea id="notes" name="notes"><?= htmlspecialchars($patient['notes'] ?? '') ?></textarea>
        </div>
        
        <button type="submit" class="btn-update"><i class="fas fa-save"></i> Save Changes</button>
        <a href="view_patients.php" class="back-link">Cancel and Go Back</a>
    </form>
</main>

</body>
</html>