<?php
// Always start the session at the very beginning to use $_SESSION for messaging
session_start();

// --- PHPMailer Includes ---
// ASSUMPTION: This index.php is in the project root,
// and config/ and vendor/ are also in the root.
require_once 'config/db_pdo.php';
// FIX START: Manual includes based on login.php's method to ensure PHPMailer classes are loaded,
// resolving the "Class not found" error for registration and contact forms.
// IMPORTANT FIX: Changed 'phpmailer-master' to 'PHPMailer-master' (Uppercase 'P') for case-sensitive Linux host (InfinityFree).
require 'PHPMailer-master/src/PHPMailer.php';
require 'PHPMailer-master/src/SMTP.php';
require 'PHPMailer-master/src/Exception.php';
// Use the Composer autoloader exclusively for PHPMailer (kept for other packages)
require 'vendor/autoload.php';
// FIX END

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
// Removed: use Dotenv\Dotenv;

// =======================================================
// IMPORTANT CONFIGURATION: SMTP & Timezone
// !! REPLACE THESE PLACEHOLDERS WITH YOUR ACTUAL CREDENTIALS !!
// =======================================================
define('CONF_TIMEZONE', 'Asia/Manila');
define('CONF_SMTP_HOST', 'smtp.gmail.com');
define('CONF_SMTP_PORT', 587);
define('CONF_SMTP_USER', 'dentitrack2025@gmail.com');       // e.g., clinic.dentitrack@gmail.com
define('CONF_SMTP_PASS', 'gpmennmjrynhujzq');             // e.g., abcd1234efgh5678 (Gmail App Password)
// =======================================================

// Set timezone for consistency, essential for token expiry logic
date_default_timezone_set(CONF_TIMEZONE);
// Attempt to set database time zone if necessary
try {
    $pdo->exec("SET time_zone = '+08:00'");
} catch (Exception $e) {
    // Ignore if not supported or not necessary
}

// =======================================================
// NEW: PDO Helper Function to Retrieve Admin Settings
// =======================================================
// This is necessary because index.php uses PDO ($pdo) 
function getSettingPDO($pdo, $key, $default = '') {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value !== false ? $value : $default;
    } catch (Exception $e) {
        // Log error but continue with default value
        error_log("PDO Setting retrieval failed for key '{$key}': " . $e->getMessage());
        return $default;
    }
}


// --- State Variables for Modals/Messages ---
$primary_button_class = "btn-primary";
$show_registration_modal = false; // Controls the Registration Modal
$show_verification_modal = false; // Controls the Code Verification Modal
$message = ""; // Registration message or general info message (uses $message_type)
$message_type = 'info'; 
$login_message = ""; // Message for the verification modal or login errors (uses $login_message_type)
$login_message_type = 'info'; 
$temp_email = ''; // Stores the email to verify temporarily in the session

// Array to store previous form data for re-population (Registration)
$form_data = [
    'first_name' => '', 'middle_name' => '', 'last_name' => '',
    'email' => '', 'username' => '', 'password' => '',
    'contact_number' => '', 'dob' => '', 'gender' => 'Other', 'address' => '',
    'data_privacy_agreement' => false, // NEW: Track Data Privacy status
];

// --- PHP: Handle Session Messages from Redirects ---
// Check for messages before any POST logic that might generate new ones
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'] ?? 'info';
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
    $show_registration_modal = true; // Force modal to display message
}

if (isset($_SESSION['login_message'])) {
    $login_message = $_SESSION['login_message'];
    $login_message_type = $_SESSION['login_message_type'] ?? 'info';
    unset($_SESSION['login_message']);
    unset($_SESSION['login_message_type']);

    // If the message is about the verification code, show the verification modal
    if (strpos($login_message, 'verification code has been sent') !== false || strpos($_SERVER['REQUEST_URI'], '#verify') !== false) {
        $show_verification_modal = true;
        $show_registration_modal = false; // Hide registration if verification is needed
    } else {
        // If it's a success message from verification, show it in the registration modal area
        if ($login_message_type === 'success') {
            $message = $login_message;
            $message_type = $login_message_type;
            $show_registration_modal = true;
            $show_verification_modal = false;
        } else {
             $show_registration_modal = true;
        }
    }
}
// --- END Session Messages Handler ---


/* =========================================
    HANDLE ACCOUNT VERIFICATION CODE SUBMISSION
    ========================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_code'])) {

    $code = trim($_POST['verification_code'] ?? '');
    // Fetch email from the session (set during successful registration)
    $email_to_verify = $_SESSION['temp_email'] ?? '';

    if (empty($code) || empty($email_to_verify)) {
        $_SESSION['login_message'] = "Verification failed. Please ensure the code is entered and try again.";
        $_SESSION['login_message_type'] = 'error';
        header('Location: index.php#verify'); // Redirect to keep modal open
        exit();
    } else {
        try {
            // Check for the user using the email and the code, ensuring the code hasn't expired
            $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND verification_token = ? AND verification_expiry > NOW() AND is_verified = 0");
            $stmt->execute([$email_to_verify, $code]);
            $user_id = $stmt->fetchColumn();

            if ($user_id) {
                // Code is valid and not expired. Perform verification.
                $update_stmt = $pdo->prepare("
                    UPDATE users
                    SET is_verified = 1, verification_token = NULL, verification_expiry = NULL
                    WHERE user_id = ?
                ");
                $update_stmt->execute([$user_id]);

                // --- START: New Post-Verification Email Logic ---

                // 1. Get Patient Details for Email
                $patient_stmt = $pdo->prepare("SELECT fullname, email FROM patient WHERE user_id = ?");
                $patient_stmt->execute([$user_id]);
                $patient_data = $patient_stmt->fetch(PDO::FETCH_ASSOC);

                $patient_fullname = $patient_data['fullname'] ?? 'Valued Patient';
                $patient_email = $patient_data['email'] ?? $email_to_verify;
                // **CHANGE THIS URL to your actual login page or modal anchor**
                $login_url = 'http://localhost/DentiTrack_Main/public/login.php'; 

                // 2. Load Customizable Messages from Admin Settings
                $success_msg = getSettingPDO($pdo, 'email_verification_success_message');
                $payment_details = getSettingPDO($pdo, 'email_payment_details');
                
                // Use robust defaults if settings retrieval fails or they are empty
                $success_msg = $success_msg ?: "Your account has been successfully registered. You can now log in with your created account and proceed to our booking system to schedule your appointment.";
                $payment_details = $payment_details ?: "Please log in to view your appointment downpayment details and booking options.";


                // 3. Send Professional Confirmation/Payment Email
                $mail = new PHPMailer(true);
                try {
                    // Server settings (using existing constants)
                    $mail->isSMTP();
                    $mail->Host       = CONF_SMTP_HOST; 
                    $mail->SMTPAuth   = true;
                    $mail->Username   = CONF_SMTP_USER; 
                    $mail->Password   = CONF_SMTP_PASS; 
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = CONF_SMTP_PORT; 
                    $mail->isHTML(true);
                    $mail->CharSet    = 'UTF-8';
                    
                    // Recipients
                    $mail->setFrom(CONF_SMTP_USER, 'DentiTrack Appointment System'); 
                    $mail->addAddress($patient_email, $patient_fullname); 

                    // Email Content
                    $mail->Subject = '✅ Account Verified: Log In & Appointment Details';
                    
                    // Construct the email body using CUSTOMIZED, non-hardcoded settings
                    $email_body = '
                        <html>
                        <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
                            <div style="max-width: 600px; margin: 20px auto; padding: 25px; border: 1px solid #ddd; border-radius: 10px; background-color: #f9f9f9;">
                                <h2 style="color: #0077b6; border-bottom: 3px solid #0077b6; padding-bottom: 10px; margin-top: 0;">Welcome, Your Account is Active!</h2>
                                
                                <p>Dear ' . htmlspecialchars($patient_fullname) . ',</p>
                                
                                <p style="font-size: 1.1em; color: #28a745; font-weight: bold;">
                                    Successfully Registered: You can now log in with your created account!
                                </p>
                                
                                <p>' . nl2br(htmlspecialchars($success_msg)) . '</p>
                                
                                <p style="text-align: center; margin: 25px 0;">
                                    <a href="' . $login_url . '" 
                                       style="display: inline-block; padding: 12px 30px; color: #ffffff; background-color: #0077b6; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px; border: 1px solid #005f8f;">
                                        Log In to Book Appointment
                                    </a>
                                </p>

                                <hr style="margin: 30px 0; border: 0; border-top: 1px solid #eee;">

                                <h3 style="color: #dc3545; margin-bottom: 15px;">Appointment Downpayment Transaction Details</h3>
                                <p>Please note the following required downpayment information to secure your preferred appointment slot:</p>

                                <div style="background-color: #ffebe6; padding: 15px; border: 1px dashed #dc3545; border-radius: 5px; white-space: pre-wrap;">
                                    ' . nl2br(htmlspecialchars($payment_details)) . '
                                </div>

                                <p style="margin-top: 25px; font-size: 0.9em; text-align: center;">
                                    If you have any questions, please contact our support team.
                                </p>
                            </div>
                        </body>
                        </html>
                    ';

                    $mail->Body = $email_body;
                    $mail->AltBody = 'Account Verified! Success: ' . strip_tags($success_msg) . ' - Downpayment Details: ' . strip_tags($payment_details) . ' - Login: ' . $login_url;

                    $mail->send();
                    // Successfully sent post-verification email

                } catch (Exception $e) {
                    error_log("Post-verification email could not be sent. Mailer Error: {$mail->ErrorInfo}");
                }
                
                // --- END: New Post-Verification Email Logic ---

                $_SESSION['message'] = '🎉 Your account has been successfully verified! An email with log-in instructions and downpayment details has been sent to your inbox.';
                $_SESSION['message_type'] = 'success';

                unset($_SESSION['temp_email']); 
                $show_verification_modal = false; 
                
                // Redirect to clean up POST data and show success message in registration modal area
                header('Location: index.php#success');
                exit();

            } else {
                // Check if the user is already verified or if the code expired/incorrect
                $check_verified_stmt = $pdo->prepare("SELECT is_verified FROM users WHERE email = ?");
                $check_verified_stmt->execute([$email_to_verify]);
                $status = $check_verified_stmt->fetchColumn();

                if ($status === '1') {
                    $_SESSION['login_message'] = "This account is already verified. Please log in.";
                    $_SESSION['login_message_type'] = 'info';
                    header('Location: index.php#login'); // Redirect to clear POST and show message
                    exit();
                } else {
                    $_SESSION['login_message'] = "Verification failed. The code is incorrect or has expired. Please try again or re-register.";
                    $_SESSION['login_message_type'] = 'error';
                    header('Location: index.php#verify'); // Keep modal open if verification failed
                    exit();
                }
            }

        } catch (Exception $e) {
            error_log("Verification DB Error: " . $e->getMessage());
            $_SESSION['login_message'] = "A database error occurred during verification. Please contact support.";
            $_SESSION['login_message_type'] = 'error';
            header('Location: index.php#verify'); // Keep modal open
            exit();
        }
    }
}
// --- END VERIFICATION CODE SUBMISSION ---


/* =========================================
    HANDLE CONTACT FORM SUBMISSION (NEW BLOCK)
    ========================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_contact_message'])) {
    
    $contact_name = trim($_POST['contact_name'] ?? '');
    $contact_email = trim($_POST['contact_email'] ?? '');
    $contact_subject = trim($_POST['contact_subject'] ?? 'Contact Inquiry');
    $contact_message = trim($_POST['contact_message'] ?? '');

    $target_email = 'dentitrack2025@gmail.com'; // Fixed recipient email

    if (empty($contact_name) || empty($contact_email) || empty($contact_message)) {
        // Use session/redirect for error handling
        $_SESSION['contact_status'] = 'error';
        $_SESSION['contact_message_text'] = 'Please fill in all required fields.';
        header('Location: index.php#contact');
        exit();
    }
    
    // Send Contact Email using PHPMailer
    $mail = new PHPMailer(true);
    try {
        // Server settings (using constants)
        $mail->isSMTP();
        $mail->Host       = CONF_SMTP_HOST; // <-- Using Constant
        $mail->SMTPAuth   = true;
        $mail->Username   = CONF_SMTP_USER; // <-- Using Constant
        $mail->Password   = CONF_SMTP_PASS; // <-- Using Constant
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = CONF_SMTP_PORT; // <-- Using Constant
        $mail->isHTML(true);
        
        // Set the sender 
        $mail->setFrom(CONF_SMTP_USER, 'DentiTrack Website Contact'); // <-- Using Constant
        
        // Add the recipient (the target clinic email)
        $mail->addAddress($target_email, 'DentiTrack Admin');
        
        // Add the customer's email as Reply-To
        $mail->addReplyTo($contact_email, $contact_name);

        $mail->Subject = "WEBSITE INQUIRY: " . $contact_subject;
        $mail->Body     = "
            <p style='font-family: sans-serif;'>You have received a new message from your website contact form:</p>
            <hr>
            <p style='font-family: sans-serif;'><strong>Name:</strong> " . htmlspecialchars($contact_name) . "</p>
            <p style='font-family: sans-serif;'><strong>Email:</strong> " . htmlspecialchars($contact_email) . "</p>
            <p style='font-family: sans-serif;'><strong>Subject:</strong> " . htmlspecialchars($contact_subject) . "</p>
            <hr>
            <p style='font-family: sans-serif;'><strong>Message:</strong></p>
            <p style='font-family: sans-serif; white-space: pre-wrap; background: #f0f0f0; padding: 15px; border-radius: 8px;'>" . htmlspecialchars($contact_message) . "</p>
        ";
        $mail->AltBody = "New Website Inquiry from {$contact_name}. Email: {$contact_email}. Subject: {$contact_subject}. Message: {$contact_message}";

        $mail->send();
        
        // Success message
        $_SESSION['contact_status'] = 'success';
        $_SESSION['contact_message_text'] = 'Thank you! Your message has been sent successfully.';

    } catch (Exception $e) {
        error_log("Contact Email Error: {$mail->ErrorInfo}");
        
        // Failure message
        $_SESSION['contact_status'] = 'error';
        $_SESSION['contact_message_text'] = 'Sorry, we could not send your message. Please try calling us instead.';
    }

    // Redirect to prevent form resubmission and clean up POST data
    header('Location: index.php#contact');
    exit();
}
// --- END CONTACT FORM SUBMISSION ---


/* =========================================
    HANDLE REGISTRATION FORM SUBMISSION
    ========================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {

    // Store current submission data
    $form_data = [
        'first_name' => trim($_POST['first_name'] ?? ''),
        'middle_name' => trim($_POST['middle_name'] ?? ''),
        'last_name' => trim($_POST['last_name'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'username' => trim($_POST['username'] ?? ''),
        'password' => $_POST['password'] ?? '',
        'contact_number' => trim($_POST['contact_number'] ?? ''),
        'dob' => $_POST['dob'] ?? '',
        'gender' => $_POST['gender'] ?? 'Other',
        'address' => trim($_POST['address'] ?? ''),
        'data_privacy_agreement' => isset($_POST['data_privacy_agreement']), // NEW: Checkbox status
    ];

    $first_name = $form_data['first_name'];
    $middle_name = $form_data['middle_name'];
    $last_name = $form_data['last_name'];
    $email = $form_data['email'];
    $username = $form_data['username'];
    $password = $form_data['password'];
    $contact_number = $form_data['contact_number'];
    $dob = $form_data['dob'];
    $gender = $form_data['gender'];
    $address = $form_data['address'];
    $fullname = trim($first_name . ' ' . $middle_name . ' ' . $last_name);
    $data_privacy_agreement = $form_data['data_privacy_agreement']; // NEW

    // --- Start Validation ---
    if (empty($first_name) || empty($last_name) || empty($email) || empty($contact_number) || empty($username) || empty($password)) {
        $message = "Please fill in all required fields, including Username and Password.";
        $message_type = 'error';
        $show_registration_modal = true;
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";
        $message_type = 'error';
        $show_registration_modal = true;
    } 
    // NEW: Strong Password Validation - REVISED MESSAGE HERE
    elseif (strlen($password) < 8 || 
            !preg_match('/[A-Z]/', $password) || 
            !preg_match('/[a-z]/', $password) || 
            !preg_match('/[0-9]/', $password)) {
        
        $message = "Password strength requirement failed. Your password must be at least 8 characters long and include a combination of special characters,uppercase letters, lowercase letters, and numbers.";
        $message_type = 'error';
        $show_registration_modal = true;
    } 
    // NEW: Data Privacy Agreement Validation
    elseif (!$data_privacy_agreement) {
        $message = "You must agree to the **Data Privacy Agreement** to register an account.";
        $message_type = 'error';
        $show_registration_modal = true;
    }
    // --- End Validation ---
    
    else {
        try {
            // Check for existing username or email before starting transaction
            $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? OR username = ?");
            $check_stmt->execute([$email, $username]);
            if ($check_stmt->fetchColumn() > 0) {
                $message = "Error: The email or username you entered is already in use.";
                $message_type = 'error';
                $show_registration_modal = true;
                goto end_of_registration;
            }

            // Start transaction for atomicity
            $pdo->beginTransaction();

            // Prepare credentials and token
            $password_hash = password_hash($password, PASSWORD_BCRYPT); // Using BCRYPT is better
            

            // --- Generate 6-digit code with 15-minute expiry ---
            $verification_code = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $expiry_time = date('Y-m-d H:i:s', time() + (15 * 60)); // 15 minutes from now

            // 1. Insert into users table
            $stmt_user = $pdo->prepare("INSERT INTO users (email, username, password_hash, role, is_verified, verification_token, verification_expiry) VALUES (?, ?, ?, 'patient', 0, ?, ?)");
            $stmt_user->execute([$email, $username, $password_hash, $verification_code, $expiry_time]);

            // [FIX 1 - USER_ID RETRIEVAL]: Retrieve the last inserted ID (the user_id)
            $new_user_id = $pdo->lastInsertId();

            // 2. Insert into PATIENT table
            // This ensures the foreign key relationship is established.
            $stmt_patient = $pdo->prepare("INSERT INTO patient (user_id, first_name, middle_name, last_name, fullname, email, contact_number, dob, gender, address) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt_patient->execute([
                $new_user_id, // Correctly inserts the foreign key user_id
                $first_name,
                $middle_name,
                $last_name,
                $fullname,
                $email,
                $contact_number,
                $dob,
                $gender,
                $address
            ]);

            // [FIX 2 - PATIENT_ID RETRIEVAL]: Retrieve the patient_id (PK of the patient table)
            // Note: This variable isn't used here, but is kept for completeness/future use.
            $new_patient_id = $pdo->lastInsertId(); // Gets the primary key of the PATIENT record

            // Commit the transaction
            $pdo->commit();

            // 3. Send Verification Email using PHPMailer
            $mail = new PHPMailer(true);
            try {
                // Server settings (using constants)
                $mail->isSMTP();
                $mail->Host       = CONF_SMTP_HOST; // <-- Using Constant
                $mail->SMTPAuth   = true;
                $mail->Username   = CONF_SMTP_USER; // <-- Using Constant
                $mail->Password   = CONF_SMTP_PASS; // <-- Using Constant
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = CONF_SMTP_PORT; // <-- Using Constant
                $mail->isHTML(true);
                $mail->setFrom(CONF_SMTP_USER, 'DentiTrack Clinic'); // <-- Using Constant
                $mail->addAddress($email, $fullname);

                // --- Email Content sends CODE, not a link ---
                $mail->Subject = 'Your DentiTrack Account Verification Code';
                $mail->Body     = "
                    <p style='font-family: sans-serif;'>Dear {$fullname},</p>
                    <p style='font-family: sans-serif;'>Thank you for registering with DentiTrack! Please use the following **6-digit code** to verify your email address and activate your account:</p>
                    <p style='font-size: 2rem; font-weight: bold; color: #0077b6; text-align: center; background: #e6f7ff; padding: 15px; border-radius: 8px; font-family: monospace;'>{$verification_code}</p>
                    <p style='font-family: sans-serif;'>This code will expire in 15 minutes. Please enter it into the verification form on our website to continue.</p>
                    <p style='font-family: sans-serif; font-size: 0.8em; margin-top: 20px;'>DentiTrack Team</p>
                ";
                $mail->AltBody = "Your verification code is: {$verification_code}. This code expires in 15 minutes. Please return to the website to enter the code.";


                $mail->send();
                $_SESSION['temp_email'] = $email; // Store email for verification check
                $_SESSION['login_message_type'] = 'success';
                $_SESSION['login_message'] = "Registration successful! A verification code has been sent to **" . htmlspecialchars($email) . "**. Please enter the code below to activate your account.";


            } catch (Exception $e) {
                // Log the detailed error for support to view later
                error_log("Verification email could not be sent. Mailer Error: {$mail->ErrorInfo}");
                // Provide a user-friendly message
                $_SESSION['login_message_type'] = 'error';
                $_SESSION['login_message'] = "Registration successful! However, we could not send the verification email. Please contact support to activate your account or try logging in to trigger a resend.";
            }

            // --- Post/Redirect/Get (PRG) Pattern to show verification modal ---
            // Clear form data after successful submission before redirect
            $form_data = [];
            header('Location: index.php#verify');
            exit();

        } catch(Exception $e) {
            // Rollback transaction on error
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error_message = $e->getMessage();

            // Default error message
            $message = "Error: Could not complete registration. Please try again.";

            // Specific error handling for known duplicate issues (from database unique constraints)
            if (strpos($error_message, 'Duplicate entry') !== false) {
                if (strpos($error_message, 'email') !== false || strpos($error_message, 'username') !== false) {
                    $message = "Error: The email or username you entered is already registered.";
                } elseif (strpos($error_message, 'contact_number') !== false) {
                    $message = "Error: The contact number you entered is already registered.";
                }
            }
            $message_type = 'error';

            // Log the technical error for the developer
            error_log("Registration DB Error: " . $error_message);

            $show_registration_modal = true;
        }
    }
}
// Label for goto statement
end_of_registration:

// --- PHP: Re-initialization for fresh load ---
if (!$show_registration_modal && !$show_verification_modal) {
    // Re-initialize $form_data to empty strings if it wasn't a post request
    // and no modal is set to display
    $form_data = [
        'first_name' => '', 'middle_name' => '', 'last_name' => '',
        'email' => '', 'username' => '', 'password' => '',
        'contact_number' => '', 'dob' => '', 'gender' => 'Other', 'address' => '',
        'data_privacy_agreement' => false,
    ];
}


// --- PHP: Fetch Services for Landing Page (UPDATED QUERY) ---
$services_list = [];
try {
    // UPDATED: Fetch category, price, and duration
    $stmt = $pdo->query("SELECT service_id, service_name, category, description, price, duration, image_path FROM services WHERE status = 'active' ORDER BY category, service_name ASC");
    $services_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {
    error_log("Database error fetching services: " . $e->getMessage());
}

$icon_map = [
    'Routine Check-ups' => 'fa fa-user-check', 'Dental Check-ups' => 'fa fa-user-check', 'Cosmetic Dentistry' => 'fa fa-palette',
    'Cosmetic Whitening' => 'fa fa-lightbulb', 'Orthodontics' => 'fa fa-link', 'Orthodontic Braces' => 'fa fa-link',
    'Teeth Whitening' => 'fa fa-lightbulb', 'Root Canal' => 'fa fa-x-ray', 'Fillings' => 'fa fa-fill-drip',
    'Extraction' => 'fa fa-mask', 'Surgery' => 'fa fa-briefcase-medical', 'Cleaning' => 'fa fa-soap',
    'Braces' => 'fa fa-tooth', 'Denture' => 'fa fa-hand-holding-heart',
];

$fallback_services = [
    // Fallback services now include placeholder category, price, and duration
    ['service_name' => 'Routine Check-ups', 'category' => 'Preventive', 'description' => 'Comprehensive examinations and professional teeth cleaning for health.', 'price' => '1,500.00', 'duration' => '45 mins', 'image_path' => 'images/checkup.jpg'],
    ['service_name' => 'Cosmetic Whitening', 'category' => 'Cosmetic', 'description' => 'Achieve a brighter, whiter smile with our advanced treatment options.', 'price' => '15,000.00', 'duration' => '90 mins', 'image_path' => 'images/whitening.jpg'],
    ['service_name' => 'Orthodontic Braces', 'category' => 'Orthodontics', 'description' => 'Straighten your teeth for a perfect alignment and confident smile.', 'price' => '50,000.00', 'duration' => 'N/A', 'image_path' => 'images/braces.jpg'],
];

if (empty($services_list)) {
    $services_list = $fallback_services;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>DentiTrack - Dental Clinic Management</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* --- Global Styles --- */
* { box-sizing: border-box; scroll-behavior: smooth; }
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    margin:0; padding:0;
    background:
        linear-gradient(180deg, rgba(255,255,255,0.95), rgba(245,245,245,0.95)),
        url('images/abstract_dental_bg.jpg') no-repeat center center/cover;
    background-attachment: fixed;
    color:#333; line-height:1.6;
}
a { text-decoration:none; color:inherit; }

/* --- Header & Navigation --- */
header {
    background:#fff; padding:15px 40px; color:#0077b6; position:sticky; top:0;
    z-index:10; display:flex; justify-content:space-between; align-items:center;
    box-shadow:0 2px 10px rgba(0,0,0,0.08);
}
header .logo { font-size:2rem; font-weight:700; color:#000; display: flex; align-items: center; }

nav ul { list-style:none; display:flex; gap:25px; margin:0; padding:0; align-items:center; }
nav ul li a { color:#333; font-weight:500; padding:10px 12px; transition: all 0.3s ease; border-radius:8px; display:flex; align-items:center; }
nav ul li a:hover { background:#e6f7ff; color:#0077b6; }

/* --- Buttons --- */
.btn-primary {
    display: inline-block; padding: 12px 25px; background: #0077b6; color: white;
    border-radius: 8px; font-weight: 600; transition: background 0.3s ease, transform 0.1s ease;
    border: none; cursor: pointer; text-align: center;
}
.btn-primary:hover {
    background: #005f8f; transform: translateY(-1px); box-shadow: 0 4px 10px rgba(0, 119, 182, 0.3);
}
.btn-primary:active { background: #004b73; transform: translateY(0); }

/* --- Hero Section --- */
#home {
    background: linear-gradient(rgba(0, 119, 182, 0.6), rgba(0, 180, 216, 0.6)), url('images/dental.jpg') no-repeat center center/cover;
    background-size: cover; min-height: 550px; display: flex; flex-direction: column;
    justify-content: center; align-items: center; text-align: center; color: #fff;
    padding: 0 20px; text-shadow: 0 2px 5px rgba(0,0,0,0.5);
}
#home h1 { font-size: 4rem; font-weight: 900; margin: 0 0 15px; line-height: 1.1; }
#home p { font-size: 1.5rem; font-weight: 300; margin-bottom: 30px; }

/* --- Section Styling --- */
.section {
    padding: 60px 20px; max-width: 1200px; margin: 40px auto; background: #fff;
    border-radius: 12px; box-shadow: 0 6px 20px rgba(0,0,0,0.1);
}
.section-title {
    text-align: center; font-size: 2.8rem; color: #000; margin-bottom: 40px;
    font-weight: 700; border-bottom: 3px solid #e6f7ff; display: inline-block;
    padding-bottom: 10px;
}

/* --- Services Cards --- */
.cards { display: flex; flex-wrap: wrap; gap: 30px; justify-content: center; }
.card {
    background: #fff; padding: 0; overflow: hidden; border-radius: 10px;
    width: 320px; text-align: center; box-shadow: 0 5px 20px rgba(0,0,0,0.15);
    transition: transform 0.3s ease, box-shadow 0.3s ease; display: flex;
    flex-direction: column; cursor: default;
}
.card:hover { transform: translateY(-5px); box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
.card-image-container { width: 100%; height: 200px; overflow: hidden; background-color: #f0f0f0; position: relative; }
/* IMAGE LOGIC IS PRESERVED HERE: loads image from database path */
.card-image-container img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.5s ease; }
.card:hover .card-image-container img { transform: scale(1.05); }
.card-icon { font-size: 3.5rem; color: #0077b6; display: flex; justify-content: center; align-items: center; height: 100%; background: #e6f7ff; opacity: 0.8; }

/* --- Services Cards Details Enhancement (NEW STYLES) --- */
.card-content { 
    padding: 25px; flex-grow: 1; display: flex; flex-direction: column; justify-content: flex-start; text-align: left; 
}
.card-content h3 { 
    font-size: 1.6rem; margin-top: 0; margin-bottom: 12px; color: #0077b6; text-align: center; 
}

.service-details {
    margin-bottom: 15px;
    padding: 10px;
    border: 1px solid #e6f7ff; /* Light blue border */
    border-radius: 6px;
    background-color: #f7fcff; /* Very light blue background */
    font-size: 0.95rem;
}
.service-details p {
    margin: 5px 0;
    color: #555;
    display: flex;
    align-items: center;
    line-height: 1.4;
}
.service-details p i {
    margin-right: 8px;
    color: #0077b6; /* Accent color for icons */
    width: 15px; /* Fixed width for icons */
    text-align: center;
}
.service-details p strong {
    font-weight: 600;
    margin-right: 5px;
    color: #333;
}
.price-detail {
    font-weight: 700;
    color: #28a745 !important; /* Green for price */
    margin: 8px 0 !important;
}
.price-detail span {
    font-size: 1.1em;
    font-weight: 800;
}
.description-text {
    font-size: 1.05rem; 
    color: #555; 
    margin-bottom: 0; 
    flex-grow: 1; 
    border-top: 1px dashed #eee;
    padding-top: 10px;
}
/* --- End NEW Styles --- */


/* --- About Section --- */
.about-content { display: flex; gap: 40px; align-items: center; }
.about-image { width: 50%; border-radius: 10px; object-fit: cover; max-height: 400px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
.about-text { width: 50%; font-size: 1.1rem; }

/* --- Modal Styles (Registration Form & Verification Form) --- */
.modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.6); justify-content: center; align-items: flex-start; padding: 40px 20px; overflow-y: auto; }
.modal-content { background: #fff; padding: 40px; border-radius: 15px; width: 100%; max-width: 650px; position: relative; max-height: 90vh; overflow-y: auto; box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
.close { position: absolute; top: 15px; right: 20px; font-size: 2rem; cursor: pointer; color: #666; transition: color 0.2s; }
.close:hover { color: #333; }
.modal-content h2 { font-weight: 700; margin-bottom: 15px; text-align: center; color: #0077b6; font-size: 2rem; }
.form-row { display: flex; gap: 20px; margin-bottom: 10px; }
.form-group { display: flex; flex-direction: column; margin-bottom: 15px; flex: 1; min-width: 40%; }
.form-group label { font-weight: 600; margin-bottom: 5px; color: #0077b6; font-size: 0.95rem; }
.form-group input, .form-group select, .form-group textarea {
    padding: 12px 15px; border-radius: 8px; border: 1px solid #ccc;
    font-size: 1rem; width: 100%; transition: border 0.3s ease, box-shadow 0.3s ease;
}
.form-group input:focus, .form-group select:focus, .form-group textarea:focus {
    border-color: #00b4d8; box-shadow: 0 0 8px rgba(0,180,216,0.5); outline: none;
}
/* New style for the Data Privacy checkbox group */
.checkbox-group { display: flex; align-items: center; margin-bottom: 20px; padding: 10px; background-color: #f0f8ff; border-radius: 8px; }
.checkbox-group input[type="checkbox"] { margin-right: 10px; width: auto; height: 18px; }
.checkbox-group label { margin: 0; font-weight: 500; color: #333; font-size: 1rem; }

.message { text-align: center; margin-bottom: 20px; padding: 10px; border-radius: 6px; font-weight: 600; }
.success-message { color: #155724; background-color: #d4edda; border: 1px solid #c3e6cb; }
.error-message { color: #721c24; background-color: #f8d7da; border: 1px solid #f5c6cb; }
.info-message { color: #004085; background-color: #cce5ff; border: 1px solid #b8daff; }
.modal-content button { margin-top: 20px; width: 100%; font-size: 1.1rem; padding: 15px 25px; }

/* --- Footer --- */
footer { background: #f9f9f9; color: #555; text-align: center; padding: 30px 20px; margin-top: 60px; font-weight: 300; border-top: 1px solid #eee; }
footer a { color: #0077b6; font-weight: 500; }
footer a:hover { text-decoration: underline; }

/* --- Fixed Register Button --- */
.register-btn { position: fixed; bottom: 30px; right: 30px; z-index: 100; box-shadow: 0 6px 15px rgba(0, 180, 216, 0.5); border-radius: 8px; }
.register-btn a { display: block; padding: 15px 30px; background: #00b4d8; color: white; border-radius: 8px; font-weight: 700; transition: background 0.3s ease, transform 0.1s ease; text-align: center; font-size: 1.1rem; }
.register-btn a:hover { background: #0096c7; transform: translateY(-2px); }

/* --- Responsive Adjustments --- */
@media (max-width: 900px) {
    header { flex-direction: column; gap: 15px; }
    nav ul { justify-content: flex-end; }
    #home h1 { font-size: 3rem; }
    .section-title { font-size: 2.5rem; }
    .about-content { flex-direction: column; }
    .about-image, .about-text { width: 100%; }
    .card { width: 45%; }
    .form-row { flex-direction: column; gap: 0; }
}
@media (max-width: 600px) {
    header { flex-direction: column; gap: 15px; }
    nav ul { justify-content: center; flex-wrap: wrap; }
    #home h1 { font-size: 2.5rem; }
    .card { width: 100%; }
    .register-btn { bottom: 20px; right: 20px; }
}
</style>
</head>
<body>

<header>
    <div class="logo">DentiTrack</div>
    <nav>
        <ul>
            <li><a href="#home">Home</a></li>
            <li><a href="#about">About</a></li>
            <li><a href="#services">Services</a></li>
            <li><a href="#contact" class="btn-primary" style="background: #00b4d8; color: white;">Contact Us</a></li>
            <li><a href="public/login.php" class="<?= $primary_button_class ?>">Login</a></li>
        </ul>
    </nav>
</header>

<main>
<section id="home">
    <h1>Your Journey to a Perfect Smile Starts Here</h1>
    <p>Providing state-of-the-art dental care with compassion and expertise.</p>
</section>

<section id="services" class="section">
    <h2 class="section-title">Our Services</h2>
    <div class="cards">
        <?php foreach ($services_list as $service):
            // --- UPDATED: Fetch new fields and format data ---
            $service_name = htmlspecialchars($service['service_name']);
            $service_category = htmlspecialchars($service['category'] ?? 'General Dentistry'); // NEW
            $service_description = htmlspecialchars($service['description'] ?? 'Detailed description coming soon.');
            // Format price with Philippine Peso symbol and two decimal places
            $service_price_raw = (float)($service['price'] ?? 0.00); 
            $service_price = number_format($service_price_raw, 2); // NEW
            $service_duration = htmlspecialchars($service['duration'] ?? 'N/A'); // NEW
            
            $image_path = htmlspecialchars($service['image_path'] ?? '');
            $icon_class = $icon_map[$service['service_name']] ?? 'fa fa-tooth';
        ?>
            <div class="card">
                <div class="card-image-container">
                    <?php if (!empty($image_path)): ?>
                        <img
                            src="<?= $image_path ?>"
                            alt="<?= $service_name ?> Image"
                            title="<?= $service_name ?>"
                            onerror="this.onerror=null; this.outerHTML='<div class=\'card-icon\'><i class=\'<?= $icon_class ?>\'></i></div>'">
                    <?php else: ?>
                        <div class="card-icon"><i class="<?= $icon_class ?>"></i></div>
                    <?php endif; ?>
                </div>
                
                <div class="card-content">
                    <h3><?= $service_name ?></h3>
                    
                    <div class="service-details">
                        <p><i class="fa fa-tag"></i> <strong>Category:</strong> <span><?= $service_category ?></span></p>
                        <p class="price-detail"><i class="fa fa-peso-sign"></i> <strong>Price Estimate:</strong> <span>₱<?= $service_price ?></span></p>
                        <p><i class="fa fa-clock"></i> <strong>Duration:</strong> <span><?= $service_duration ?></span></p>
                    </div>
                    <p class="description-text"><?= $service_description ?></p>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if (count($services_list) == count($fallback_services)): ?>
        <p style="text-align: center; width: 100%; font-style: italic; color: #777; margin-top: 40px;">
            Note: Database connection failed or no services were found. Displaying illustrative placeholders.
        </p>
    <?php endif; ?>

</section>

<section id="about" class="section">
    <h2 class="section-title">About Us</h2>
    <div class="about-content">
        <img src="images/denti.jpg" alt="About Us Image" class="about-image">
        <div class="about-text">
            <p>At DentiTrack, we are passionate about providing exceptional dental care tailored to your needs. Our experienced team is committed to enhancing your dental health with personalized, state-of-the-art treatments in a welcoming environment. We utilize the latest technology to ensure your comfort and the highest standard of care.</p>
            <p>From routine check-ups to complex cosmetic procedures, trust us to brighten your smile and improve your oral health.</p>
        </div>
    </div>
</section>

<section id="contact" class="section">
    <h2 class="section-title">Contact & Send Message</h2>
    
    <?php 
    // Display contact status message from session if it exists
    if (isset($_SESSION['contact_status'])): 
        $status_type = $_SESSION['contact_status'];
        $status_text = $_SESSION['contact_message_text'];
        $css_class = $status_type === 'success' ? 'success-message' : 'error-message';
        // Display and immediately clear the session variables
        echo "<div class='message {$css_class}' style='max-width: 550px; margin: 0 auto 20px;'>{$status_text}</div>";
        unset($_SESSION['contact_status']);
        unset($_SESSION['contact_message_text']);
    endif;
    ?>

    <p style="text-align: center; max-width: 600px; margin: 0 auto 30px;">
        Have a question or want to book an appointment? Fill out the form below or reach us directly at the contact details provided.

        <br><strong>09913637693</strong> | <strong>dentitrack2025@gmail.com</strong>
    </p>

    <form method="POST" action="index.php#contact" style="max-width: 550px; margin: 0 auto;">
        <input type="hidden" name="send_contact_message" value="1">
        <div class="form-group">
            <label for="contact_name"><i class="fa fa-user-alt"></i> Your Name</label>
            <input type="text" name="contact_name" id="contact_name" placeholder="Enter Your Name" required>
        </div>
        <div class="form-group">
            <label for="contact_email"><i class="fa fa-envelope"></i> Your Email</label>
            <input type="email" name="contact_email" id="contact_email" placeholder="Enter Your Email" required>
        </div>
        <div class="form-group">
            <label for="contact_subject"><i class="fa fa-info-circle"></i> Subject</label>
            <input type="text" name="contact_subject" id="contact_subject" placeholder="Subject of your message" required>
        </div>
        <div class="form-group">
            <label for="contact_message"><i class="fa fa-comment-alt"></i> Message</label>
            <textarea name="contact_message" id="contact_message" rows="5" placeholder="Type your message here..." required></textarea>
        </div>
        <button type="submit" class="btn-primary">Send Message</button>
    </form>
</section>
</main>

<footer>
    &copy; <?= date("Y") ?> DentiTrack Clinic. All rights reserved. | Developed for Educational Purposes.
</footer>

<div class="register-btn">
    <a href="#" onclick="document.getElementById('registrationModal').style.display='flex'; return false;">
        <i class="fa fa-clipboard-list"></i> Register Now
    </a>
</div>

<div id="registrationModal" class="modal" style="display: <?= $show_registration_modal && !$show_verification_modal ? 'flex' : 'none'; ?>;">
    <div class="modal-content">
        <span class="close" onclick="document.getElementById('registrationModal').style.display='none';">&times;</span>
        <h2>Patient Registration</h2>
        <p style="text-align: center; margin-bottom: 20px;">
            Please fill out the form accurately to create your patient record and user account.
            <br><span style="color:#0077b6; font-weight:600;">Account activation requires email verification code.</span>
        </p>

        <?php if (!empty($message)): ?>
            <div class="message <?= $message_type === 'success' ? 'success-message' : 'error-message'; ?>">
                <?= nl2br(htmlspecialchars($message)) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="index.php">

            <div class="form-row">
                <div class="form-group">
                    <label for="first_name">First Name <span style="color:red;">*</span></label>
                    <input type="text" id="first_name" name="first_name" value="<?= htmlspecialchars($form_data['first_name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="middle_name">Middle Name</label>
                    <input type="text" id="middle_name" name="middle_name" value="<?= htmlspecialchars($form_data['middle_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="last_name">Last Name <span style="color:red;">*</span></label>
                    <input type="text" id="last_name" name="last_name" value="<?= htmlspecialchars($form_data['last_name'] ?? '') ?>" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="email">Email Address <span style="color:red;">*</span></label>
                    <input type="email" id="email" name="email" value="<?= htmlspecialchars($form_data['email'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="contact_number">Contact Number <span style="color:red;">*</span></label>
                    <input type="tel" id="contact_number" name="contact_number" value="<?= htmlspecialchars($form_data['contact_number'] ?? '') ?>" required>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="dob">Date of Birth</label>
                    <input type="date" id="dob" name="dob" value="<?= htmlspecialchars($form_data['dob'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="gender">Gender</label>
                    <select id="gender" name="gender">
                        <option value="Male" <?= (isset($form_data['gender']) && $form_data['gender'] == 'Male') ? 'selected' : '' ?>>Male</option>
                        <option value="Female" <?= (isset($form_data['gender']) && $form_data['gender'] == 'Female') ? 'selected' : '' ?>>Female</option>
                        <option value="Other" <?= (isset($form_data['gender']) && $form_data['gender'] == 'Other') ? 'selected' : '' ?>>Prefer not to say</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="address">Full Address</label>
                <textarea id="address" name="address" rows="2"><?= htmlspecialchars($form_data['address'] ?? '') ?></textarea>
            </div>

            <hr style="margin: 20px 0; border: 0; border-top: 1px solid #eee;">
            
            <h3 style="text-align: center; color: #555; margin-bottom: 15px;">Account Credentials</h3>

            <div class="form-row">
                <div class="form-group" style="flex: 1;">
                    <label for="username">Username <span style="color:red;">*</span></label>
                    <input type="text" id="username" name="username" value="<?= htmlspecialchars($form_data['username'] ?? '') ?>" required>
                </div>
                <div class="form-group" style="flex: 1;">
                    <label for="password">Password <span style="color:red;">*</span></label>
                    <input type="password" id="password" name="password" required>
                    <small style="color: #6c757d; margin-top: 5px;">Min 8 characters, must include Upper/Lower case and Number.</small>
                </div>
            </div>
            
            <div class="checkbox-group">
                <input type="checkbox" id="data_privacy_agreement" name="data_privacy_agreement" value="1" <?= $form_data['data_privacy_agreement'] ? 'checked' : '' ?> required>
                <label for="data_privacy_agreement">
                    I have read and agree to the <a href="#" onclick="document.getElementById('dpaModal').style.display='flex'; return false;" style="color: #0077b6; font-weight: 700;">Data Privacy Agreement</a>. <span style="color:red;">*</span>
                </label>
            </div>


            <input type="hidden" name="register" value="1">
            <button type="submit" class="btn-primary">Register Account</button>
        </form>
    </div>
</div>

<div id="verificationModal" class="modal" style="display: <?= $show_verification_modal ? 'flex' : 'none'; ?>;">
    <div class="modal-content" style="max-width: 450px;">
        <span class="close" onclick="document.getElementById('verificationModal').style.display='none';">&times;</span>
        <h2><i class="fa fa-key"></i> Account Verification</h2>
        <p style="text-align: center; margin-bottom: 20px;">
            A **6-digit verification code** was sent to **<?= htmlspecialchars($_SESSION['temp_email'] ?? 'your email') ?>**. Please enter it below to activate your account.
        </p>

        <?php if (!empty($login_message)): ?>
            <div class="message <?= $login_message_type === 'success' ? 'success-message' : ($login_message_type === 'error' ? 'error-message' : 'info-message'); ?>">
                <?= nl2br(htmlspecialchars($login_message)) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="index.php">
            <div class="form-group">
                <label for="verification_code">Verification Code <span style="color:red;">*</span></label>
                <input type="text" id="verification_code" name="verification_code" placeholder="Enter 6-digit Code" pattern="\d{6}" maxlength="6" required style="text-align: center; letter-spacing: 5px;">
            </div>
            <input type="hidden" name="verify_code" value="1">
            <button type="submit" class="btn-primary">Verify Account</button>
        </form>
        
        <p style="text-align: center; margin-top: 15px; font-size: 0.9em; color: #777;">
            Code expires in 15 minutes.
        </p>
    </div>
</div>

<div id="dpaModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <span class="close" onclick="document.getElementById('dpaModal').style.display='none';">&times;</span>
        <h2 style="color: #0077b6;"><i class="fa fa-shield-alt"></i> Data Privacy Agreement</h2>
        <div style="font-size: 1rem; line-height: 1.7; color: #333;">
            <p>By proceeding with registration and checking the agreement box, you acknowledge and consent to the collection, processing, and storage of your personal and sensitive personal information (including dental history and health records) by DentiTrack in accordance with this agreement.</p>
            
            <h3 style="color: #0077b6; border-bottom: 1px solid #eee; padding-bottom: 5px; margin-top: 25px;">Purpose of Data Processing:</h3>
            <p>Your information is collected and used solely for the following legitimate purposes:</p>
            <ol style="margin-left: 15px; padding-left: 0; list-style-position: inside;">
                <li>Account Registration and Management for DentiTrack services.</li>
                <li>Appointment Scheduling, Confirmation, and necessary follow-up communications.</li>
                <li>Personalized Dental Treatment Planning and delivery of professional care.</li>
                <li>Compliance with legal and regulatory requirements.</li>
            </ol>
            
            <h3 style="color: #0077b6; border-bottom: 1px solid #eee; padding-bottom: 5px; margin-top: 25px;">Commitment to Privacy:</h3>
            <p>We are committed to securing your data in strict compliance with the **Data Privacy Act of 2012 (Republic Act No. 10173)**. Your data will be protected using appropriate organizational and technical security measures and will **never** be shared, sold, or disclosed to unauthorized third parties without your explicit consent, except as required by law.</p>
            
            <p style="text-align: center; margin-top: 20px; font-weight: bold; color: #000;">
                <i class="fa fa-check-circle" style="color: #28a745; margin-right: 5px;"></i> By checking the box, you confirm that you have read and understood this agreement.
            </p>
        </div>
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const regModal = document.getElementById('registrationModal');
        const verModal = document.getElementById('verificationModal');
        const dpaModal = document.getElementById('dpaModal'); // NEW: DPA Modal reference
        
        // This ensures the modals respect the PHP state on page load, 
        // especially after a redirect (PRG pattern).
        const showReg = <?= $show_registration_modal ? 'true' : 'false'; ?>;
        const showVer = <?= $show_verification_modal ? 'true' : 'false'; ?>;

        if (showVer) {
            verModal.style.display = 'flex';
        } else if (showReg) {
            regModal.style.display = 'flex';
        }

        // Add event listeners to close buttons using standard JS
        document.querySelectorAll('.modal .close').forEach(closeBtn => {
            closeBtn.onclick = function() {
                // Determine which modal the close button belongs to and hide it
                this.closest('.modal').style.display = 'none';
                
                // If closing verification modal, redirect to index to clear hash and messages
                if(this.closest('#verificationModal')) {
                     window.location.hash = '';
                }
            }
        });

        // Close when clicking outside the modal content
        window.onclick = function(event) {
            if (event.target == regModal) {
                regModal.style.display = "none";
            }
            if (event.target == verModal) {
                verModal.style.display = "none";
                window.location.hash = ''; // Clear hash on outside click for verification
            }
            if (event.target == dpaModal) { // NEW: DPA Modal outside click close
                dpaModal.style.display = "none";
            }
        }
    });
</script>

</body>
</html>