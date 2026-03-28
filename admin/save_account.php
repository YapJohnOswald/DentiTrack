<?php
require_once '../config/db_pdo.php';
session_start();

// Restrict access to admin only
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: admin_login.php');
    exit();
}

try {
    // Validate inputs
    if (empty($_POST['username']) || empty($_POST['password']) || empty($_POST['confirm_password']) || empty($_POST['role'])) {
        throw new Exception("Please fill out all required fields.");
    }

    $username = trim($_POST['username']);
    $email = !empty($_POST['email']) ? trim($_POST['email']) : null;
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $role = $_POST['role'];
    
    // Removed $clinic_id = $_POST['clinic_id'] ?? null;

    if ($password !== $confirm_password) {
        throw new Exception("Passwords do not match.");
    }

    // Check for duplicate username
    $check = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $check->execute([$username]);
    if ($check->rowCount() > 0) {
        throw new Exception("Username already exists.");
    }

    // Hash password
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    // Removed all role-based clinic logic here

    // Insert user record - SIMPLIFIED QUERY (Removed clinic_id and clinic_name)
    $stmt = $pdo->prepare("INSERT INTO users 
        (username, email, password_hash, role, created_at, updated_at) 
        VALUES (?, ?, ?, ?, NOW(), NOW())");

    // SIMPLIFIED EXECUTE (Removed $clinic_id and $clinic_name)
    $stmt->execute([$username, $email, $password_hash, $role]);

    header('Location: manage_accounts.php?created=1');
    exit();

} catch (Exception $e) {
    $error = urlencode($e->getMessage());
    header("Location: manage_accounts.php?error={$error}");
    exit();
}
?>