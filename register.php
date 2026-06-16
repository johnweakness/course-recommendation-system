<?php 
include 'config.php'; 
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Register - <?= htmlspecialchars(SITE_NAME) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Keep your existing styles exactly as they were */
        body { background: linear-gradient(135deg, #56ab2f 0%, #a8e6cf 100%) !important; min-height: 100vh; margin: 0; display: flex; align-items: center; justify-content: center; font-family: 'Segoe UI', sans-serif; }
        .split-container { display: flex; width: 100%; max-width: 1100px; background: white; border-radius: 15px; overflow: hidden; box-shadow: 0 15px 35px rgba(0,0,0,0.2); margin: 20px; }
        .left-side { flex: 1; background: #f8f9fa; padding: 40px; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; border-right: 1px solid #eee; }
        .left-side .logo img { width: 100px; height: 100px; border-radius: 50%; margin-bottom: 20px; border: none; }
        .left-side h1 { font-size: 2.2rem; color: #2c3e50; margin-bottom: 10px; }
        .left-side p { color: #7f8c8d; font-size: 1rem; max-width: 300px; }
        .right-side { flex: 1; padding: 40px; background: white; }
        .right-side h2 { text-align: center; color: #2c3e50; margin-bottom: 25px; font-size: 1.8rem; }
        
        input[type="text"], input[type="email"], input[type="password"] { width: 100%; padding: 14px 70px 14px 16px; margin: 10px 0; border: 1px solid #ddd; border-radius: 8px; font-size: 16px; outline: none; transition: border 0.3s, box-shadow 0.3s; box-sizing: border-box; }
        input:focus { border-color: #56ab2f; box-shadow: 0 0 0 3px rgba(86,171,47,0.1); }
        input:invalid:not(:placeholder-shown) { border-color: #e74c3c; }
        
        .password-wrapper { position: relative; }
        
        .toggle-password { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: transparent; border: none; color: #56ab2f; font-size: 1.1rem; cursor: pointer; z-index: 10; padding: 5px; margin: 0; }
        .toggle-password:hover { color: #468f24; }

        .input-group { position: relative; display: flex; gap: 5px; align-items: center; }
        
        button:not(.toggle-password) { width: 100%; padding: 14px; background: #56ab2f; color: white; border: none; border-radius: 8px; font-size: 16px; font-weight: bold; cursor: pointer; margin-top: 15px; transition: 0.3s; display: flex; align-items: center; justify-content: center; gap: 8px; }
        button:not(.toggle-password):hover:not(:disabled) { background: #468f24; transform: translateY(-1px); }
        
        .btn-small { width: auto !important; padding: 0 20px !important; height: 50px; margin: 10px 0 !important; font-size: 14px; white-space: nowrap; }

        button:disabled, button[disabled] { background-color: #cccccc !important; color: #666666 !important; cursor: not-allowed !important; transform: none !important; box-shadow: none !important; }

        .alert { padding: 12px; margin: 15px 0; border-radius: 8px; font-size: 14px; animation: fadeIn 0.4s ease-out; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .link { text-align: center; margin-top: 20px; font-size: 14px; }
        .link a { color: #56ab2f; text-decoration: none; font-weight: 500; }
        .link a:hover { text-decoration: underline; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        @media (max-width: 768px) { .split-container { flex-direction: column; } .left-side, .right-side { padding: 30px; } .left-side { border-right: none; border-bottom: 1px solid #eee; } }
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
        <h2>Create Account</h2>

        <?php
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $name  = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $pass  = $_POST['password'] ?? '';
            $cpass = $_POST['cpassword'] ?? '';

            $errors = [];

            if (empty($_SESSION['is_verified']) || $_SESSION['is_verified'] !== true) {
                $errors[] = 'You must verify your email address before registering.';
            }

            if (empty($name)) { $errors[] = 'Full name is required.'; } 
            elseif (!preg_match("/^[a-zA-Z\s]+$/", $name)) { $errors[] = 'Name must contain only letters and spaces.'; }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
            if (isset($_SESSION['temp_email']) && $email !== $_SESSION['temp_email']) {
                $errors[] = 'The email you verified does not match the one entered.';
            }

            if ($pass !== $cpass) $errors[] = 'Passwords do not match.';
            
            // --- NEW PASSWORD VALIDATION START ---
            if (strlen($pass) < 6) {
                $errors[] = 'Password must be at least 6 characters.';
            } 
            // Check for: At least one letter AND At least one number AND At least one special char
            elseif (!preg_match('/(?=.*[a-zA-Z])(?=.*\d)(?=.*[\W_])/', $pass)) {
                $errors[] = 'Password must contain at least one letter, one number, and one special character.';
            }
            // --- NEW PASSWORD VALIDATION END ---

            if (empty($errors)) {
                $hash = password_hash($pass, PASSWORD_DEFAULT);
                $stmt = $mysqli->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
                $stmt->bind_param("sss", $name, $email, $hash);

                if ($stmt->execute()) {
                    unset($_SESSION['temp_otp']);
                    unset($_SESSION['temp_email']);
                    unset($_SESSION['is_verified']);
                    
                    echo '<div class="alert alert-success">Account created! Redirecting...</div>';
                    echo '<script>setTimeout(() => window.location.replace("index.php"), 800);</script>';
                    exit;
                } else {
                    $errors[] = 'Email already exists.';
                }
                $stmt->close();
            }

            foreach ($errors as $msg) {
                echo "<div class='alert alert-danger'>" . htmlspecialchars($msg) . "</div>";
            }
        }
        ?>

        <form method="POST" id="regForm">
            <input type="text" name="name" placeholder="Full Name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" pattern="[a-zA-Z\s]+" title="Only letters and spaces allowed" required>

            <div class="input-group">
                <input type="email" name="email" id="emailInput" placeholder="Email Address" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                <button type="button" class="btn-small" id="sendBtn" onclick="sendCode()">
                    Send Code
                </button>
            </div>
            <small id="emailMsg" style="display:block; margin-bottom:10px;"></small>

            <div class="input-group" id="otpSection" style="display: none;">
                <input type="text" id="otpInput" placeholder="Enter Verification Code">
                <button type="button" class="btn-small" onclick="verifyCode()">
                    Verify
                </button>
            </div>
            <small id="otpMsg" style="display:block; margin-bottom:10px;"></small>

            <div class="password-wrapper">
                <input type="password" name="password" id="pass" placeholder="Password" 
                       pattern="(?=.*[a-zA-Z])(?=.*\d)(?=.*[\W_]).{6,}"
                       title="Must contain at least 6 characters, including letters, numbers, and special characters."
                       required>
                <button type="button" class="toggle-password" onclick="togglePassword('pass')"><i class="fas fa-eye"></i></button>
            </div>

            <div class="password-wrapper">
                <input type="password" name="cpassword" id="cpass" placeholder="Confirm Password" required>
                <button type="button" class="toggle-password" onclick="togglePassword('cpass')"><i class="fas fa-eye"></i></button>
            </div>

            <button type="submit" id="registerBtn" disabled title="Please verify email first">
                <i class="fas fa-lock"></i> Register
            </button>
        </form>

        <div class="link">
            <a href="index.php">Already have an account? Login</a>
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

// --- AJAX FUNCTIONS FOR EMAIL VERIFICATION ---
function sendCode() {
    const email = document.getElementById('emailInput').value;
    const msg = document.getElementById('emailMsg');
    const btn = document.getElementById('sendBtn');

    if(email === '') {
        msg.style.color = 'red';
        msg.innerText = 'Please enter an email first.';
        return;
    }

    btn.disabled = true;
    btn.innerText = 'Sending...';

    const formData = new FormData();
    formData.append('action', 'send');
    formData.append('email', email);

    fetch('send_otp.php', { method: 'POST', body: formData })
    .then(response => response.json())
    .then(data => {
        if(data.status === 'success') {
            msg.style.color = 'green';
            msg.innerText = 'Code sent! Check your email.';
            document.getElementById('otpSection').style.display = 'flex';
        } else {
            msg.style.color = 'red';
            msg.innerText = data.message;
            btn.disabled = false;
            btn.innerText = 'Send Code';
        }
    })
    .catch(error => { console.error('Error:', error); btn.disabled = false; });
}

function verifyCode() {
    const code = document.getElementById('otpInput').value;
    const msg = document.getElementById('otpMsg');
    const regBtn = document.getElementById('registerBtn');

    const formData = new FormData();
    formData.append('action', 'verify');
    formData.append('otp_code', code);

    fetch('send_otp.php', { method: 'POST', body: formData })
    .then(response => response.json())
    .then(data => {
        if(data.status === 'success') {
            msg.style.color = 'green';
            msg.innerText = 'Email Verified Successfully!';
            regBtn.disabled = false;
            regBtn.innerHTML = '<i class="fas fa-user-plus"></i> Register';
            document.getElementById('emailInput').readOnly = true;
            document.getElementById('otpSection').style.display = 'none';
        } else {
            msg.style.color = 'red';
            msg.innerText = 'Invalid Code. Try again.';
        }
    });
}
</script>

</body>
</html>