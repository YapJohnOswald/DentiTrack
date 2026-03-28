<?php
session_start();
header('Content-Type: application/json');
require_once '../config/db_pdo.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Invalid request method']);
    exit;
}

$email = $_SESSION['reset_admin_email'] ?? '';
$otp_verified = $_SESSION['otp_verified_admin'] ?? false;

if (!$email || !$otp_verified) {
    echo json_encode(['error' => 'Unauthorized or session expired']);
    exit;
}

$newPassword = trim($_POST['new_password'] ?? '');
$confirmPassword = trim($_POST['confirm_password'] ?? '');

if (empty($newPassword) || empty($confirmPassword)) {
    echo json_encode(['error' => 'All fields are required']);
    exit;
}

if ($newPassword !== $confirmPassword) {
    echo json_encode(['error' => 'Passwords do not match']);
    exit;
}

// Strong password validation: min 8 chars, 1 uppercase, 1 lowercase, 1 number
if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/', $newPassword)) {
    echo json_encode(['error' => 'Password must be at least 8 characters, include uppercase, lowercase, and a number']);
    exit;
}

// Hash and update
$hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
$stmt = $pdo->prepare("UPDATE users SET password_hash = ?, otp_code = NULL, otp_expiry = NULL, updated_at = NOW() WHERE username = ? AND role = 'admin'");
$stmt->execute([$hashedPassword, $email]);

// Clear session
unset($_SESSION['reset_admin_email'], $_SESSION['otp_verified_admin']);

echo json_encode(['success' => 'Password updated successfully. You can now login.']);
