<?php
// Start session for displaying messages on the login page
session_start();

// Configuration includes
// Assuming this file is in 'public/' and 'config/' is in the root:
require_once __DIR__ . '/../config/db_pdo.php';

// Check if a verification token is present in the URL
if (!isset($_GET['verify']) || empty($_GET['verify'])) {
    // If no token is provided, set an error message and redirect.
    $_SESSION['login_message'] = "Invalid verification link. Please register or contact support.";
    $_SESSION['login_message_type'] = 'error'; // Set type for consistent styling on login page
    header('Location: login.php');
    exit();
}

$verification_token = $_GET['verify'];
$message_type = 'error';
$message_text = 'Account verification failed. The token is invalid or has expired.';

try {
    // 1. Prepare and execute the query to find the user by the token
    // We only select users who are NOT yet verified, improving efficiency and logic flow.
    $stmt = $pdo->prepare("SELECT user_id, is_verified FROM users WHERE verification_token = ?");
    $stmt->execute([$verification_token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        if ($user['is_verified'] == 1) {
            // User is already verified
            $message_type = 'info';
            $message_text = 'This account is already verified. Please log in.';
        } else {
            // 2. Update the user record: set is_verified = 1 and clear the token
            $update_stmt = $pdo->prepare("
                UPDATE users 
                SET is_verified = 1, verification_token = NULL 
                WHERE user_id = ?
            ");
            
            if ($update_stmt->execute([$user['user_id']])) {
                // Success
                $message_type = 'success';
                $message_text = '🎉 Your account has been successfully verified! You can now log in.';
            } else {
                // Database error during update
                $message_text = 'Verification failed due to a server error. Please try again or contact support.';
            }
        }
    }
    // If $user is null, the token was not found (default $message_text is used)

} catch (PDOException $e) {
    // Log the error (only log the technical details)
    error_log("Database Error during verification: " . $e->getMessage());
    $message_text = 'A database error occurred. Please contact support.';
}

// 3. Store the result message and redirect to the login page
$_SESSION['login_message_type'] = $message_type;
$_SESSION['login_message'] = $message_text;

// Redirect the user to the login page (which should be in the 'public' directory)
header('Location: public/login.php');
exit();