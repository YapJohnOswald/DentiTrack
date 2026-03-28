<?php
session_start();
require_once '../config/db_pdo.php';

// Restrict access to admin only
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: admin_login.php');
    exit();
}

date_default_timezone_set('Asia/Manila');
$pdo->exec("SET time_zone = '+08:00'");

// This script now ONLY handles POST submission from the modal in manage_accounts.php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: manage_accounts.php?error=Invalid+access+method');
    exit();
}

// 1. Validate POST inputs
if (!isset($_POST['user_id']) || !is_numeric($_POST['user_id'])) {
    header('Location: manage_accounts.php?error=Invalid+User+ID+submitted');
    exit();
}

$user_id = intval($_POST['user_id']);
$username = trim($_POST['username']);
$email = trim($_POST['email']);
$role = $_POST['role'];

try {
    // 2. Perform the database update
    // Simplified UPDATE query: No clinic_id/clinic_name reference (Fixes SQL error)
    $stmt = $pdo->prepare("UPDATE users SET username=?, email=?, role=?, updated_at=NOW() WHERE user_id=?");
    $stmt->execute([$username, $email, $role, $user_id]);

    // 3. Redirect back to the main page with success message
    header('Location: manage_accounts.php?updated=1');
    exit();

} catch (PDOException $e) {
    $error = urlencode("Database Error: " . $e->getMessage());
    header("Location: manage_accounts.php?error={$error}");
    exit();
}
?>