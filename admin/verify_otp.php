<?php
date_default_timezone_set("Asia/Manila"); // PHP timezone
session_start();
require_once '../config/db_pdo.php'; // PDO connection

// Align MySQL timezone with PHP timezone
$pdo->exec("SET time_zone = '+08:00'");

$error = '';
$success = '';

// ---------- VERIFY OTP ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_otp'])) {
    if (!isset($_SESSION['reset_admin_email'])) {
        $error = 'Session expired. Please try again.';
    } else {
        $email = $_SESSION['reset_admin_email'];
        $otp_input = trim($_POST['otp'] ?? '');

        if (!$otp_input) {
            $error = 'OTP is required.';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users 
                WHERE email=? AND role='admin' AND otp_code=? AND otp_expiry >= NOW()");
            $stmt->execute([$email, $otp_input]);
            $user = $stmt->fetch();

            if (!$user) {
                $error = 'Invalid or expired OTP.';
            } else {
                // OTP verified successfully
                $_SESSION['otp_verified'] = true;
                $success = 'OTP verified successfully. You may now reset your password.';
                header("Location: reset_password.php");
                exit;
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Verify OTP - DentiTrack</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
body {
    font-family: sans-serif;
    background: #f0f6ff;
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 100vh;
    margin: 0;
}
.auth-wrapper {
    background: #fff;
    padding: 30px;
    border-radius: 12px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.15);
    width: 400px;
}
h2 {
    text-align: center;
    color: #007bff;
}
input {
    width: 100%;
    padding: 10px;
    margin: 8px 0;
    border-radius: 6px;
    border: 1px solid #ccc;
    box-sizing: border-box;
}
button {
    width: 100%;
    padding: 12px;
    background: #007bff;
    color: #fff;
    border: none;
    border-radius: 6px;
    font-weight: 600;
    cursor: pointer;
}
button:hover {
    background: #0056b3;
}
.message {
    padding: 10px;
    margin: 10px 0;
    border-radius: 6px;
    font-weight: 600;
}
.success {
    background: #e6ffed;
    color: #006600;
}
.error {
    background: #ffe6e6;
    color: #b30000;
}
.back-btn {
    display: block;
    margin-top: 10px;
    text-align: center;
    color: #333;
    text-decoration: none;
}
.back-btn:hover {
    text-decoration: underline;
}
</style>
</head>
<body>
<div class="auth-wrapper">
<?php if($error): ?>
    <div class="message error"><?=htmlspecialchars($error)?></div>
<?php endif; ?>
<?php if($success): ?>
    <div class="message success"><?=htmlspecialchars($success)?></div>
<?php endif; ?>

<h2><i class="fas fa-key"></i> Verify OTP</h2>
<form method="POST">
    <input type="text" name="otp" placeholder="Enter OTP Code" required>
    <button type="submit" name="verify_otp">Verify OTP</button>
</form>

<a href="admin_login.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Login</a>
</div>
</body>
</html>
