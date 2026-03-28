<?php
session_start();
require '../config/db_pdo.php';
require '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Dotenv\Dotenv;

header('Content-Type: application/json');

// Validate POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error'=>'Invalid request']);
    exit;
}

// Get email
$email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
if (!$email) {
    echo json_encode(['error'=>'Valid email required']);
    exit;
}

// Check if email exists
$stmt = $pdo->prepare("SELECT user_id, username FROM users WHERE username=? AND role='admin'");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(['error'=>'Admin not found']);
    exit;
}

// Generate OTP & expiry (10 min)
$otp = random_int(100000,999999);
$expiry = date("Y-m-d H:i:s", strtotime('+10 minutes'));

// Save OTP in database
$stmt = $pdo->prepare("UPDATE users SET otp_code=?, otp_expiry=? WHERE user_id=?");
$stmt->execute([$otp, $expiry, $user['user_id']]);

// Save in session
$_SESSION['reset_admin'] = $user['username'];

// Send OTP via PHPMailer
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host = $_ENV['SMTP_HOST'];
    $mail->SMTPAuth = true;
    $mail->Username = $_ENV['SMTP_USER'];
    $mail->Password = $_ENV['SMTP_PASS'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = $_ENV['SMTP_PORT'];

    $mail->setFrom($_ENV['SMTP_USER'], 'DentiTrack Admin Reset');
    $mail->addAddress($email);

    $mail->isHTML(true);
    $mail->Subject = 'DentiTrack OTP for Password Reset';
    $mail->Body = "
        <h3>Hello {$user['username']}</h3>
        <p>Your OTP code is: <b>$otp</b></p>
        <p>It expires in 10 minutes.</p>
    ";

    $mail->send();
    echo json_encode(['success'=>'OTP sent to your email']);
} catch (Exception $e) {
    echo json_encode(['error'=>'Mailer Error: '.$mail->ErrorInfo]);
}
?>
