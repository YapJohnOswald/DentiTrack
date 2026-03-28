<?php
session_start();
require_once '../config/db_pdo.php';
require_once '../config/db_conn.php';

// --- PHPMailer Includes ---
require '../phpmailer-master/src/PHPMailer.php';
require '../phpmailer-master/src/SMTP.php';
require '../phpmailer-master/src/Exception.php';
require '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// --- SMTP Configuration ---
define('CONF_TIMEZONE', 'Asia/Manila');
define('CONF_SMTP_HOST', 'smtp.gmail.com');
define('CONF_SMTP_PORT', 587);
define('CONF_SMTP_USER', 'dentitrack2025@gmail.com');       
define('CONF_SMTP_PASS', 'gpmennmjrynhujzq');             

date_default_timezone_set(CONF_TIMEZONE);

// Access control
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'secretary') {
    header('Location: ../public/login.php');
    exit;
}

$conn = $pdo;
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$message = '';
$error   = '';
$recorded_by_user_id = $_SESSION['user_id'];

/* ======================================================
   1. CONFIRM PAYMENT LOGIC
====================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
    $request_id      = (int) $_POST['request_id'];
    $patient_id      = (int) $_POST['patient_id'];
    $payment_id      = !empty($_POST['payment_id']) && $_POST['payment_id'] !== 'NULL' ? (int) $_POST['payment_id'] : null;
    $amount_received = (float) $_POST['amount_received'];
    $payment_method  = $_POST['payment_method'];

    try {
        $conn->beginTransaction();
        
        $reqStmt = $conn->prepare("
            SELECT pr.*, p.email, CONCAT(p.first_name, ' ', p.last_name) as patient_name 
            FROM payment_requests pr 
            JOIN patient p ON pr.patient_id = p.patient_id 
            WHERE pr.request_id = :rid AND pr.status = 'pending' FOR UPDATE
        ");
        $reqStmt->execute([':rid' => $request_id]);
        $request = $reqStmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) { throw new Exception("Payment request already processed."); }

        $proof_image    = $request['proof_image'];
        $appointment_id = $request['appointment_id'];
        $patient_email  = $request['email'];
        $patient_name   = $request['patient_name'];

        if ($payment_id !== null) {
            $conn->prepare("UPDATE payments SET proof_image = :proof WHERE payment_id = :pid")
                 ->execute([':proof' => $proof_image, ':pid' => $payment_id]);
        } else {
            $create = $conn->prepare("INSERT INTO payments (patient_id, appointment_id, amount, total_amount, payment_method, payment_date, status, proof_image, created_at) VALUES (:pat, :appt, :amt, :amt, :method, CURDATE(), 'partial', :proof, NOW())");
            $create->execute([':pat' => $patient_id, ':appt' => $appointment_id, ':amt' => $amount_received, ':method' => $payment_method, ':proof'  => $proof_image]);
            $payment_id = $conn->lastInsertId();
        }

        $conn->prepare("INSERT INTO payment_transactions (payment_id, patient_id, amount_received, transaction_date, payment_method, payment_proof_path, recorded_by_user_id) VALUES (:pid, :pat, :amt, CURDATE(), :method, :proof, :recby)")
             ->execute([':pid' => $payment_id, ':pat' => $patient_id, ':amt' => $amount_received, ':method' => $payment_method, ':proof' => $proof_image, ':recby' => $recorded_by_user_id]);

        $conn->prepare("UPDATE patient SET outstanding_balance = GREATEST(outstanding_balance - :amt, 0) WHERE patient_id = :pid")
             ->execute([':amt' => $amount_received, ':pid' => $patient_id]);

        $conn->prepare("UPDATE payment_requests SET status = 'approved' WHERE request_id = :rid")
             ->execute([':rid' => $request_id]);

        $conn->commit();
        $message = "Payment confirmed successfully.";

    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

// Fetch Pending Requests
try {
    $pending_payments = $conn->query("
        SELECT pr.*, CONCAT(p.first_name, ' ', p.last_name) AS patient_name, p.outstanding_balance 
        FROM payment_requests pr 
        JOIN patient p ON pr.patient_id = p.patient_id 
        WHERE pr.status = 'pending' 
        ORDER BY pr.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $error = "Fetch Error"; }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Pending Payments | DentiTrack</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --sidebar-width: 240px; --primary-blue: #007bff; --text-dark: #343a40; --text-light: #6c757d; --bg-page: #f4f7fa; --widget-bg: #ffffff; --success-green: #28a745; --info-blue: #17a2b8; }
        
        body { margin: 0; font-family: 'Inter', sans-serif; background: var(--bg-page); color: var(--text-dark); display: flex; min-height: 100vh; overflow: hidden; }

        /* Invisible Scrollbar Utility */
        .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        .hide-scrollbar::-webkit-scrollbar { display: none; }

        /* Sidebar Styles */
        .sidebar { width: var(--sidebar-width); background: var(--widget-bg); box-shadow: 2px 0 10px rgba(0,0,0,0.05); display: flex; flex-direction: column; position: fixed; height: 100vh; z-index: 1000; }
        .sidebar-header { padding: 25px; text-align: center; font-size: 22px; font-weight: 700; color: var(--primary-blue); border-bottom: 1px solid #e9ecef; }
        .sidebar-nav { flex-grow: 1; padding: 10px 15px; overflow-y: auto; }
        .sidebar a { display: flex; align-items: center; gap: 12px; padding: 12px 15px; margin: 8px 0; color: var(--text-dark); text-decoration: none; font-weight: 500; border-radius: 8px; transition: all 0.3s ease; }
        .sidebar a:hover { background-color: rgba(0, 123, 255, 0.08); color: var(--primary-blue); }
        .sidebar a.active { background-color: var(--primary-blue); color: white; }
        .sidebar .logout { margin-top: auto; border-top: 1px solid #e9ecef; padding: 15px 30px; display: flex; align-items: center; gap: 12px; color: var(--text-dark); text-decoration: none; }

        /* Main Content Styles */
        .main-content { flex: 1; margin-left: var(--sidebar-width); padding: 40px; width: 100%; overflow-y: auto; height: 100vh; }
        .data-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.03); }
        .data-table th { background: #f8f9fa; color: var(--text-light); padding: 18px; text-align: left; font-weight: 600; text-transform: uppercase; font-size: 12px; letter-spacing: 0.5px; border-bottom: 2px solid #eee; }
        .data-table td { padding: 18px; border-bottom: 1px solid #f0f0f0; }

        /* Eye-Pleasing Buttons */
        .btn { padding: 9px 18px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.2s ease; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; }
        .btn-info { background: rgba(23, 162, 184, 0.1); color: #17a2b8; }
        .btn-info:hover { background: #17a2b8; color: #fff; transform: translateY(-1px); }
        .btn-success { background: #28a745; color: #fff; box-shadow: 0 4px 10px rgba(40, 167, 69, 0.2); }
        .btn-success:hover { opacity: 0.9; transform: translateY(-1px); }

        /* Eye-Pleasing Modal */
        .modal { display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); justify-content: center; align-items: center; z-index: 2000; }
        .modal-body { background: #fff; border-radius: 20px; width: 100%; max-width: 480px; max-height: 85vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); position: relative; animation: modalSlide 0.3s ease-out; }
        @keyframes modalSlide { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

        .modal-header { padding: 25px 25px 15px; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; }
        .modal-header h3 { margin: 0; font-size: 20px; color: var(--text-dark); }
        .modal-content { padding: 25px; }
        .detail-row { display: flex; justify-content: space-between; margin-bottom: 12px; }
        .detail-label { color: var(--text-light); font-size: 14px; }
        .detail-value { font-weight: 600; font-size: 14px; text-align: right; }
        .proof-img { width: 100%; border-radius: 12px; margin-top: 15px; border: 1px solid #eef0f2; cursor: zoom-in; }
        
        .btn-close-modal { width: calc(100% - 50px); margin: 0 25px 25px; padding: 12px; border: none; background: #f1f5f9; color: var(--text-dark); font-weight: 700; border-radius: 10px; cursor: pointer; transition: background 0.2s; }
        .btn-close-modal:hover { background: #e2e8f0; }
    </style>
</head>
<body>

    <div class="sidebar">
        <div class="sidebar-header"><i class="fas fa-tooth"></i> DentiTrack</div>
        <nav class="sidebar-nav hide-scrollbar">
            <a href="secretary_dashboard.php"><i class="fas fa-chart-line"></i> Dashboard</a>
            <a href="find_patient.php"><i class="fas fa-search"></i> Find Patient</a>
            <a href="view_patients.php"><i class="fas fa-users"></i> Patients List</a>
            <a href="online_bookings.php"><i class="fas fa-calendar-check"></i> Booking Mgmt</a>
            <a href="appointments.php"><i class="fas fa-calendar-alt"></i> Consultations</a>
            <a href="services_list.php"><i class="fas fa-briefcase-medical"></i> Services</a>
            <a href="inventory.php"><i class="fas fa-boxes"></i> Inventory Stock</a>
            <a href="payments.php"><i class="fas fa-money-bill-wave"></i> Process Payments</a>
            <a href="pending_installments.php"><i class="fas fa-credit-card"></i> Pending Installments</a>
            <a href="pending_payments.php" class="active"><i class="fas fa-credit-card"></i> Pending Payments</a>
            <a href="payment_logs.php"><i class="fas fa-file-invoice-dollar"></i> Payments Log</a>
            <a href="create_announcements.php"><i class="fas fa-bullhorn"></i> Announcements</a>
        </nav>
        <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Log Out</a>
    </div>

    <main class="main-content hide-scrollbar">
        <h2 style="margin-bottom: 30px;"><i class="fas fa-wallet" style="color:var(--primary-blue)"></i> Online Payment Requests</h2>

        <?php if ($message): ?><div style="padding:15px; background:#dcfce7; color:#166534; border-radius:10px; margin-bottom:20px; font-weight:500;"><?= $message ?></div><?php endif; ?>
        <?php if ($error): ?><div style="padding:15px; background:#fee2e2; color:#991b1b; border-radius:10px; margin-bottom:20px; font-weight:500;"><?= $error ?></div><?php endif; ?>

        <table class="data-table">
            <thead>
                <tr>
                    <th>Patient</th>
                    <th>Requested Amount</th>
                    <th>Method</th>
                    <th>Date</th>
                    <th style="text-align:center;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pending_payments as $row): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($row['patient_name']) ?></strong></td>
                        <td style="color:var(--success-green); font-weight:700">₱<?= number_format($row['requested_amount'], 2) ?></td>
                        <td><span style="background:#f1f5f9; padding:4px 10px; border-radius:20px; font-size:12px; font-weight:600;"><?= htmlspecialchars($row['payment_method']) ?></span></td>
                        <td><?= date('M d, Y', strtotime($row['created_at'])) ?></td>
                        <td style="text-align:center;">
                            <button class="btn btn-info" onclick='openReview(<?= json_encode($row) ?>)'>
                                <i class="fas fa-expand-alt"></i> View
                            </button>
                            
                            <form method="POST" style="display:inline" onsubmit="return confirm('Confirm payment?')">
                                <input type="hidden" name="request_id" value="<?= $row['request_id'] ?>">
                                <input type="hidden" name="patient_id" value="<?= $row['patient_id'] ?>">
                                <input type="hidden" name="amount_received" value="<?= $row['requested_amount'] ?>">
                                <input type="hidden" name="payment_method" value="<?= $row['payment_method'] ?>">
                                <button class="btn btn-success" name="confirm_payment"><i class="fas fa-check"></i> Confirm</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </main>

    <div id="reviewModal" class="modal">
        <div class="modal-body hide-scrollbar">
            <div class="modal-header">
                <h3>Payment Verification</h3>
                <i class="fas fa-times" onclick="closeReview()" style="cursor:pointer; color:var(--text-light)"></i>
            </div>
            
            <div class="modal-content">
                <div class="detail-row">
                    <span class="detail-label">Patient Name</span>
                    <span class="detail-value" id="m_name"></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Outstanding Balance</span>
                    <span class="detail-value" id="m_balance"></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Amount Transferred</span>
                    <span class="detail-value" id="m_amount" style="color:var(--success-green); font-size:18px;"></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Payment Channel</span>
                    <span class="detail-value" id="m_method"></span>
                </div>
                
                <div style="margin-top:15px; background: #fffbeb; border: 1px solid #fef3c7; padding: 12px; border-radius: 10px;">
                    <span style="font-size:12px; color:#92400e; font-weight:700; text-transform:uppercase;">Patient Note</span>
                    <p id="m_note" style="margin:5px 0 0; font-size:14px; color:#b45309; line-height:1.4;"></p>
                </div>

                <div style="margin-top:20px;">
                    <span class="detail-label">Proof of Payment</span>
                    <img id="m_img" class="proof-img" onclick="window.open(this.src, '_blank')" title="Click to enlarge">
                </div>
            </div>

            <button class="btn-close-modal" onclick="closeReview()">Close Review</button>
        </div>
    </div>

    <script>
        function openReview(data) {
            document.getElementById('m_name').innerText = data.patient_name;
            document.getElementById('m_balance').innerText = "₱" + parseFloat(data.outstanding_balance).toLocaleString('en-US', {minimumFractionDigits: 2});
            document.getElementById('m_amount').innerText = "₱" + parseFloat(data.requested_amount).toLocaleString('en-US', {minimumFractionDigits: 2});
            document.getElementById('m_method').innerText = data.payment_method;
            document.getElementById('m_note').innerText = data.request_note || "No Message provided.";
            document.getElementById('m_img').src = "../" + data.proof_image;
            document.getElementById('reviewModal').style.display = 'flex';
        }
        function closeReview() { document.getElementById('reviewModal').style.display = 'none'; }

        // Close when clicking outside
        window.onclick = function(e) { if(e.target == document.getElementById('reviewModal')) closeReview(); }
    </script>
</body>
</html>