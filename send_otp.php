<?php
// send_otp.php
session_start();
header('Content-Type: application/json');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'vendor/autoload.php'; 

$action = $_POST['action'] ?? '';
$email  = $_POST['email'] ?? '';
$otp_in = $_POST['otp_code'] ?? '';

// --- ACTION 1: SEND CODE ---
if ($action === 'send') {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid Email Address']);
        exit;
    }

    $otp = rand(100000, 999999);
    
    // Store OTP and the CURRENT TIMESTAMP
    $_SESSION['temp_otp'] = $otp;
    $_SESSION['temp_email'] = $email;
    $_SESSION['otp_time'] = time(); // <--- This saves the time the code was created
    $_SESSION['is_verified'] = false;

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com'; 
        $mail->SMTPAuth   = true;
        $mail->Username   = 'rsucrs0@gmail.com';
        $mail->Password   = 'okhz zrgc twzl xmpp';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; 
        $mail->Port       = 587;

        $mail->setFrom('no-reply@yoursystem.com', 'Course Recommendation System');
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = 'Verify Your Account';
        $mail->Body    = "
            <div style='font-family: Arial, sans-serif; padding: 20px; background: #f4f4f4;'>
                <div style='max-width: 500px; margin: auto; background: white; padding: 20px; border-radius: 10px;'>
                    <h2 style='color: #56ab2f;'>Verification Code</h2>
                    <p>Please use the code below to complete your registration:</p>
                    <h1 style='letter-spacing: 5px; text-align: center; background: #eee; padding: 10px;'>$otp</h1>
                    <p>This code will expire in 15 minutes.</p>
                    <p style='color: #888; font-size: 12px;'>If you did not request this, please ignore this email.</p>
                </div>
            </div>
        ";
        $mail->AltBody = "Your verification code is: $otp";

        $mail->send();
        echo json_encode(['status' => 'success', 'message' => 'Code sent to your email!']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Could not send email. Error: ' . $mail->ErrorInfo]);
    }
    exit;
}

// --- ACTION 2: VERIFY CODE ---
if ($action === 'verify') {
    if (isset($_SESSION['temp_otp']) && $otp_in == $_SESSION['temp_otp']) {
        
        // CHECK EXPIRATION LOGIC (900 seconds = 15 minutes)
        if (time() - $_SESSION['otp_time'] > 900) {
            unset($_SESSION['temp_otp']); // Clear the expired code
            unset($_SESSION['otp_time']);
            echo json_encode(['status' => 'error', 'message' => 'Code expired. Please request a new one.']);
            exit;
        }

        $_SESSION['is_verified'] = true;
        echo json_encode(['status' => 'success', 'message' => 'Verified!']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Incorrect Code']);
    }
    exit;
}
?>