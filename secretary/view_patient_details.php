<?php
session_start();
// Security check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'secretary') {
    header('Location: ../public/login.php');
    exit();
}

include '../config/db_pdo.php'; // Ensure this file provides the $conn PDO instance
include '../config/db_conn.php';
// 1. Get and Validate Patient ID
$patient_id = (int)($_GET['id'] ?? 0);

if ($patient_id === 0) {
    // Redirect if no valid ID is provided
    header('Location: view_patients.php');
    exit();
}

// 2. Fetch Patient Data
$sql = "SELECT * FROM patient WHERE patient_id = :id";
$stmt = $conn->prepare($sql);
$stmt->bindParam(':id', $patient_id, PDO::PARAM_INT);
$stmt->execute();
$patient = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$patient) {
    // Redirect if patient not found in the database
    header('Location: view_patients.php?error=notfound');
    exit();
}

// 3. Utility Function (re-included for self-contained functionality)
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
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Patient Details: <?= htmlspecialchars($patient['fullname']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous" />
    <style>
        /* Shared Styles for Consistency */
        body { font-family: 'Segoe UI', sans-serif; background: #e6f0ff; color: #003366; }
        main.main-content { padding: 40px; max-width: 900px; margin: 0 auto; background: white; border-radius: 15px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
        header h1 { font-size: 2rem; color: #004080; display: flex; align-items: center; gap: 0.5rem; margin-bottom: 20px; }

        /* Detail Card Styles */
        .detail-card {
            background: #f9fbff;
            border-radius: 10px;
            padding: 30px;
            border: 1px solid #cce0ff;
            margin-bottom: 20px;
        }
        .detail-card h2 {
            border-bottom: 2px solid #3399ff;
            padding-bottom: 10px;
            margin-top: 0;
            color: #0066cc;
            font-size: 1.5rem;
        }
        .detail-row {
            display: flex;
            padding: 12px 0;
            border-bottom: 1px solid #eef3ff;
            align-items: flex-start;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            flex: 0 0 200px; /* Fixed width for the label */
            font-weight: 600;
            color: #004080;
        }
        .detail-value {
            flex: 1;
            color: #333;
            word-wrap: break-word; /* Ensure long text wraps */
        }
        
        /* Action Button Styles */
        a.action-btn {
            display: inline-block;
            margin-right: 10px;
            font-weight: 700;
            text-decoration: none;
            padding: 8px 18px;
            border: 1.5px solid #0066cc;
            border-radius: 12px;
            transition: background-color 0.3s ease, color 0.3s ease;
            color: #0066cc;
            background: #e6f0ff;
        }
        a.action-btn:hover {
            background-color: #0066cc;
            color: white;
        }
    </style>
</head>
<body>

<main class="main-content">
    <header>
        <h1><i class="fas fa-user-circle"></i> Patient Record: <?= htmlspecialchars($patient['fullname']) ?></h1>
        <a href="view_patients.php" class="action-btn"><i class="fas fa-arrow-left"></i> Back to List</a>
        <a href="edit_patient_account.php?id=<?= (int)$patient['patient_id'] ?>" class="action-btn"><i class="fas fa-edit"></i> Edit Record</a>
    </header>

    <div class="detail-card">
        <h2>General & Contact Information</h2>
        <div class="detail-row">
            <span class="detail-label">Patient ID:</span>
            <span class="detail-value"><?= (int)$patient['patient_id'] ?></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Full Name:</span>
            <span class="detail-value"><?= htmlspecialchars($patient['fullname']) ?></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Date of Birth:</span>
            <span class="detail-value"><?= htmlspecialchars($patient['dob'] ?? 'N/A') ?></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Age:</span>
            <span class="detail-value"><?= calculateAge($patient['dob']) ?></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Gender:</span>
            <span class="detail-value"><?= htmlspecialchars($patient['gender'] ?? 'N/A') ?></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Contact Number:</span>
            <span class="detail-value"><?= htmlspecialchars($patient['contact_number'] ?? 'N/A') ?></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Email:</span>
            <span class="detail-value"><?= htmlspecialchars($patient['email'] ?? 'N/A') ?></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Address:</span>
            <span class="detail-value"><?= nl2br(htmlspecialchars($patient['address'] ?? 'N/A')) ?></span>
        </div>
    </div>

    <div class="detail-card">
        <h2>Medical & Dental History</h2>
        <?php 
        // Example for displaying a large text field like medical history
        $med_history = $patient['medical_history'] ?? 'No history recorded.';
        ?>
        <div class="detail-row">
            <span class="detail-label">Medical History:</span>
            <span class="detail-value"><?= nl2br(htmlspecialchars($med_history)) ?></span>
        </div>
        
        <?php 
        // Add other relevant medical/dental fields here:
        $dental_notes = $patient['dental_notes'] ?? 'No dental notes available.';
        ?>
        <div class="detail-row">
            <span class="detail-label">Dental Notes:</span>
            <span class="detail-value"><?= nl2br(htmlspecialchars($dental_notes)) ?></span>
        </div>
        
        </div>
</main>

</body>
</html>