<?php
session_start();
require_once '../config/db_pdo.php'; // PDO connection
require '../phpmailer-master/src/PHPMailer.php';
require '../phpmailer-master/src/SMTP.php';
require '../phpmailer-master/src/Exception.php';
require '../vendor/autoload.php'; // for Dotenv

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Dotenv\Dotenv;

// ---------- LOAD ENV ----------
$dotenv = Dotenv::createImmutable(__DIR__ . "/..");
$dotenv->load();

// ---------- TIMEZONE ----------
date_default_timezone_set("Asia/Manila");
$pdo->exec("SET time_zone = '+08:00'");

$error = '';
$success = '';
$showForgot = false;
$showVerify = false;
$showReset = false;

// If OTP verified already
if (isset($_SESSION['otp_verified']) && $_SESSION['otp_verified'] === true) {
    $showReset = true;
}

// ---------- LOGIN ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username=? LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        // Set session
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['clinic_name'] = $user['clinic_name'] ?? '';

        // Redirect based on role
        switch ($user['role']) {
            case 'admin':
                header('Location: ../admin/admin_dashboard.php');
                break;
            case 'secretary':
                header('Location: ../secretary/secretary_dashboard.php');
                break;
            case 'doctor':
                header('Location: ../doctor/doctor_dashboard.php');
                break;
            default:
                $error = 'Unknown role assigned. Contact system administrator.';
                session_destroy();
                break;
        }
        exit;
    } else {
        $error = 'Invalid username or password.';
    }
}

// ---------- FORGOT PASSWORD ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['forgot_password'])) {
    $email = trim($_POST['email'] ?? '');
    if (!$email) {
        $error = 'Email is required.';
        $showForgot = true;
    } else {
        $_SESSION['reset_email'] = $email;
        $otp = random_int(100000, 999999);
        $expiry = date("Y-m-d H:i:s", strtotime("+10 minutes"));

        $stmt = $pdo->prepare("SELECT * FROM users WHERE email=?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            $stmt2 = $pdo->prepare("UPDATE users SET otp_code=?, otp_expiry=? WHERE user_id=?");
            $stmt2->execute([$otp, $expiry, $user['user_id']]);
        }

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = $_ENV['SMTP_USER'];
            $mail->Password = $_ENV['SMTP_PASS'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;
            $mail->setFrom($_ENV['SMTP_USER'], 'DentiTrack');
            $mail->addAddress($email);
            $mail->isHTML(true);
            $mail->Subject = 'DentiTrack Password Reset OTP';
            $mail->Body = "<h3>Hello!</h3><p>Your OTP code is: <b>$otp</b></p><p>This expires in 10 minutes.</p>";
            $mail->send();
        } catch (Exception $e) {}

        $success = 'If your email exists, an OTP has been sent.';
        $showVerify = true;
    }
}

// ---------- VERIFY OTP ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_otp'])) {
    if (!isset($_SESSION['reset_email'])) {
        $error = 'Session expired.';
    } else {
        $email = $_SESSION['reset_email'];
        $otp_input = trim($_POST['otp'] ?? '');

        $stmt = $pdo->prepare("SELECT * FROM users 
                               WHERE email=? 
                               AND otp_code=? 
                               AND otp_expiry >= NOW()");
        $stmt->execute([$email, $otp_input]);
        $user = $stmt->fetch();

        if ($user) {
            $_SESSION['otp_verified'] = true;
            $success = 'OTP verified! You can now reset your password.';
            $showReset = true;
        } else {
            $error = 'Invalid or expired OTP.';
            $showVerify = true;
        }
    }
}

// ---------- RESET PASSWORD ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    if (!isset($_SESSION['reset_email']) || !isset($_SESSION['otp_verified'])) {
        $error = 'OTP verification required.';
    } else {
        $email = $_SESSION['reset_email'];
        $new_password = trim($_POST['new_password'] ?? '');
        $confirm_password = trim($_POST['confirm_password'] ?? '');

        if (!$new_password || !$confirm_password) {
            $error = 'All fields are required.';
        } elseif ($new_password !== $confirm_password) {
            $error = 'Passwords do not match.';
        } elseif (!preg_match('/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[\W_]).{8,}$/', $new_password)) {
            $error = 'Password must be 8+ chars with upper, lower, number & symbol.';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email=?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                $hash = password_hash($new_password, PASSWORD_BCRYPT);
                $stmt2 = $pdo->prepare("UPDATE users SET password_hash=?, otp_code=NULL, otp_expiry=NULL WHERE user_id=?");
                $stmt2->execute([$hash, $user['user_id']]);
                unset($_SESSION['reset_email'], $_SESSION['otp_verified']);
                $success = 'Password reset successfully! Redirecting...';
                echo "<script>
                        setTimeout(function(){
                            window.location.href = window.location.pathname;
                        }, 2000);
                      </script>";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>DentiTrack Login</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
body {
  font-family: 'Segoe UI', sans-serif;
  background: linear-gradient(135deg, #eaf3ff, #d6e6ff);
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
  box-shadow: 0 8px 25px rgba(0,0,0,0.15);
  width: 400px;
}
h2 { text-align:center; color:#007bff; }
input {
  width:100%; padding:10px; margin:8px 0;
  border-radius:6px; border:1px solid #ccc;
  box-sizing:border-box;
}
button {
  width:100%; padding:12px; background:#007bff;
  color:#fff; border:none; border-radius:6px;
  font-weight:600; cursor:pointer;
}
button:hover { background:#0056b3; }
.message { padding:10px; margin:10px 0; border-radius:6px; font-weight:600; }
.success { background:#e6ffed; color:#006600; }
.error { background:#ffe6e6; color:#b30000; }
.toggle { cursor:pointer; color:#007bff; text-align:center; margin-top:10px; }
.hidden { display:none; }
.back-btn {
  display:block; margin-top:15px; text-align:center; color:#333; text-decoration:none;
}
.back-btn:hover { text-decoration:underline; color:#007bff; }
</style>
</head>
<body>
<div class="auth-wrapper">
<?php if($error): ?><div class="message error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if($success): ?><div class="message success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<!-- LOGIN -->
<div id="login-form" class="<?= ($showForgot||$showVerify||$showReset)?'hidden':'' ?>">
  <h2><i class="fas fa-user-lock"></i> Unified Login</h2>
  <form method="POST">
    <input type="text" name="username" placeholder="Username" required>
    <input type="password" name="password" placeholder="Password" required>
    <button type="submit" name="login">Login</button>
  </form>
  <div class="toggle" onclick="showForgot()">Forgot Password?</div>
  
</div>

<!-- FORGOT PASSWORD -->
<div id="forgot-form" class="<?= $showForgot?'':'hidden' ?>">
  <h2><i class="fas fa-unlock-alt"></i> Forgot Password</h2>
  <form method="POST">
    <input type="email" name="email" placeholder="Enter your email" required>
    <button type="submit" name="forgot_password">Send OTP</button>
  </form>
  <div class="toggle" onclick="showLogin()">Back to Login</div>
</div>

<!-- VERIFY OTP -->
<div id="verify-form" class="<?= $showVerify?'':'hidden' ?>">
  <h2><i class="fas fa-key"></i> Verify OTP</h2>
  <form method="POST">
    <input type="text" name="otp" placeholder="Enter OTP" required>
    <button type="submit" name="verify_otp">Verify OTP</button>
  </form>
  <div class="toggle" onclick="showLogin()">Back to Login</div>
</div>

<!-- RESET PASSWORD -->
<div id="reset-form" class="<?= $showReset?'':'hidden' ?>">
  <h2><i class="fas fa-lock"></i> Reset Password</h2>
  <form method="POST">
    <input type="password" name="new_password" placeholder="New Password" required>
    <input type="password" name="confirm_password" placeholder="Confirm Password" required>
    <button type="submit" name="reset_password">Reset Password</button>
  </form>
  <div class="toggle" onclick="showLogin()">Back to Login</div>
</div>
</div>

<script>
function showLogin(){
  document.getElementById('login-form').classList.remove('hidden');
  document.getElementById('forgot-form').classList.add('hidden');
  document.getElementById('verify-form').classList.add('hidden');
  document.getElementById('reset-form').classList.add('hidden');
}
function showForgot(){
  document.getElementById('login-form').classList.add('hidden');
  document.getElementById('forgot-form').classList.remove('hidden');
  document.getElementById('verify-form').classList.add('hidden');
  document.getElementById('reset-form').classList.add('hidden');
}
</script>
</body>
</html>
