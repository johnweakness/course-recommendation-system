<?php
// 1. Include PHPMailer Autoloader and use statements
require 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

include 'config.php';

// SMTP CONFIGURATION
if (!defined('SMTP_HOST')) define('SMTP_HOST', 'smtp.gmail.com');
if (!defined('SMTP_USER')) define('SMTP_USER', 'rsucrs0@gmail.com');
if (!defined('SMTP_PASS')) define('SMTP_PASS', 'okhz zrgc twzl xmpp');
if (!defined('SMTP_PORT')) define('SMTP_PORT', 587);

$error = "";

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        // Check if email exists
        $stmt = $mysqli->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows > 0) {
            // Generate a secure 6-digit token and UTC expiry time
            $token = random_int(100000, 999999);
            $expiry = gmdate("Y-m-d H:i:s", strtotime("+15 minutes")); // UTC-based expiry

            // Store token and expiry in database
            $upd = $mysqli->prepare("UPDATE users SET reset_token = ?, reset_expiry = ? WHERE email = ?");
            $upd->bind_param("iss", $token, $expiry, $email);
            $upd->execute();
            $upd->close();

            // Prepare the email
            $subject = "Your Password Reset Code - " . SITE_NAME;

            try {
                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host       = SMTP_HOST;
                $mail->SMTPAuth   = true;
                $mail->Username   = SMTP_USER;
                $mail->Password   = SMTP_PASS;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = SMTP_PORT;

        $mail->setFrom('no-reply@yoursystem.com', 'Course Recommendation System');
        $mail->addAddress($email);
                
                // --- UPDATED EMAIL DESIGN START ---
                $mail->isHTML(true); // Changed to TRUE
                $mail->Subject = $subject;
                
                // Using the design from send_otp.php
                $mail->Body = "
                    <div style='font-family: Arial, sans-serif; padding: 20px; background: #f4f4f4;'>
                        <div style='max-width: 500px; margin: auto; background: white; padding: 20px; border-radius: 10px;'>
                            <h2 style='color: #56ab2f;'>Password Reset</h2>
                            <p>Hi there,</p>
                            <p>You requested a password reset. Please use the code below:</p>
                            <h1 style='letter-spacing: 5px; text-align: center; background: #eee; padding: 10px;'>$token</h1>
                            <p>This code will expire in 15 minutes.</p>
                            <p style='color: #888; font-size: 12px;'>If you did not request this, please ignore this email.</p>
                        </div>
                    </div>
                ";
                
                $mail->AltBody = "Your password reset code is: $token";
                // --- UPDATED EMAIL DESIGN END ---

                $mail->send();

                // Store email in session for reset.php
                $_SESSION['reset_email'] = $email;

                header("Location: reset.php");
                exit();
            } catch (Exception $e) {
                error_log("PHPMailer Error: " . $e->getMessage());
                $error = "Failed to send the reset code. Please try again later.";
            }
        } else {
            $error = "No account found with that email address.";
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - <?= htmlspecialchars(SITE_NAME) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: linear-gradient(135deg, #56ab2f 0%, #a8e6cf 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', sans-serif;
        }

        .split-container {
            display: flex;
            width: 100%;
            max-width: 1100px;
            background: #fff;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 15px 35px rgba(0,0,0,.2);
            margin: 20px;
        }

        .left-side {
            flex: 1;
            background: #f8f9fa;
            padding: 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            border-right: 1px solid #eee;
        }

        .left-side .logo img {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            margin-bottom: 20px;
            border: none;
        }

        .left-side h1 {
            font-size: 2.2rem;
            color: #2c3e50;
            margin: 0 0 10px 0;
            line-height: 1.3;
        }

        .left-side p {
            color: #7f8c8d;
            font-size: 1rem;
            max-width: 300px;
            margin: 0;
        }

        .right-side {
            flex: 1;
            padding: 40px;
            background: #fff;
        }

        .right-side h2 {
            text-align: center;
            color: #2c3e50;
            margin-bottom: 25px;
            font-size: 1.8rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        input[type="email"] {
            width: 100%;
            padding: 14px 16px;
            margin: 10px 0;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
            outline: none;
            transition: border 0.3s, box-shadow 0.3s;
        }

        input:focus {
            border-color: #56ab2f;
            box-shadow: 0 0 0 3px rgba(86,171,47,0.1);
        }

        button {
            width: 100%;
            padding: 14px;
            background: #56ab2f;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            margin-top: 15px;
            transition: 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        button:hover {
            background: #468f24;
            transform: translateY(-1px);
        }

        .alert {
            padding: 12px;
            margin: 15px 0;
            border-radius: 8px;
            font-size: 14px;
            animation: fadeIn 0.4s ease-out;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .link {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
        }

        .link a {
            color: #56ab2f;
            text-decoration: none;
            font-weight: 500;
        }

        .link a:hover {
            text-decoration: underline;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 768px) {
            .split-container {
                flex-direction: column;
                margin: 15px;
            }
            .left-side, .right-side {
                padding: 30px;
            }
            .left-side {
                border-right: none;
                border-bottom: 1px solid #eee;
            }
        }

        @media (max-width: 480px) {
            .left-side h1 {
                font-size: 1.8rem;
            }
            input, button {
                font-size: 15px;
            }
        }
    </style>
</head>
<body>

<div class="split-container">
    <div class="left-side">
        <div class="logo">
            <img src="<?= htmlspecialchars(LOGO) ?>" alt="Logo">
        </div>
        <h1><?= htmlspecialchars(SITE_NAME) ?></h1>
    </div>

    <div class="right-side">
        <h2>Forgot Password</h2>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <input type="email" name="email" placeholder="Enter your email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus>
            </div>
            <button type="submit">
                <i class="fas fa-paper-plane"></i> Send Reset Code
            </button>
        </form>

        <div class="link">
            <a href="index.php">Back to Login</a>
        </div>
    </div>
</div>

</body>
</html>