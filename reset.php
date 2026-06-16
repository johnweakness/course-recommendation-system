<?php
include 'config.php';

// Start session if not already started
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Security: Redirect if no reset email in session
if (empty($_SESSION['reset_email'])) {
    header("Location: forgot.php");
    exit;
}

$email = $_SESSION['reset_email'];
$errors = [];

if ($_POST) {
    $code = trim($_POST['code'] ?? '');
    $pass = $_POST['password'] ?? '';
    $cpass = $_POST['cpassword'] ?? '';

    // Validate 6-digit code
    if (!preg_match('/^\d{6}$/', $code)) {
        $errors[] = 'Please enter a valid 6-digit code.';
    }

    if ($pass !== $cpass) {
        $errors[] = 'Passwords do not match.';
    }
    if (strlen($pass) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }

    if (empty($errors)) {
        $stmt = $mysqli->prepare("SELECT id FROM users WHERE email = ? AND reset_token = ? AND reset_expiry > NOW()");
        $stmt->bind_param("ss", $email, $code);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows > 0) {
            $user = $res->fetch_assoc();
            $hash = password_hash($pass, PASSWORD_DEFAULT);

            $upd = $mysqli->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expiry = NULL WHERE id = ?");
            $upd->bind_param("si", $hash, $user['id']);
            $upd->execute();
            $upd->close();

            // Clear session
            unset($_SESSION['reset_email']);

            // Success + auto redirect
            echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i> Password reset successfully! Redirecting...</div>';
            echo '<script>setTimeout(() => window.location.replace("index.php"), 1200);</script>';
            exit;
        } else {
            $errors[] = 'Invalid or expired reset code.';
        }
        $stmt->close();
    }

    // Display errors
    foreach ($errors as $msg) {
        echo "<div class='alert alert-danger'><i class='fas fa-exclamation-triangle'></i> " . htmlspecialchars($msg) . "</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - <?= htmlspecialchars(SITE_NAME) ?></title>
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
            margin-bottom: 10px;
            line-height: 1.3;
        }

        .left-side p {
            color: #7f8c8d;
            font-size: 1rem;
            max-width: 300px;
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

        input[type="email"],
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 14px 70px 14px 16px;
            margin: 10px 0;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
            outline: none;
            transition: border 0.3s, box-shadow 0.3s;
            box-sizing: border-box;
        }

        input:focus {
            border-color: #56ab2f;
            box-shadow: 0 0 0 3px rgba(86,171,47,0.1);
        }

        .password-wrapper {
            position: relative;
        }

        .toggle-password {
            position: absolute;
            right: 10px;
            top: 0;
            bottom: 0;
            margin: auto;
            height: 22px;
            width: 22px;
            background: transparent;
            border: none;
            color: #56ab2f;
            font-size: 1.1rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: color 0.2s, background 0.2s;
        }

        .toggle-password:hover {
            background: rgba(86,171,47,0.1);
            border-radius: 50%;
            color: #468f24;
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

        .alert-success {
            background: #d4edda;
            color: #155721;
            border: 1px solid #c3e6cb;
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
    </style>
</head>
<body>

<div class="split-container">
    <div class="left-side">
        <div class="logo">
            <img src="<?= htmlspecialchars(LOGO) ?>" alt="Logo">
        </div>
        <h1>Course Recommendation System</h1>
    </div>

    <div class="right-side">
        <h2>Reset Password</h2>

        <form method="POST">
            <!-- 1. Email (readonly) -->
            <div class="form-group">
                <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" readonly>
            </div>

            <!-- 2. 6-DIGIT CODE (REQUIRED) -->
            <div class="form-group">
                <input type="text" name="code" placeholder="6-digit Reset Code" 
                       value="<?= htmlspecialchars($_POST['code'] ?? '') ?>" 
                       required pattern="\d{6}" title="Enter exactly 6 digits" 
                       maxlength="6" inputmode="numeric" autofocus>
            </div>

            <!-- 3. New Password -->
            <div class="password-wrapper">
                <input type="password" name="password" id="pass" placeholder="New Password" required minlength="6">
                <button type="button" class="toggle-password" onclick="togglePassword('pass')">
                    <i class="fas fa-eye"></i>
                </button>
            </div>

            <!-- 4. Confirm Password -->
            <div class="password-wrapper">
                <input type="password" name="cpassword" id="cpass" placeholder="Confirm New Password" required minlength="6">
                <button type="button" class="toggle-password" onclick="togglePassword('cpass')">
                    <i class="fas fa-eye"></i>
                </button>
            </div>

            <button type="submit">
                <i class="fas fa-key"></i> Reset Password
            </button>
        </form>

        <div class="link">
            <a href="index.php">Back to Login</a>
            <?php if (!empty($_SESSION['reset_email'])): ?>
                | <a href="forgot.php">Resend Code</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function togglePassword(id) {
    const input = document.getElementById(id);
    const icon = input.nextElementSibling.querySelector('i');
    if (input.type === "password") {
        input.type = "text";
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        input.type = "password";
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}
</script>

</body>
</html>