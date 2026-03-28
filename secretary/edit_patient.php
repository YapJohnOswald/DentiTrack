<?php
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'secretary') {
    header('Location: secretary_login.php');
    exit();
}

include '../config/db_pdo.php';

$patient_id = $_GET['id'] ?? '';
if (!$patient_id) {
    header('Location: view_patients.php');
    exit();
}

// Fetch patient details
$stmt = $conn->prepare("SELECT * FROM patient WHERE patient_id = :id");
$stmt->bindParam(':id', $patient_id, PDO::PARAM_INT);
$stmt->execute();
$patient = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$patient) {
    echo "Patient not found.";
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name']);
    $dob = trim($_POST['dob']);
    $contact_info = trim($_POST['contact_info']);

    $update = $conn->prepare("UPDATE patient SET full_name = :full_name, dob = :dob, contact_info = :contact_info WHERE patient_id = :id");
    $update->bindParam(':full_name', $full_name);
    $update->bindParam(':dob', $dob);
    $update->bindParam(':contact_info', $contact_info);
    $update->bindParam(':id', $patient_id, PDO::PARAM_INT);
    $update->execute();

    header('Location: view_patients.php?message=updated');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Edit Patient - DentiTrack</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous" />
<style>
body {
    margin: 0;
    font-family: 'Segoe UI', sans-serif;
    background: #e6f0ff;
    color: #003366;
    display: flex;
    height: 100vh;
}

.container {
    margin: auto;
    background: white;
    padding: 40px;
    border-radius: 20px;
    box-shadow: 0 8px 20px rgba(0,0,0,0.1);
    width: 400px;
}

h2 {
    text-align: center;
    font-size: 1.8rem;
    margin-bottom: 30px;
    color: #004080;
    text-shadow: 1px 1px 2px #a3c2ff;
}

form label {
    display: block;
    font-weight: 600;
    margin-bottom: 8px;
    color: #003366;
}

form input[type="text"],
form input[type="date"] {
    width: 100%;
    padding: 12px 15px;
    border-radius: 12px;
    border: 1.5px solid #b3c6ff;
    font-size: 16px;
    margin-bottom: 20px;
    transition: border-color 0.3s ease;
}

form input:focus {
    border-color: #3399ff;
    outline: none;
    box-shadow: 0 0 8px #3399ff88;
}

form button {
    width: 100%;
    background-color: #0066cc;
    color: white;
    font-weight: 700;
    font-size: 16px;
    padding: 12px;
    border: none;
    border-radius: 12px;
    cursor: pointer;
    box-shadow: 0 4px 10px rgba(0,102,204,0.4);
    transition: background-color 0.3s ease;
}

form button:hover {
    background-color: #004080;
    box-shadow: 0 6px 12px rgba(0,64,128,0.6);
}

a.back-link {
    display: inline-block;
    margin-top: 20px;
    text-align: center;
    width: 100%;
    text-decoration: none;
    color: #0066cc;
    font-weight: 700;
    border: 1.5px solid #0066cc;
    padding: 10px 0;
    border-radius: 12px;
    transition: background-color 0.3s ease, color 0.3s ease;
}

a.back-link:hover {
    background-color: #0066cc;
    color: white;
}
</style>
</head>
<body>

<div class="container">
    <h2><i class="fas fa-edit"></i> Edit Patient</h2>
    <form method="POST">
        <label>Full Name:</label>
        <input type="text" name="full_name" value="<?= htmlspecialchars($patient['full_name']) ?>" required>

        <label>Date of Birth:</label>
        <input type="date" name="dob" value="<?= htmlspecialchars($patient['dob']) ?>" required>

        <label>Contact Info:</label>
        <input type="text" name="contact_info" value="<?= htmlspecialchars($patient['contact_info']) ?>" required>

        <button type="submit"><i class="fas fa-save"></i> Update</button>
    </form>

    <a href="view_patients.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to List</a>
</div>

</body>
</html>
