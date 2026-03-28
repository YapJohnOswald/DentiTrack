<?php
session_start();
require_once '../config/db_pdo.php';
require '../phpmailer-master/src/PHPMailer.php';
require '../phpmailer-master/src/SMTP.php';
require '../phpmailer-master/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');

$email = trim($_POST['email'] ?? '');
if (!$email) {
    echo json_encode(['error' => 'Email is required']);
    exit;
}

// Fetch admin
$stmt = $pdo->prepare("SELECT * FROM users WHERE email=? AND role='admin'");
$stmt->execute([$email]);
$admin = $stmt->fetch();

if (!$admin) {
    echo json_encode(['error' => 'Admin account not found']);
    exit;
}

// Generate OTP
$otp = random_int(100000, 999999);
$expiry = date("Y-m-d H:i:s", strtotime("+10 minutes"));

// Update OTP in DB
$stmt2 = $pdo->prepare("UPDATE users SET otp_code=?, otp_expiry=? WHERE user_id=?");
$stmt2->execute([$otp, $expiry, $admin['user_id']]);

$_SESSION['reset_admin_email'] = $email;

// Send OTP via PHPMailer
try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'your_gmail@gmail.com';
    $mail->Password = 'your_app_password';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;

    $mail->setFrom('your_gmail@gmail.com','DentiTrack');
    $mail->addAddress($email);

    $mail->isHTML(true);
    $mail->Subject = 'DentiTrack Admin OTP';
    $mail->Body = "<h3>Hello Admin,</h3><p>Your OTP code is: <b>$otp</b></p><p>Expires in 10 minutes.</p>";

    $mail->send();
    echo json_encode(['success' => 'OTP sent']);
} catch(Exception $e){
    echo json_encode(['error' => 'Mailer Error: ' . $mail->ErrorInfo]);
}
