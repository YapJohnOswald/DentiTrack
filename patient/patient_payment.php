<?php
session_start();

/* ======================================================
   1. STRICT SESSION CHECK
====================================================== */
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: ../public/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$role    = $_SESSION['role'];

if (strtolower($role) !== 'patient') {
    header("Location: ../public/login.php");
    exit();
}

/* ======================================================
   2. DATABASE CONNECTION
====================================================== */
$conn = new mysqli("localhost", "root", "", "dentitrack_main");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

/* ======================================================
   3. GET PATIENT INFO + OUTSTANDING BALANCE
====================================================== */
$patient_stmt = $conn->prepare("SELECT patient_id, first_name, last_name, outstanding_balance FROM patient WHERE user_id=?");
$patient_stmt->bind_param("i", $user_id);
$patient_stmt->execute();
$patient_res = $patient_stmt->get_result();
$patient_row = $patient_res->fetch_assoc();

if (!$patient_row) {
    die("Patient record not found.");
}

$patient_id = $patient_row['patient_id'];
$outstanding = floatval($patient_row['outstanding_balance'] ?? 0.00);

/* ======================================================
   4. HANDLE NEW PAYMENT REQUEST (PRG PATTERN)
====================================================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['submit_payment'])) {
    $pay_amount = floatval($_POST['pay_amount']);
    $method     = $_POST['payment_method'];
    $trans_id   = trim($_POST['transaction_id']); // New Transaction ID field
    $appt_id    = (!empty($_POST['appointment_id'])) ? intval($_POST['appointment_id']) : null;
    $proof_image = null;

    if (isset($_FILES['gcash_receipt']) && $_FILES['gcash_receipt']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['gcash_receipt']['tmp_name'];
        $file_ext = pathinfo($_FILES['gcash_receipt']['name'], PATHINFO_EXTENSION);
        $file_name = 'proof_' . uniqid() . '.' . $file_ext;
        $upload_dir = '../uploads/payment_proofs/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        move_uploaded_file($file_tmp, $upload_dir . $file_name);
        $proof_image = 'uploads/payment_proofs/' . $file_name;
    }

    if ($pay_amount > 0 && $proof_image) {
        // Updated query to include transaction_id
        $stmt = $conn->prepare(
            "INSERT INTO payment_requests 
            (patient_id, appointment_id, requested_amount, payment_method, transaction_id, proof_image, status, request_date)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())"
        );
        $stmt->bind_param("iidsss", $patient_id, $appt_id, $pay_amount, $method, $trans_id, $proof_image);

        if ($stmt->execute()) {
            header("Location: patient_payment.php?status=success");
            exit();
        } else {
            header("Location: patient_payment.php?status=error");
            exit();
        }
    }
}

// Prepare display message based on URL status
$msg = "";
if (isset($_GET['status'])) {
    if ($_GET['status'] == 'success') {
        $msg = "Payment request submitted! Waiting for clinic approval.";
    } elseif ($_GET['status'] == 'error') {
        $msg = "Failed to submit request. Please try again.";
    }
}

// Get last appointment ID for the hidden field
$appt_stmt = $conn->prepare("SELECT appointment_id FROM appointments WHERE patient_id=? ORDER BY appointment_id DESC LIMIT 1");
$appt_stmt->bind_param("i", $patient_id);
$appt_stmt->execute();
$appt_res = $appt_stmt->get_result();
$appt_row = $appt_res->fetch_assoc();
$appt_id_display = ($appt_row) ? intval($appt_row['appointment_id']) : "";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DentiTrack | Payment Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <style>
        :root {
            --primary-blue: #007bff; --secondary-blue: #0056b3; --text-dark: #343a40;
            --text-light: #6c757d; --bg-page: #eef2f8; --widget-bg: #ffffff;
            --radius: 12px; --sidebar-width: 250px;
        }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Inter', sans-serif; background: var(--bg-page); color: var(--text-dark); display: flex; }

        .sidebar { width: var(--sidebar-width); background: var(--widget-bg); box-shadow: 2px 0 10px rgba(0,0,0,0.05); display: flex; flex-direction: column; position: fixed; height: 100vh; z-index: 1000; }
        .sidebar-header { padding: 25px; text-align: center; font-size: 24px; font-weight: 700; color: var(--primary-blue); border-bottom: 1px solid #e9ecef; }
        .sidebar-nav { flex-grow: 1; padding-top: 20px; }
        .sidebar a { display: flex; align-items: center; gap: 12px; padding: 12px 25px; margin: 4px 0; color: var(--text-dark); text-decoration: none; font-weight: 500; transition: 0.3s; }
        .sidebar a.active { background: rgba(0, 123, 255, 0.1); color: var(--primary-blue); border-left: 4px solid var(--primary-blue); }
        .sidebar a:hover:not(.active) { background: #f8f9fa; }
        
        .logout-section { border-top: 1px solid #e9ecef; padding: 20px 0; }
        .logout-link { color: #dc3545 !important; }
        .logout-link:hover { background: #fff5f5 !important; }

        .main-content { flex: 1; margin-left: var(--sidebar-width); padding: 40px; }
        .records-container { background:#fff; padding:25px; border-radius:var(--radius); box-shadow:0 4px 15px rgba(0,0,0,0.05); margin-bottom:30px; }
        .balance-widget { border-left: 5px solid #dc3545; }
        #currentBalanceDisplay { font-weight:800; color:#dc3545; font-size:2.2rem; display:block; margin:10px 0; }
        
        .payment-form { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
        .form-group { display:flex; flex-direction:column; gap:8px; }
        input, select { padding:12px; border:1px solid #ced4da; border-radius:8px; font-size:1rem; }
        .submit-btn { grid-column:span 2; background:var(--primary-blue); color:white; border:none; padding:15px; border-radius:8px; font-weight:700; cursor:pointer; }

        .records-table { width:100%; border-collapse:separate; border-spacing:0; }
        .records-table th { background:#007bff; color:white; padding:12px; text-align:left; font-size:0.8rem; text-transform:uppercase; }
        .records-table td { padding:12px; border-bottom:1px solid #f0f0f0; }
        .status-badge { padding:4px 10px; border-radius:20px; font-size:0.75rem; font-weight:700; }
        .pending { background:#fff3cd; color:#856404; }
        .approved, .paid { background:#d4edda; color:#155724; }
        .rejected { background:#f8d7da; color:#721c24; }

        .qr-overlay { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); justify-content:center; align-items:center; z-index:3000; }
        .qr-card { background:#fff; padding:30px; border-radius:20px; width:350px; text-align: center; }
        .acc-info { font-size: 1.2rem; font-weight: 800; color: var(--primary-blue); margin-bottom: 20px; }
        .close-qr-btn { background: #f1f3f5; border: none; padding: 12px 0; width: 100%; border-radius: 10px; cursor: pointer; }
    </style>
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-header"><i class="fas fa-tooth"></i> DentiTrack</div>
    <nav class="sidebar-nav">
        <a href="patient_dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
        <a href="patient_appointments.php"><i class="fas fa-calendar-check"></i> Appointments</a>
        <a href="patient_records.php"><i class="fas fa-file-medical"></i> Records</a>
        <a href="patient_payment.php" class="active"><i class="fas fa-credit-card"></i> Payments</a>
        <a href="Profile.php"><i class="fas fa-user"></i> Profile</a>
    </nav>
    <div class="logout-section">
        <a href="logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</aside>

<main class="main-content">
    <?php if($msg): ?>
        <div style="background:#d4edda; color:#155724; padding:15px; border-radius:10px; margin-bottom:20px; border: 1px solid #c3e6cb;"><?= $msg ?></div>
    <?php endif; ?>

    <div class="records-container balance-widget">
        <h2>Remaining Balance</h2>
        <span id="currentBalanceDisplay">₱<?= number_format($outstanding, 2) ?></span>
    </div>

    <div class="records-container">
        <h3>Submit Online Payment</h3>
        <div style="text-align:center; margin-bottom:20px;">
            <button type="button" onclick="showQr('gcash-qr')" style="padding:10px; border:1px solid #007bff; background:none; color:#007bff; border-radius:8px; cursor:pointer; margin-right:10px;">View GCash QR</button>
            <button type="button" onclick="showQr('bank-qr')" style="padding:10px; border:1px solid #007bff; background:none; color:#007bff; border-radius:8px; cursor:pointer;">View Bank QR</button>
        </div>
        <form method="POST" class="payment-form" enctype="multipart/form-data">
            <div class="form-group">
                <label>Amount (₱):</label>
                <input type="number" name="pay_amount" max="<?= $outstanding ?>" step="0.01" required>
            </div>
            <div class="form-group">
                <label>Method:</label>
                <select name="payment_method" required>
                    <option value="GCash">GCash</option>
                    <option value="Bank Transfer">Bank Transfer</option>
                </select>
            </div>
            <div class="form-group" style="grid-column: span 2;">
                <label>Reference / Transaction ID:</label>
                <input type="text" name="transaction_id" placeholder="Enter the ID from your receipt" required>
            </div>
            <div class="form-group" style="grid-column: span 2;">
                <label>Upload Receipt:</label>
                <input type="file" name="gcash_receipt" accept="image/*" required>
            </div>
            <input type="hidden" name="appointment_id" value="<?= $appt_id_display ?>">
            <button type="submit" name="submit_payment" class="submit-btn">SUBMIT REQUEST</button>
        </form>
    </div>

    <div class="records-container">
        <h3>History & Status</h3>
        <table class="records-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Method</th>
                    <th>Ref ID</th>
                    <th>Status</th>
                    <th style="text-align:right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Show manual payment_requests with transaction_id
                $query = "SELECT request_date as dt, payment_method as mth, transaction_id as tid, status as st, requested_amount as amt 
                          FROM payment_requests 
                          WHERE patient_id = ? 
                          ORDER BY dt DESC LIMIT 15";
                
                $hist = $conn->prepare($query);
                $hist->bind_param("i", $patient_id);
                $hist->execute();
                $res = $hist->get_result();

                if ($res->num_rows > 0):
                    while($h = $res->fetch_assoc()):
                ?>
                <tr>
                    <td><?= date('M d, Y', strtotime($h['dt'])) ?></td>
                    <td><?= htmlspecialchars($h['mth'] ?? 'N/A') ?></td>
                    <td><small style="color:#666;"><?= htmlspecialchars($h['tid'] ?? '---') ?></small></td>
                    <td><span class="status-badge <?= strtolower($h['st']) ?>"><?= strtoupper($h['st']) ?></span></td>
                    <td style="text-align:right; font-weight:700; color: #007bff;">₱<?= number_format($h['amt'], 2) ?></td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="5" style="text-align:center; padding:30px; color:#999;">No online payment requests found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>

<div id="gcash-qr" class="qr-overlay">
    <div class="qr-card">
        <h3>GCash QR</h3>
        <img src="../uploads/online_payment/gcash-qr.png" width="200" style="margin-bottom:15px;">
        <div class="acc-info">0991 363 7693</div>
        <button class="close-qr-btn" onclick="hideQr('gcash-qr')">Close</button>
    </div>
</div>

<div id="bank-qr" class="qr-overlay">
    <div class="qr-card">
        <h3>Bank QR</h3>
        <img src="../uploads/online_payment/bank-qr.png" width="200" style="margin-bottom:15px;">
        <div class="acc-info">9108-2025-0012-3456</div>
        <button class="close-qr-btn" onclick="hideQr('bank-qr')">Close</button>
    </div>
</div>

<script>
    function showQr(id) { document.getElementById(id).style.display = 'flex'; }
    function hideQr(id) { document.getElementById(id).style.display = 'none'; }
</script>

</body>
</html>