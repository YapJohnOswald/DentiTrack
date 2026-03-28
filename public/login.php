<?php
session_start();
require_once '../config/db_pdo.php';
// FIX: Changed 'phpmailer-master' to 'PHPMailer-master' (Uppercase 'P') for case-sensitive Linux host.
require '../PHPMailer-master/src/PHPMailer.php';
require '../PHPMailer-master/src/SMTP.php';
require '../PHPMailer-master/src/Exception.php';
require '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// =======================================================
// IMPORTANT CONFIGURATION: SMTP Credentials 
// !! REPLACE THESE PLACEHOLDERS WITH YOUR ACTUAL CREDENTIALS !!
// =======================================================
define('CONF_SMTP_USER', 'dentitrack2025@gmail.com');      // e.g., clinic.dentitrack@gmail.com
define('CONF_SMTP_PASS', 'gpmennmjrynhujzq');             // e.g., abcd1234efgh5678 (Gmail App Password)
// =======================================================

date_default_timezone_set("Asia/Manila");
// Ensure database time zone is set, only if $pdo is successfully connected
try {
    if (isset($pdo)) {
        $pdo->exec("SET time_zone = '+08:00'");
    }
} catch (Exception $e) {
    // Ignore if not connected or command fails
}

$error = '';
$success = '';
$showForgot = false;
$showVerify = false;
$showReset = false;

/* ==========================
    LOGGING FUNCTION
    ========================== */
function log_activity($pdo, $user_id, $username, $action_type) {
    // Get IP address for logging
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'N/A';

    // Prepare statement to insert log entry
    $log_stmt = $pdo->prepare("INSERT INTO user_logs (user_id, username, action_type, timestamp, ip_address) VALUES (?, ?, ?, NOW(), ?)");
    
    // Check if user_id is null or a valid ID
    $user_id_to_log = is_numeric($user_id) ? $user_id : null;

    $log_stmt->execute([$user_id_to_log, $username, $action_type, $ip]);
}


/* ==========================
    AUTO LOGIN (Remember Me)
    ========================== */
if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token'])) {
    $cookie_token = $_COOKIE['remember_token'] ?? '';

    if ($cookie_token && strlen($cookie_token) > 64) {
        $selector = substr($cookie_token, 0, 32);
        $validator = substr($cookie_token, 32);

        $stmt = $pdo->prepare("SELECT * FROM users WHERE remember_selector = ? LIMIT 1");
        $stmt->execute([$selector]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($validator, $user['remember_token'])) {
            // ✅ Auto-login success
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            // 🚩 LOG ACTIVITY: Auto-Login
            log_activity($pdo, $user['user_id'], $user['username'], 'Auto-Login');

            // 🔄 Refresh token for security
            $new_selector = bin2hex(random_bytes(16));
            $new_validator = bin2hex(random_bytes(32));
            $hashed_validator = password_hash($new_validator, PASSWORD_DEFAULT);

            $pdo->prepare("UPDATE users SET remember_selector=?, remember_token=? WHERE user_id=?")
                ->execute([$new_selector, $hashed_validator, $user['user_id']]);

            setcookie('remember_token', $new_selector . $new_validator, time() + (86400 * 30), "/", "", false, true);

            // Redirect by role
            switch ($user['role']) {
                case 'admin': header('Location: ../admin/Dashboard/admin_dashboard.php'); exit;
                case 'secretary': header('Location: ../secretary/secretary_dashboard.php'); exit;
                case 'doctor': header('Location: ../doctor/doctor_dashboard.php'); exit;
                case 'patient': header('Location: ../patient/patient_dashboard.php'); exit;
            }
        } else {
            // Invalid token → clear cookie
            setcookie('remember_token', '', time() - 3600, '/');
            
            // 🚩 LOG ACTIVITY: Failed Auto-Login (Attempted Token)
            log_activity($pdo, null, 'Unknown/Invalid Token', 'Failed Auto-Login');
        }
    }
}

if (isset($_SESSION['otp_verified']) && $_SESSION['otp_verified'] === true) {
    $showReset = true;
}

/* ==========================
    LOGIN
    ========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username=? LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        // Successful Login
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];

        // 🚩 LOG ACTIVITY: Successful Login
        log_activity($pdo, $user['user_id'], $user['username'], 'Successful Login');

        if ($remember) {
            // ✅ Create secure selector + validator pair
            $selector = bin2hex(random_bytes(16));
            $validator = bin2hex(random_bytes(32));
            $hashed_validator = password_hash($validator, PASSWORD_DEFAULT);

            setcookie('remember_token', $selector . $validator, time() + (86400 * 30), "/", "", false, true);

            $pdo->prepare("UPDATE users SET remember_selector=?, remember_token=? WHERE user_id=?")
                ->execute([$selector, $hashed_validator, $user['user_id']]);
        } else {
            // Remove old remember token
            setcookie('remember_token', '', time() - 3600, '/');
            $pdo->prepare("UPDATE users SET remember_selector=NULL, remember_token=NULL WHERE user_id=?")
                ->execute([$user['user_id']]);
        }

        switch ($user['role']) {
            case 'patient': header('Location: ../patient/patient_dashboard.php'); break;
            case 'admin': header('Location: ../admin/Dashboard/admin_dashboard.php'); break;
            case 'secretary': header('Location: ../secretary/secretary_dashboard.php'); break;
            case 'doctor': header('Location: ../doctor/doctor_dashboard.php'); break;
            default:
                $error = 'Unknown role assigned.';
                session_destroy();
                break;
        }
        exit;
    } else {
        // Failed Login
        $error = 'Invalid username or password.';
        
        // 🚩 LOG ACTIVITY: Failed Login (Note: We only log the provided username, as the user_id is unknown/not found)
        log_activity($pdo, null, $username, 'Failed Login Attempt');
    }
    
}

/* ==========================
    FORGOT PASSWORD (Send OTP)
    ========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['forgot_password'])) {
    $email = trim($_POST['email'] ?? '');
    if (!$email) { 
        $error = 'Email is required.';
        $showForgot = true;
    } else {
        $_SESSION['reset_email'] = $email;
        $otp = random_int(100000, 999999);
        $expiry = date("Y-m-d H:i:s", strtotime("+10 minutes"));

        $stmt = $pdo->prepare("SELECT * FROM users WHERE email=? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            $pdo->prepare("UPDATE users SET otp_code=?, otp_expiry=? WHERE user_id=?")
                ->execute([$otp, $expiry, $user['user_id']]);
            // 🚩 LOG ACTIVITY: OTP Sent
            log_activity($pdo, $user['user_id'], $user['username'], 'OTP Sent');
        }

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = CONF_SMTP_USER; // <-- Using Constant
            $mail->Password = CONF_SMTP_PASS; // <-- Using Constant
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;
            $mail->setFrom(CONF_SMTP_USER, 'DentiTrack'); // <-- Using Constant
            $mail->addAddress($email);
            $mail->isHTML(true);
            $mail->Subject = 'DentiTrack Password Reset OTP';
            $mail->Body = "<h3>Your OTP code is: <b>$otp</b></h3><p>Expires in 10 minutes.</p>";
            $mail->send();
        } catch (Exception $e) {
            // silent fail
        }

        $success = 'If your email exists, an OTP has been sent.';
        $showVerify = true;
    }
}

/* ==========================
    VERIFY OTP
    ========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_otp'])) {
    $email = $_SESSION['reset_email'] ?? '';
    $otp_input = trim($_POST['otp'] ?? '');

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email=? AND otp_code=? AND otp_expiry >= NOW()");
    $stmt->execute([$email, $otp_input]);
    $user = $stmt->fetch();

    if ($user) {
        $_SESSION['otp_verified'] = true;
        $success = 'OTP verified! You can now reset your password.';
        $showReset = true;
          // 🚩 LOG ACTIVITY: OTP Verified
        log_activity($pdo, $user['user_id'], $user['username'], 'OTP Verified');
    } else {
        $error = 'Invalid or expired OTP.';
        $showVerify = true;
    }
}

/* ==========================
    RESET PASSWORD (Strong)
    ========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $email = $_SESSION['reset_email'] ?? '';
    $new_password = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);

    $strongPasswordPattern = '/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[\W_]).{8,}$/';

    if ($new_password !== $confirm_password) {
        $error = 'Passwords do not match.';
        $showReset = true;
    } elseif (!preg_match($strongPasswordPattern, $new_password)) {
        $error = 'Password must be at least 8 characters long and include uppercase, lowercase, number, and special character.';
        $showReset = true;
    } else {
        $hash = password_hash($new_password, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE users SET password_hash=?, otp_code=NULL, otp_expiry=NULL WHERE email=?")
            ->execute([$hash, $email]);
        
        // Fetch user data again to log the reset
        $stmt = $pdo->prepare("SELECT user_id, username FROM users WHERE email=?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // 🚩 LOG ACTIVITY: Password Reset
        if($user) {
            log_activity($pdo, $user['user_id'], $user['username'], 'Password Reset Successful');
        }

        unset($_SESSION['reset_email'], $_SESSION['otp_verified']);
        $success = 'Password reset successfully! Redirecting...';
        echo "<script>setTimeout(()=>window.location='login.php',1500)</script>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>DentiTrack Portal</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ... (Existing CSS) ... */
*{box-sizing:border-box;}
:root{
    --bg1:#0077b6; --bg2:#00b4d8;
    --glass-bg:rgba(255,255,255,0.12);
    --glass-border:rgba(255,255,255,0.18);
    --main-color:#0077b6; /* Added for loading spinner */
}
body{
    font-family:'Segoe UI',sans-serif;
    margin:0;
    background:
      linear-gradient(180deg,rgba(0,0,0,0.35),rgba(0,0,0,0.35)),
      url('../images/about.jpg') no-repeat center center/cover;
    color:#fff;
    min-height:100vh;
    display:flex;
    flex-direction:column;
}
header{
    background:linear-gradient(135deg,var(--bg1),var(--bg2));
    padding:18px 30px;
    color:white;
    display:flex;
    justify-content:space-between; /* Adjusted to accommodate the new button */
    align-items:center;
    box-shadow:0 6px 18px rgba(0,0,0,0.25);
}
header .logo{font-size:1.6rem;font-weight:700;}
/* --- START ADDED CSS FOR BACK BUTTON --- */
.back-link {
    color: white;
    text-decoration: none;
    font-size: 14px;
    padding: 8px 12px;
    border: 1px solid rgba(255, 255, 255, 0.4);
    border-radius: 8px;
    transition: background 0.2s;
    display: flex;
    align-items: center;
    gap: 6px;
}
.back-link:hover {
    background: rgba(255, 255, 255, 0.1);
}
/* --- END ADDED CSS FOR BACK BUTTON --- */
.hero{text-align:center;padding:48px 20px 20px;}
.hero-text-box{
    display:inline-block;
    background:linear-gradient(90deg,rgba(255,255,255,0.06),rgba(255,255,255,0.02));
    padding:18px 28px;border-radius:14px;
    backdrop-filter:blur(6px) saturate(140%);
    border:1px solid rgba(255,255,255,0.06);
}
.hero h1{margin:0;font-size:28px;}
.hero p{color:#e6f7ff;margin:8px 0 0;font-size:15px;}
#logins{flex:1;display:flex;align-items:center;justify-content:center;padding:40px 20px 80px;}
.login-card{
    width:100%;max-width:480px;
    background:var(--glass-bg);
    border-radius:16px;
    padding:28px;
    box-shadow:0 10px 40px rgba(0,0,0,0.45);
    border:1px solid var(--glass-border);
    backdrop-filter:blur(8px) saturate(130%);
    color:#fff;
}
.login-card h2{margin:0 0 14px;font-size:20px;display:flex;align-items:center;gap:10px;}
.form-row{margin-bottom:12px;}
input[type="text"],input[type="password"],input[type="email"],input[type="text"][name="otp"]{
    width:100%;padding:12px 14px;border-radius:10px;border:none;outline:none;font-size:15px;
    background:rgba(255,255,255,0.06);color:#fff;
}
input::placeholder{color:rgba(255,255,255,0.6);}
.actions{display:flex;align-items:center;justify-content:space-between;margin-top:8px;}
.remember{display:flex;align-items:center;gap:8px;color:#eaf6ff;font-size:14px;}
button.primary{
    display:inline-block;padding:11px 14px;
    background:linear-gradient(90deg,var(--bg1),var(--bg2));
    color:#fff;border:none;border-radius:10px;font-weight:700;
    cursor:pointer;box-shadow:0 6px 18px rgba(0,0,0,0.35);
    transition:transform .12s ease; width:100%;
}
button.primary:hover{transform:translateY(-2px);}
.message{padding:10px;margin-bottom:12px;border-radius:8px;font-weight:600;}
.error{background:#ffe6e6;color:#8b0000;}
.success{background:#e6ffed;color:#03510b;}
.link{color:#d9f6ff;cursor:pointer;text-decoration:underline;font-size:14px;}
footer{background:linear-gradient(135deg,var(--bg1),var(--bg2));color:white;text-align:center;padding:18px 10px;}
.hidden{display:none;}
/* --- START NEW LOADING OVERLAY CSS --- */
#loading-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.7); /* Dark semi-transparent background */
    display: none; /* Hidden by default */
    justify-content: center;
    align-items: center;
    z-index: 1000; /* Ensure it's on top of everything */
    flex-direction: column;
}
.loading-content {
    background: white;
    padding: 30px 40px;
    border-radius: 12px;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
    text-align: center;
    color: #333;
    font-size: 1.1rem;
    font-weight: 600;
}
.spinner {
    border: 4px solid #f3f3f3; /* Light grey */
    border-top: 4px solid var(--main-color); /* Blue */
    border-radius: 50%;
    width: 40px;
    height: 40px;
    animation: spin 1s linear infinite;
    margin-bottom: 15px;
}
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
/* --- END NEW LOADING OVERLAY CSS --- */
</style>
</head>
<body>
<header>
    <div class="logo">DentiTrack</div>
    <a href="../index.php" class="back-link">
        <i class="fas fa-arrow-left"></i> Back to Frontpage
    </a>
</header>
<div class="hero">
    <div class="hero-text-box">
        <h1>Welcome to DentiTrack</h1>
        <p>Secure access for Admins, Secretaries and Doctors</p>
    </div>
</div>

<section id="logins">
    <div class="login-card">
        <?php if($error): ?><div class="message error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if($success): ?><div class="message success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

        <div id="login-form" class="<?= ($showForgot||$showVerify||$showReset)?'hidden':'' ?>">
            <h2><i class="fas fa-user-lock"></i> Login</h2>
            <form method="POST" onsubmit="showLoading()"> 
                <div class="form-row"><input type="text" name="username" placeholder="Username" required></div>
                <div class="form-row"><input type="password" name="password" placeholder="Password" required></div>
                <div class="actions">
                    <label class="remember"><input type="checkbox" name="remember"> Remember Me</label>
                    <span class="link" onclick="showForgot()">Forgot Password?</span>
                </div>
                <div style="margin-top:12px;"><button class="primary" name="login"><i class="fas fa-sign-in-alt"></i> Login</button></div>
            </form>
        </div>

        <div id="forgot-form" class="<?= $showForgot?'':'hidden' ?>">
            <h2><i class="fas fa-unlock-alt"></i> Forgot Password</h2>
            <form method="POST" onsubmit="showLoading()">
                <div class="form-row"><input type="email" name="email" placeholder="Enter your email" required></div>
                <button class="primary" name="forgot_password"><i class="fas fa-paper-plane"></i> Send OTP</button>
            </form>
            <div style="text-align:right;margin-top:10px;"><span class="link" onclick="showLogin()">Back to Login</span></div>
        </div>

        <div id="verify-form" class="<?= $showVerify?'':'hidden' ?>">
            <h2><i class="fas fa-key"></i> Verify OTP</h2>
            <form method="POST" onsubmit="showLoading()">
                <div class="form-row"><input type="text" name="otp" placeholder="Enter OTP" required></div>
                <button class="primary" name="verify_otp"><i class="fas fa-check"></i> Verify</button>
            </form>
            <div style="text-align:right;margin-top:10px;"><span class="link" onclick="showLogin()">Back to Login</span></div>
        </div>

        <div id="reset-form" class="<?= $showReset?'':'hidden' ?>">
            <h2><i class="fas fa-lock"></i> Reset Password</h2>
            <form method="POST" onsubmit="return validateAndShowLoading(event)">
                <div class="form-row"><input type="password" name="new_password" placeholder="New Password" required></div>
                <div class="form-row"><input type="password" name="confirm_password" placeholder="Confirm Password" required></div>
                <button class="primary" name="reset_password"><i class="fas fa-save"></i> Reset Password</button>
            </form>
            <div style="text-align:right;margin-top:10px;"><span class="link" onclick="showLogin()">Back to Login</span></div>
        </div>
    </div>
</section>

<footer><p>&copy; <?= date("Y") ?> DentiTrack. All Rights Reserved.</p></footer>

<div id="loading-overlay">
    <div class="loading-content">
        <div class="spinner"></div>
        Processing....
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

// *** NEW: Loading functions ***
function showLoading() {
    document.getElementById('loading-overlay').style.display = 'flex';
}

function hideLoading() {
    document.getElementById('loading-overlay').style.display = 'none';
}

// Client-side strong password validation adapted to include loading
function validateAndShowLoading(e) {
    const pw = e.target.new_password.value;
    const pattern = /^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[\W_]).{8,}$/;
    
    if (!pattern.test(pw)) {
        alert("Password must be at least 8 characters long and include uppercase, lowercase, number, and special character.");
        return false; // Prevent form submission
    }
    
    // If validation passes, show loading and allow form submission
    showLoading();
    return true; 
}


// ✅ Client-side strong password validation - REMOVED the DOMContentLoaded event listener 
// and replaced it with validateAndShowLoading which is called via onsubmit.

// Hide loading on page load in case of PHP errors (e.g., if the user hits back/forward)
window.onload = hideLoading;
</script>
</body>
</html>