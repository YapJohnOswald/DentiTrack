<?php
session_start();
require_once '../config/db_pdo.php';

// Check if the user is logged in and has admin role
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: admin_login.php');
    exit();
}

// Check if a user ID is provided via GET request
if (isset($_GET['user_id'])) {
    $user_id = intval($_GET['user_id']); // Sanitize and ensure input is an integer

    try {
        // Use a prepared statement to safely delete the user
        $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);

        // Redirect with success message
        header('Location: manage_accounts.php?deleted=1');
        exit();
    } catch (PDOException $e) {
        // Redirect with error message on failure, properly URL-encoded
        header('Location: manage_accounts.php?error=' . urlencode($e->getMessage()));
        exit();
    }
} else {
    // Redirect if no user ID was provided
    header('Location: manage_accounts.php?error=No user selected');
    exit();
}
?>