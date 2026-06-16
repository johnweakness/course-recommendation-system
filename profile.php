<?php
session_start();
include 'config.php';

require 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!defined('SMTP_HOST')) define('SMTP_HOST', 'smtp.gmail.com');
if (!defined('SMTP_USER')) define('SMTP_USER', 'rsucrs0@gmail.com');
if (!defined('SMTP_PASS')) define('SMTP_PASS', 'okhz zrgc twzl xmpp');
if (!defined('SMTP_PORT')) define('SMTP_PORT', 587);

// USER AUTH CHECK
if (!isset($_SESSION['user_auth']['logged_in']) || !$_SESSION['user_auth']['logged_in']) {
    header("Location: index.php");
    exit;
}

$user_id    = $_SESSION['user_auth']['id'];
$user_name  = $_SESSION['user_auth']['name'];
$user_email = $_SESSION['user_auth']['email'];
$upload_dir = 'uploads/profile/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// === PERSIST FORM STATE ===
$_SESSION['show_password_form'] ??= false;
$_SESSION['verification_sent'] ??= false;
$_SESSION['show_email_form'] ??= false;
$_SESSION['email_verification_sent'] ??= false;

$message = '';
$message_type = '';

// 1. FETCH CURRENT USER DATA
$stmt = $mysqli->prepare("SELECT name, email, profile_image, address, birthday, sex FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($name, $email, $profile_image, $address, $birthday, $sex);
$stmt->fetch();
$stmt->close();

if ($sex === null) $sex = '';
if ($birthday === null) $birthday = '';

// === REDIRECT AFTER POST ===
$needs_redirect = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $needs_redirect = true; // Default true

    // --- 1. CHECK CANCEL FIRST (Stops other actions) ---
    if (isset($_POST['cancel_email_change'])) {
        unset($_SESSION['pending_email']);
        $_SESSION['email_verification_sent'] = false;
        $_SESSION['show_email_form'] = false; 
        $message = "Email change cancelled.";
        $message_type = "success";
    }

    // --- 2. CHECK EMAIL REQUEST/RESEND (Before Update Profile) ---
    elseif (isset($_POST['request_email_code']) || isset($_POST['resend_email_code'])) {
        $_SESSION['show_email_form'] = true;

        $new_email = '';
        if (isset($_POST['request_email_code'])) {
            $new_email = trim($_POST['new_email']);
        } else {
            $new_email = $_SESSION['pending_email'] ?? '';
        }

        // --- VALIDATION RESTORED ---
        if (empty($new_email)) {
            $message = "Please enter an email address.";
            $message_type = "error";
            $needs_redirect = false; // Stop redirect to show error
        } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $message = "Invalid email format.";
            $message_type = "error";
            $needs_redirect = false; // Stop redirect to show error
        } elseif ($new_email === $email) {
            $message = "Please enter a different email address.";
            $message_type = "error";
            $needs_redirect = false; // Stop redirect to show error
        } else {
            $token   = random_int(100000, 999999);
            $expiry  = gmdate("Y-m-d H:i:s", strtotime("+5 minutes"));

            $stmt = $mysqli->prepare("UPDATE users SET password_reset_token = ?, password_reset_expiry = ? WHERE id = ?");
            $stmt->bind_param("ssi", $token, $expiry, $user_id);
            $stmt->execute();
            $stmt->close();

            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = SMTP_HOST;
                $mail->SMTPAuth = true;
                $mail->Username = SMTP_USER;
                $mail->Password = SMTP_PASS;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = SMTP_PORT;
                $mail->setFrom(SMTP_USER, SITE_NAME);
                $mail->ClearAddresses();
                $mail->addAddress($new_email);
                
                $mail->isHTML(false);
                $mail->Subject = "Email Change Code – " . SITE_NAME;
                $mail->Body = "Hi $name,\n\nVerify your new email: $new_email\n\nCode: $token\n\nExpires in 5 mins.";
                $mail->send();

                $_SESSION['pending_email'] = $new_email;
                $_SESSION['email_verification_sent'] = true;
                $message = "Code sent to " . htmlspecialchars($new_email);
                $message_type = "success";
            } catch (Exception $e) {
                $message = "Failed to send code.";
                $message_type = "error";
                $needs_redirect = false;
            }
        }
    }

    // --- 3. CHECK EMAIL VERIFY ---
    elseif (isset($_POST['verify_email_code'])) {
        $_SESSION['show_email_form'] = true; 
        $code = trim($_POST['verification_code']);
        $pending_email = $_SESSION['pending_email'] ?? '';

        $stmt = $mysqli->prepare("SELECT password_reset_token, password_reset_expiry FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->bind_result($db_token, $db_expiry);
        $stmt->fetch();
        $stmt->close();

        $now = gmdate("Y-m-d H:i:s");

        if ($db_token && $db_expiry > $now && $code === $db_token && $pending_email) {
            $stmt = $mysqli->prepare("UPDATE users SET email = ?, password_reset_token = NULL, password_reset_expiry = NULL WHERE id = ?");
            $stmt->bind_param("si", $pending_email, $user_id);
            $stmt->execute();
            $stmt->close();

            $email = $pending_email;
            $_SESSION['user_email'] = $email;
            unset($_SESSION['pending_email']);
            $_SESSION['email_verification_sent'] = false;
            $_SESSION['show_email_form'] = false; 

            $message = "Email updated successfully!";
            $message_type = "success";
        } else {
            $message = "Invalid or expired code.";
            $message_type = "error";
            $_SESSION['email_verification_sent'] = true;
            $needs_redirect = false; 
        }
    }

    /* ----------------------- PASSWORD CHANGE ----------------------- */
    elseif (isset($_POST['request_code']) || isset($_POST['resend_password_code'])) {
        $token  = random_int(100000, 999999);
        $expiry = gmdate("Y-m-d H:i:s", strtotime("+5 minutes"));

        $stmt = $mysqli->prepare("UPDATE users SET password_reset_token = ?, password_reset_expiry = ? WHERE id = ?");
        $stmt->bind_param("ssi", $token, $expiry, $user_id);
        $stmt->execute();
        $stmt->close();

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USER;
            $mail->Password = SMTP_PASS;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = SMTP_PORT;
            $mail->setFrom(SMTP_USER, SITE_NAME);
            $mail->addAddress($email);
            $mail->isHTML(false);
            $mail->Subject = "Password Change Code";
            $mail->Body = "Hi $name,\n\nYour code: $token\n\nExpires in 5 mins.";
            $mail->send();

            $_SESSION['verification_sent'] = true;
            $message = "New code sent.";
            $message_type = "success";
        } catch (Exception $e) {
            $message = "Failed to send.";
            $message_type = "error";
            $needs_redirect = false;
        }
    }

    elseif (isset($_POST['verify_code'])) {
        $code = trim($_POST['verification_code']);

        $stmt = $mysqli->prepare("SELECT password_reset_token, password_reset_expiry FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->bind_result($db_token, $db_expiry);
        $stmt->fetch();
        $stmt->close();

        $now = gmdate("Y-m-d H:i:s");

        if ($db_token && $db_expiry > $now && $code === $db_token) {
            $_SESSION['show_password_form'] = true;
            $stmt = $mysqli->prepare("UPDATE users SET password_reset_token = NULL, password_reset_expiry = NULL WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();
        } else {
            $message = "Invalid code.";
            $message_type = "error";
            $_SESSION['verification_sent'] = true;
            $needs_redirect = false;
        }
    }

    elseif (isset($_POST['change_password']) && $_SESSION['show_password_form']) {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if ($new !== $confirm) {
            $message = "Passwords don't match.";
            $message_type = "error";
            $needs_redirect = false;
        } elseif (strlen($new) < 6) {
            $message = "Password too short.";
            $message_type = "error";
            $needs_redirect = false;
        } else {
            $stmt = $mysqli->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->bind_result($hashed);
            $stmt->fetch();
            $stmt->close();

            if (password_verify($current, $hashed)) {
                $new_hashed = password_hash($new, PASSWORD_DEFAULT);
                $stmt = $mysqli->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->bind_param("si", $new_hashed, $user_id);
                if ($stmt->execute()) {
                    $_SESSION['show_password_form'] = false;
                    $_SESSION['verification_sent'] = false;
                    $message = "Password changed!";
                    $message_type = "success";
                }
                $stmt->close();
            } else {
                $message = "Wrong current password.";
                $message_type = "error";
                $needs_redirect = false;
            }
        }
    }

    // --- 4. CHECK PROFILE UPDATE LAST (As "else if") ---
    // This prevents "Send Code" or "Cancel" from triggering a profile update
    elseif (isset($_POST['update_profile'])) {
        $new_name     = trim($_POST['name']);
        $new_address  = trim($_POST['address']);
        $new_birthday = $_POST['birthday'] ?? ''; 
        $new_sex      = $_POST['sex'] ?? ''; 
        $new_profile_image = $profile_image;

        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['profile_image'];
            $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif'];
            if (in_array($ext, $allowed) && $file['size'] <= 20 * 1024 * 1024) {
                $new_filename = $user_id . '_' . time() . '.' . $ext;
                $dest = $upload_dir . $new_filename;
                if (move_uploaded_file($file['tmp_name'], $dest)) {
                    if ($profile_image && $profile_image !== 'avatar.png' && file_exists($upload_dir . $profile_image)) {
                        unlink($upload_dir . $profile_image);
                    }
                    $new_profile_image = $new_filename;
                }
            } else {
                $message = "Image must be JPG, PNG, or GIF and maximum 20 MB.";
                $message_type = "error";
                $needs_redirect = false;
            }
        }

        if (!empty($_POST['remove_image'])) {
            if ($profile_image && $profile_image !== 'avatar.png' && file_exists($upload_dir . $profile_image)) {
                unlink($upload_dir . $profile_image);
            }
            $new_profile_image = 'avatar.png';
        }

        // Only run update if no upload error occurred
        if ($message_type !== "error") {
            $stmt = $mysqli->prepare("UPDATE users SET name = ?, address = ?, birthday = ?, sex = ?, profile_image = ? WHERE id = ?");
            $stmt->bind_param("sssssi", $new_name, $new_address, $new_birthday, $new_sex, $new_profile_image, $user_id);
            
            if ($stmt->execute()) {
                $message = "Profile updated successfully!";
                $message_type = "success";
                
                $refresh = $mysqli->prepare("SELECT name, email, profile_image, address, birthday, sex FROM users WHERE id = ?");
                $refresh->bind_param("i", $user_id);
                $refresh->execute();
                $refresh->bind_result($name, $email, $profile_image, $address, $birthday, $sex);
                $refresh->fetch();
                $refresh->close();

                $profile_image_path = $upload_dir . ($profile_image ?: 'avatar.png');
                $final_image_url = $profile_image_path;
                if ($profile_image && $profile_image !== 'avatar.png' && file_exists($profile_image_path)) {
                    $final_image_url .= '?v=' . filemtime($profile_image_path);
                }

                $_SESSION['user_name'] = $name;
                $_SESSION['user_email'] = $email;
                $_SESSION['user_profile_image'] = $profile_image;
                $_SESSION['final_image_url'] = $final_image_url;
            } else {
                $message = "Update failed: " . $stmt->error;
                $message_type = "error";
                $needs_redirect = false;
            }
            $stmt->close();
        }
    }
}

// === REDIRECT AFTER POST ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $needs_redirect) {
    $query = http_build_query($_GET);
    $redirect_url = 'profile.php?' . $query;
    if (!str_contains($redirect_url, 'updated=')) {
        $redirect_url .= (strpos($redirect_url, '?') === false ? '?' : '&') . 'updated=1';
    }
    header("Location: $redirect_url");
    exit;
}

// === SESSION STATE ===
$show_password_form       = $_SESSION['show_password_form']       ?? false;
$verification_sent        = $_SESSION['verification_sent']        ?? false;
$email_verification_sent  = $_SESSION['email_verification_sent']  ?? false;
$show_email_form          = $_SESSION['show_email_form']          ?? false;

$js_name     = htmlspecialchars($name, ENT_QUOTES);
$js_address  = htmlspecialchars($address, ENT_QUOTES);
$js_birthday = htmlspecialchars($birthday, ENT_QUOTES); 
$js_sex      = htmlspecialchars($sex, ENT_QUOTES);

// === UNIFIED FINAL IMAGE URL ===
$default_avatar = 'avatar.png';
$final_image_url = $_SESSION['final_image_url'] ?? ($upload_dir . ($profile_image ?: $default_avatar));
if ($profile_image && $profile_image !== $default_avatar && file_exists($upload_dir . $profile_image)) {
    $final_image_url = $upload_dir . $profile_image . '?v=' . filemtime($upload_dir . $profile_image);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile - <?= htmlspecialchars(SITE_NAME) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Base styles */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f7fa; color: #333; line-height: 1.6; min-height: 100vh; }
        a { text-decoration: none; color: inherit; }

        .header { background: #56ab2f; color: white; padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 10px rgba(0,0,0,0.1); position: sticky; top: 0; z-index: 1000; }
        .logo { display: flex; align-items: center; gap: 12px; font-size: 1.4rem; font-weight: 600; }
        .logo img { width: 40px; height: 40px; border-radius: 50%; border: 2px solid rgba(255,255,255,0.3); }
        
        .user-profile {
            display: flex; align-items: center; gap: 10px; font-size: 0.95rem;
            position: relative; cursor: pointer; padding: 5px 10px;
            border-radius: 8px; transition: background 0.3s;
        }
        .user-profile:hover { background: rgba(255,255,255,0.1); }
        .user-profile img { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; border: 2px solid rgba(255,255,255,0.4); }
        
        .profile-dropdown {
            display: none; position: absolute; top: 120%; right: 0;
            background: white; color: #333; min-width: 160px;
            border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.15);
            z-index: 1100; overflow: hidden; animation: fadeInDropdown 0.2s ease-out;
        }
        .profile-dropdown.active { display: block; }
        .profile-dropdown a {
            display: flex; align-items: center; gap: 10px; padding: 12px 16px;
            text-decoration: none; font-size: 0.95rem; color: #2c3e50; transition: background 0.2s;
        }
        .profile-dropdown a:hover { background: #f4f7fa; color: #56ab2f; }
        .profile-dropdown i { width: 20px; text-align: center; color: #7f8c8d; }
        .profile-dropdown a:hover i { color: #56ab2f; }
        @keyframes fadeInDropdown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .menu-toggle { display: none; background: none; border: none; color: white; font-size: 1.5rem;  cursor: pointer; }
        
        .sidebar { width: 260px; background: #2c3e50; color: white; height: calc(100vh - 64px); position: fixed; top: 64px; left: 0; overflow-y: auto; transition: transform 0.3s ease; z-index: 900; }
        .sidebar.collapsed { transform: translateX(-100%); }
        .nav-menu { list-style: none; padding: 1.5rem 0; }
        .nav-item { padding: 0.9rem 1.5rem; display: flex; align-items: center; gap: 12px; transition: 0.3s; cursor: pointer; }
        .nav-item:hover, .nav-item.active { background: #34495e; }
        .nav-item i { width: 20px; text-align: center; }
        
        .main-content { margin-left: 260px; padding: 2rem; transition: margin-left 0.3s ease; }
        .main-content.expanded { margin-left: 0; }
        .container { max-width: 800px; margin: 0 auto; }
        .page-title { font-size: 1.8rem; color: #2c3e50; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 10px; }
        
        /* Form specific styles */
        .card { background: white; border-radius: 12px; padding: 1.8rem; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 1.5rem; }
        .section-title { font-size: 1.3rem; color: #2c3e50; margin-bottom: 1.2rem; padding-bottom: 0.5rem; border-bottom: 2px solid #56ab2f; display: inline-block; }
        .form-group { margin-bottom: 1.2rem; position: relative; }
        .form-group label { display: block; margin-bottom: 0.5rem; font-weight: 600; color: #2c3e50; }
        .form-group input, .form-group textarea { width: 100%; padding: 0.8rem 1rem; border: 1px solid #ddd; border-radius: 8px; font-size: 1rem; transition: border 0.3s; }
        .form-group input:focus, .form-group textarea:focus { outline: none; border-color: #56ab2f; }
        
        .profile-preview { width: 120px; height: 120px; border-radius: 50%; object-fit: cover; border: 4px solid #56ab2f; box-shadow: 0 4px 12px rgba(0,0,0,0.1); margin-bottom: 1rem; }
        .image-upload { position: relative; display: inline-block; cursor: pointer; }
        .image-upload input[type="file"] { position: absolute; opacity: 0; width: 100%; height: 100%; cursor: pointer; }
        .upload-btn { background: #56ab2f; color: white; padding: 0.6rem 1.2rem; border-radius: 8px; font-size: 0.9rem; display: inline-flex; align-items: center; gap: 8px; transition: 0.3s; }
        .upload-btn:hover { background: #468f24; }
        .remove-image { background: #dc3545; color: white; padding: 0.5rem 1rem; border: none; border-radius: 6px; font-size: 0.85rem; margin-left: 10px; cursor: pointer; }
        .remove-image:hover { background: #c82333; }

        .btn-group { display: flex; gap: 1rem; margin-top: 1.5rem; flex-wrap: wrap; align-items: center; }
        .btn { padding: 0.8rem 1.6rem; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.3s; display: inline-flex; align-items: center; gap: 8px; font-size: 0.95rem; }
        .btn-primary { background: #56ab2f; color: white; }
        .btn-primary:hover { background: #468f24; }
        .btn-primary:disabled { background: #aaa; cursor: not-allowed; opacity: 0.7; }
        
        .message { padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; font-weight: 500; }
        .message.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .code-digit { width: 48px; height: 48px; text-align: center; font-size: 1.3rem; font-weight: bold; border: 1px solid #ddd; border-radius: 8px; }
        .code-digit:focus { outline: none; border-color: #56ab2f; }

        .overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:800;}
        .overlay.active{display:block;}

        .logout-popup { display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 1.5rem 2rem; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); z-index: 1001; text-align: center; max-width: 320px; width: 90%; }
        .logout-popup.active { display: block; animation: fadeIn 0.3s ease-out; }
        .logout-popup h3 { margin: 0 0 1rem; color: #2c3e50; font-size: 1.3rem; }
        .logout-popup p { color: #666; margin-bottom: 1.5rem; font-size: 0.95rem; }
        .logout-btns { display: flex; gap: 1rem; justify-content: center; }
        .logout-btns button { padding: 0.6rem 1.2rem; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: 0.2s; min-width: 80px; }
        .logout-btns .yes { background: #dc3545; color: white; }
        .logout-btns .yes:hover { background: #c82333; }
        .logout-btns .no { background: #6c757d; color: white; }
        .logout-btns .no:hover { background: #5a6268; }
        @keyframes fadeIn { from { opacity: 0; transform: translate(-50%, -60%); } to { opacity: 1; transform: translate(-50%, -50%); } }

        @media (max-width: 992px) { .sidebar { transform: translateX(-100%); } .sidebar.active { transform: translateX(0); } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
    </style>
</head>
<body>

    <header class="header">
        <div class="logo">
            <img src="<?= htmlspecialchars(LOGO) ?>" alt="Logo">
            <span>Course Recommendation System</span>
        </div>
        
        <div class="user-profile" id="userProfileTrigger">
            <img src="<?= htmlspecialchars($final_image_url) ?>" alt="Profile" onerror="this.src='uploads/profile/avatar.png'">
            <span><?= htmlspecialchars($name) ?></span>
            <div class="profile-dropdown" id="profileDropdown">
                <a href="profile.php"><i class="fas fa-user"></i> Profile</a>
            </div>
        </div>
        <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    </header>

    <aside class="sidebar" id="sidebar">
        <ul class="nav-menu">
            <li class="nav-item"><a href="dashboard.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a></li>
            <li class="nav-item"><a href="recommendations.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;"><i class="fas fa-lightbulb"></i><span>Get Recommendation</span></a></li>
            <li class="nav-item"><a href="history.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;"><i class="fas fa-history"></i><span>History</span></a></li>
            <li class="nav-item"><a href="#" id="logoutLink" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a></li>
        </ul>
    </aside>

    <div class="overlay" id="overlay"></div>

    <div class="logout-popup" id="logoutPopup">
        <h3>Logout Confirmation</h3>
        <p>Are you sure you want to log out?</p>
        <div class="logout-btns">
            <button class="yes" onclick="window.location.href='logout_process.php'">Yes</button>
            <button class="no" onclick="window.closeLogoutPopup()">No</button>
        </div>
    </div>

    <main class="main-content" id="mainContent">
        <div class="container">
            <h1 class="page-title"><i class="fas fa-user-edit"></i> Edit Profile</h1>

            <?php if ($message): ?>
                <div class="message <?= $message_type ?>">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <h3 class="section-title">Personal Information</h3>
                <form method="POST" enctype="multipart/form-data" id="profileForm" action="profile.php?updated=1">
                    <input type="hidden" id="orig_name" value="<?= $js_name ?>">
                    <input type="hidden" id="orig_address" value="<?= $js_address ?>">
                    <input type="hidden" id="orig_birthday" value="<?= $js_birthday ?>"> 
                    <input type="hidden" id="orig_sex" value="<?= $js_sex ?>"> 
                    <input type="hidden" name="remove_image" value="" id="removeImageInput">

                    <div style="text-align:center;margin-bottom:1.5rem;">
                        <img src="<?= htmlspecialchars($final_image_url) ?>" alt="Profile" class="profile-preview" id="previewImg" onerror="this.src='uploads/profile/avatar.png'">
                        <div style="margin-top:10px;">
                            <label class="image-upload">
                                <input type="file" name="profile_image" accept="image/*" id="profileImageInput">
                                <span class="upload-btn">Change Photo</span>
                            </label>
                            <?php if ($profile_image !== 'avatar.png'): ?>
                                <button type="button" class="remove-image" id="removeBtn" onclick="removeProfileImage()">Remove</button>
                            <?php endif; ?>
                        </div>
                        <small style="color:#666;display:block;margin-top:8px;">Max 20 MB – JPG, PNG, GIF</small>
                    </div>

                    <div class="form-group">
                        <label for="name">Full Name</label>
                        <input type="text" id="name" name="name" value="<?= $js_name ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" value="<?= htmlspecialchars($email) ?>" readonly>
                        
                        <?php if (!$email_verification_sent && !$show_email_form): ?>
                            <button type="button" class="btn btn-primary" style="margin-top:8px;padding:0.5rem 1rem;" onclick="toggleEmailChange()">
                                Change Email
                            </button>
                        <?php endif; ?>

                        <div id="emailChangeSection" style="margin-top: 15px; border-left: 3px solid #56ab2f; padding-left: 15px; display: <?= ($email_verification_sent || $show_email_form) ? 'block' : 'none' ?>;">
                            
                            <?php if (!$email_verification_sent): ?>
                                <label for="new_email">New Email Address</label>
                                <div style="display:flex; gap:10px; margin-bottom:10px;">
                                    <input type="email" id="new_email" name="new_email" placeholder="Enter new email" value="<?= htmlspecialchars($_POST['new_email'] ?? $_SESSION['pending_email'] ?? '') ?>">
                                    <button type="submit" name="request_email_code" class="btn btn-primary" formnovalidate>Send Code</button>
                                </div>
                                <button type="submit" name="cancel_email_change" class="btn" style="background:#dc3545;color:white;padding:0.5rem 1rem;" formnovalidate>Cancel</button>
                            
                            <?php else: ?>
                                <label>Verification Code sent to <strong><?= htmlspecialchars($_SESSION['pending_email'] ?? 'your email') ?></strong></label>
                                <div style="display:flex;gap:8px;margin:0.5rem 0;">
                                    <input type="text" maxlength="1" class="code-digit" inputmode="numeric">
                                    <input type="text" maxlength="1" class="code-digit" inputmode="numeric">
                                    <input type="text" maxlength="1" class="code-digit" inputmode="numeric">
                                    <input type="text" maxlength="1" class="code-digit" inputmode="numeric">
                                    <input type="text" maxlength="1" class="code-digit" inputmode="numeric">
                                    <input type="text" maxlength="1" class="code-digit" inputmode="numeric">
                                </div>
                                <input type="hidden" name="verification_code" id="emailCode">
                                
                                <div class="btn-group" style="margin-top:10px;">
                                    <button type="submit" name="verify_email_code" class="btn btn-primary" formnovalidate>Verify</button>
                                    <button type="button" onclick="resendEmailCode()" class="btn btn-primary" style="margin-left:8px;padding:0.5rem 1rem;">Resend</button>
                                    <button type="submit" name="cancel_email_change" class="btn" style="background:#dc3545;color:white;margin-left:8px;padding:0.5rem 1rem;" formnovalidate>Cancel</button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="address">Address</label>
                        <textarea id="address" name="address" required><?= $js_address ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="birthday">Birthday</label>
                        <input type="date" id="birthday" name="birthday" value="<?= $js_birthday ?>">
                    </div>

                    <div class="form-group">
                        <label>Sex</label>
                        <div style="display:flex;gap:1.5rem;margin-top:0.5rem;">
                            <label><input type="radio" name="sex" value="Male" <?= $sex==='Male'?'checked':'' ?> required> Male</label>
                            <label><input type="radio" name="sex" value="Female" <?= $sex==='Female'?'checked':'' ?>> Female</label>
                        </div>
                    </div>

                    <input type="hidden" name="update_profile" value="1">

                    <div class="btn-group">
                        <button type="submit" id="saveBtn" class="btn btn-primary" disabled>
                            <span id="saveText">Save Changes</span>
                            <span id="savingText" style="display:none;">Saving...</span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="card">
                <h3 class="section-title">Change Password</h3>
                <?php if (!$verification_sent && !$show_password_form): ?>
                    <form method="POST" action="profile.php?updated=1">
                        <p style="margin-bottom:1rem;">Code will be sent to: <strong><?= htmlspecialchars($email) ?></strong></p>
                        <div class="btn-group">
                            <button type="submit" name="request_code" class="btn btn-primary">Send Code</button>
                        </div>
                    </form>
                <?php elseif ($verification_sent && !$show_password_form): ?>
                    <form method="POST" action="profile.php?updated=1" onsubmit="return validateCode()">
                        <div class="form-group">
                            <label>Enter 6-digit code</label>
                            <div style="display:flex;gap:8px;justify-content:center;margin:0.5rem 0;">
                                <input type="text" maxlength="1" class="code-digit" inputmode="numeric">
                                <input type="text" maxlength="1" class="code-digit" inputmode="numeric">
                                <input type="text" maxlength="1" class="code-digit" inputmode="numeric">
                                <input type="text" maxlength="1" class="code-digit" inputmode="numeric">
                                <input type="text" maxlength="1" class="code-digit" inputmode="numeric">
                                <input type="text" maxlength="1" class="code-digit" inputmode="numeric">
                            </div>
                            <input type="hidden" name="verification_code" id="fullCode">
                        </div>
                        <div class="btn-group">
                            <button type="submit" name="verify_code" class="btn btn-primary">Verify</button>
                            <button type="button" onclick="resendPasswordCode()" class="btn btn-primary" style="margin-left:8px;padding:0.5rem 1rem;">Resend Code</button>
                        </div>
                    </form>
                <?php elseif ($show_password_form): ?>
                    <form method="POST" action="profile.php?updated=1">
                        <div class="form-group">
                            <label for="current_password">Current Password</label>
                            <input type="password" id="current_password" name="current_password" required>
                        </div>
                        <div class="form-group">
                            <label for="new_password">New Password</label>
                            <input type="password" id="new_password" name="new_password" required minlength="6">
                        </div>
                        <div class="form-group">
                            <label for="confirm_password">Confirm</label>
                            <input type="password" id="confirm_password" name="confirm_password" required minlength="6">
                        </div>
                        <div class="btn-group">
                            <button type="submit" name="change_password" class="btn btn-primary">Change</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        const userProfileTrigger = document.getElementById('userProfileTrigger');
        const profileDropdown = document.getElementById('profileDropdown');

        userProfileTrigger.addEventListener('click', (e) => {
            e.stopPropagation(); 
            profileDropdown.classList.toggle('active');
        });

        document.addEventListener('click', (e) => {
            if (!userProfileTrigger.contains(e.target)) {
                profileDropdown.classList.remove('active');
            }
        });

        function toggleEmailChange() {
            const section = document.getElementById('emailChangeSection');
            const btn = document.querySelector('button[onclick="toggleEmailChange()"]');
            
            if (section.style.display === 'none') {
                section.style.display = 'block';
                if(btn) btn.style.display = 'none'; // Hide "Change Email" button when form opens
            } else {
                section.style.display = 'none';
                const showBtn = document.querySelector('.btn-primary[onclick="toggleEmailChange()"]');
                if(showBtn) showBtn.style.display = 'inline-block';
            }
        }

        function resendEmailCode() {
            const form = document.createElement('form');
            form.method = 'POST'; form.action = 'profile.php?updated=1';
            const input = document.createElement('input');
            input.type = 'hidden'; input.name = 'resend_email_code'; input.value = '1';
            form.appendChild(input); document.body.appendChild(form); form.submit();
        }

        function resendPasswordCode() {
            const form = document.createElement('form');
            form.method = 'POST'; form.action = 'profile.php?updated=1';
            const input = document.createElement('input');
            input.type = 'hidden'; input.name = 'resend_password_code'; input.value = '1';
            form.appendChild(input); document.body.appendChild(form); form.submit();
        }

        const originalName    = document.getElementById('orig_name').value;
        const originalAddress = document.getElementById('orig_address').value;
        const originalBirthday= document.getElementById('orig_birthday').value; 
        const originalSex     = document.getElementById('orig_sex').value;

        function checkFormChanges() {
            const name = document.getElementById('name').value.trim();
            const address = document.getElementById('address').value.trim();
            const birthday = document.getElementById('birthday').value; 
            const sex = document.querySelector('input[name="sex"]:checked')?.value || ''; 
            const file = document.getElementById('profileImageInput').files.length > 0;
            const remove = document.getElementById('removeImageInput').value === '1';

            const changed = name !== originalName || address !== originalAddress || birthday !== originalBirthday || sex !== originalSex || file || remove;
            document.getElementById('saveBtn').disabled = !changed;
        }

        function removeProfileImage() {
            if (!confirm('Remove photo?')) return;
            document.getElementById('previewImg').src = 'uploads/profile/avatar.png';
            document.getElementById('profileImageInput').value = '';
            document.getElementById('removeImageInput').value = '1';
            const btn = document.getElementById('removeBtn');
            if (btn) btn.style.display = 'none';
            checkFormChanges();
        }

        document.getElementById('profileImageInput').addEventListener('change', function() {
            if (this.files.length > 0) {
                const reader = new FileReader();
                reader.onload = e => document.getElementById('previewImg').src = e.target.result;
                reader.readAsDataURL(this.files[0]);
                document.getElementById('removeImageInput').value = '';
                const btn = document.getElementById('removeBtn');
                if (btn) btn.style.display = 'inline-block';
            }
            checkFormChanges();
        });

        window.addEventListener('load', () => setTimeout(checkFormChanges, 100));
        document.getElementById('name').addEventListener('input', checkFormChanges);
        document.getElementById('address').addEventListener('input', checkFormChanges);
        document.getElementById('birthday').addEventListener('input', checkFormChanges);
        document.querySelectorAll('input[name="sex"]').forEach(r => r.addEventListener('change', checkFormChanges));

        document.getElementById('profileForm').addEventListener('submit', function(e) {
            // Only disable "Save Changes" if that was the button clicked.
            // Since "Send Code" is also a submit button inside this form, we check which button triggered it.
            // However, simplistically, we can just check if "saveBtn" is the one active.
            // For now, let's just allow the submit.
        });

        function setupCodeInputs(container, hiddenId) {
            const inputs = container.querySelectorAll('.code-digit');
            inputs.forEach((inp, i) => {
                inp.addEventListener('input', () => {
                    inp.value = inp.value.replace(/[^0-9]/g, '');
                    if (inp.value && i < 5) inputs[i+1].focus();
                    let code = ''; inputs.forEach(x => code += x.value);
                    document.getElementById(hiddenId).value = code;
                });
                inp.addEventListener('keydown', e => {
                    if (e.key === 'Backspace' && !inp.value && i > 0) inputs[i-1].focus();
                });
            });
        }

        const pwdForm = document.querySelector('form[onsubmit*="validateCode"]');
        if (pwdForm) setupCodeInputs(pwdForm, 'fullCode');
        
        // Updated selector for email inputs since they are now inside profileForm
        // We target the specific div wrapper
        const emailSection = document.getElementById('emailChangeSection');
        if (emailSection) setupCodeInputs(emailSection, 'emailCode');

        function validateCode() { return document.getElementById('fullCode').value.length === 6 || (alert('Enter 6 digits'), false); }

        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const overlay = document.getElementById('overlay');
        const logoutLink = document.getElementById('logoutLink');
        const logoutPopup = document.getElementById('logoutPopup');

        menuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
            mainContent.classList.toggle('expanded');
        });

        logoutLink.addEventListener('click', e => {
            e.preventDefault();
            logoutPopup.classList.add('active');
            overlay.classList.add('active');
        });

        window.closeLogoutPopup = () => {
            logoutPopup.classList.remove('active');
            overlay.classList.remove('active');
        };

        overlay.addEventListener('click', () => {
            if (sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
                mainContent.classList.remove('expanded');
            }
            if (logoutPopup.classList.contains('active')) window.closeLogoutPopup();
            overlay.classList.remove('active');
            if (profileDropdown.classList.contains('active')) {
                profileDropdown.classList.remove('active');
            }
        });

        window.addEventListener('resize', () => {
            if (window.innerWidth > 992) {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                mainContent.classList.remove('expanded');
            }
        });
    </script>
</body>
</html>
<?php $mysqli->close(); ?>