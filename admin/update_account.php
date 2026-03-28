<?php
session_start();
require_once '../config/db_pdo.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: admin_login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: manage_accounts.php');
    exit();
}

$user_id = intval($_POST['user_id']);
$username = trim($_POST['username']);
$email = trim($_POST['email']);
$password = trim($_POST['password']);
$role = trim($_POST['role']);
$clinic_name = trim($_POST['clinic_name'] ?? '');

if (empty($username) || empty($email) || empty($role)) {
    $_SESSION['error_message'] = "⚠️ All fields except password are required.";
    header('Location: manage_accounts.php');
    exit();
}

try {
    if (!empty($password)) {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("
            UPDATE users 
            SET username = ?, email = ?, password_hash = ?, role = ?, clinic_name = ?
            WHERE user_id = ?
        ");
        $stmt->execute([$username, $email, $password_hash, $role, $clinic_name, $user_id]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE users 
            SET username = ?, email = ?, role = ?, clinic_name = ?
            WHERE user_id = ?
        ");
        $stmt->execute([$username, $email, $role, $clinic_name, $user_id]);
    }

    $_SESSION['success_message'] = "✅ Account updated successfully!";
    header("Location: manage_accounts.php");
    exit();

} catch (PDOException $e) {
    $_SESSION['error_message'] = "Database error: " . htmlspecialchars($e->getMessage());
    header("Location: manage_accounts.php");
    exit();
}
?>
