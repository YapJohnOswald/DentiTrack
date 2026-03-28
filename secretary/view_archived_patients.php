<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'secretary') {
    header('Location: ../public/login.php');
    exit();
}

include '../config/db_pdo.php'; // Ensure this provides the $conn PDO instance

$message = $_GET['msg'] ?? '';

// --- A. Fetch Archived Patients ---
try {
    $sql = "SELECT patient_id, fullname, contact_number, email FROM patient WHERE is_archived = 1 ORDER BY fullname ASC";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $archived_patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Archived Patients</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous" />
    </head>
<body>

<main>
    <h1><i class="fas fa-box-archive"></i> Archived Patients</h1>

    <?php if ($message === 'restore_success'): ?>
        <div style="color: green;">Patient successfully restored!</div>
    <?php elseif ($message === 'restore_failed'): ?>
        <div style="color: red;">Error restoring patient.</div>
    <?php endif; ?>
    
    <p><a href="view_patients.php">← Back to Active Patients</a></p>

    <?php if (count($archived_patients) > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Full Name</th>
                    <th>Contact</th>
                    <th>Email</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($archived_patients as $patient): ?>
                <tr>
                    <td><?= htmlspecialchars($patient['patient_id']) ?></td>
                    <td><?= htmlspecialchars($patient['fullname']) ?></td>
                    <td><?= htmlspecialchars($patient['contact_number']) ?></td>
                    <td><?= htmlspecialchars($patient['email']) ?></td>
                    <td>
                        <a href="restore_patient.php?id=<?= $patient['patient_id'] ?>" 
                           onclick="return confirm('Are you sure you want to restore <?= htmlspecialchars($patient['fullname']) ?>?')"
                           title="Restore Patient">
                           <i class="fas fa-undo"></i> Restore
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No patients are currently archived.</p>
    <?php endif; ?>
</main>
</body>
</html>