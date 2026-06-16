<?php
session_start();
include 'config.php';

$error = '';

if ($_POST) {
    $email = trim($_POST['email']);
    $pass  = $_POST['password'];

    $stmt = $mysqli->prepare("SELECT id, name, email, password FROM admin WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($admin = $res->fetch_assoc()) {
        if (password_verify($pass, $admin['password'])) {
            session_regenerate_id(true);

            // COMPLETELY SEPARATE NAMESPACE - NO OVERLAP WITH USER
            $_SESSION['admin_auth'] = [
                'logged_in' => true,
                'id'        => $admin['id'],
                'name'      => $admin['name'],
                'email'     => $admin['email'],
                'login_at'  => time()
            ];

            $stmt->close();
            header("Location: admin_dashboard.php");
            exit;
        } else {
            $error = "Invalid password.";
        }
    } else {
        $error = "No admin account found.";
    }
    if (isset($stmt)) $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Admin Login - <?= htmlspecialchars(SITE_NAME) ?></title>
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
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
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
        }

        .left-side p {
            color: #7f8c8d;
            font-size: 1rem;
            max-width: 300px;
        }

        .right-side {
            flex: 1;
            padding: 40px;
            background: white;
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

        .form-input {
            width: 100%;
            padding: 14px 50px 14px 16px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
            outline: none;
            transition: border 0.3s, box-shadow 0.3s;
            background: white;
        }

        .form-input:focus {
            border-color: #56ab2f;
            box-shadow: 0 0 0 3px rgba(86,171,47,0.1);
        }

        .password-wrapper {
            position: relative;
        }

        .toggle-password {
            position: absolute;
            right: 14px;
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
            color: white;
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
            .left-side, .right-side { padding: 30px; }
            .left-side { border-right: none; border-bottom: 1px solid #eee; }
        }
    </style>
</head>
<body>
<div class="split-container">
    <div class="left-side">
        <div class="logo">
            <img src="<?= htmlspecialchars(LOGO) ?>" alt="Logo">
        </div>
        <h1>Admin Panel</h1>
    </div>

    <div class="right-side">
        <h2>Admin Login</h2>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <input type="email" name="email" class="form-input" 
                       placeholder="Admin Email" 
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" 
                       required autofocus>
            </div>

            <div class="form-group">
                <div class="password-wrapper">
                    <input type="password" name="password" id="password" class="form-input" 
                           placeholder="Password" required>
                    <button type="button" class="toggle-password" onclick="togglePassword()" aria-label="Toggle password visibility">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

            <button type="submit">
                Admin Login
            </button>
        </form>
        </div>
    </div>
</div>

<script>
    function togglePassword() {
        const input = document.getElementById('password');
        const icon = document.querySelector('.toggle-password i');

        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    }
</script>
</body>
</html>