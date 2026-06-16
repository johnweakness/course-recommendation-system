<?php
include 'config.php';

// FORCE NO CACHING — deleted users disappear instantly
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

// ADMIN AUTH CHECK - COMPLETELY INDEPENDENT
if (!isset($_SESSION['admin_auth']['logged_in']) || !$_SESSION['admin_auth']['logged_in']) {
    header("Location: admin_login.php");
    exit;
}

$admin_id   = $_SESSION['admin_auth']['id'];
$admin_name = $_SESSION['admin_auth']['name'];

if (!$admin_id || !$admin_name) {
    // Safety net: if session is corrupted
    session_destroy();
    header("Location: admin_login.php");
    exit;
}

// ---------- PROFILE IMAGE LOGIC UPDATED ----------
$upload_dir    = 'uploads/profile/';
// CHANGED: Default is now admin.png
$default_image = 'uploads/profile/admin.png';

// Ensure default image exists
if (!file_exists($default_image)) {
    $img = imagecreatetruecolor(100, 100);
    $bg = imagecolorallocate($img, 86, 171, 47); // #56ab2f
    $white = imagecolorallocate($img, 255, 255, 255);
    imagefilledrectangle($img, 0, 0, 100, 100, $bg);
    // Use TTF if available, otherwise simple string
    $font = dirname(__FILE__) . '/fonts/arial.ttf';
    if (file_exists($font)) {
        imagettftext($img, 36, 0, 28, 68, $white, $font, '?');
    } else {
        imagestring($img, 5, 42, 40, '?', $white);
    }
    imagepng($img, $default_image);
    imagedestroy($img);
}

$stmt = $mysqli->prepare("SELECT name, profile_image FROM users WHERE id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$stmt->bind_result($db_name, $db_profile_image);
$stmt->fetch();
$stmt->close();

// CHANGED: Fallback to admin.png if DB is null
$profile_image      = $db_profile_image ?? 'admin.png';
$profile_image_path = $upload_dir . $profile_image;

// If the specific user image is missing, load the default admin.png
if (!file_exists($profile_image_path)) {
    $profile_image_path = $default_image;
}

// Message
$message = '';
if (isset($_GET['msg']) && $_GET['msg'] === 'deleted') {
    $message = "Student account and all their recommendations have been permanently deleted.";
}

// ====================================================================
// DELETE USER WITH SERIOUS WARNING (ONLY PART CHANGED)
// ====================================================================
if (isset($_GET['delete']) && !isset($_GET['force_delete'])) {
    $id = (int)$_GET['delete'];

    if ($id == $admin_id) {
        $message = "You cannot delete your own admin account!";
    } else {
        // Count recommendations
        $count_stmt = $mysqli->prepare("SELECT COUNT(*) FROM recommendations WHERE user_id = ?");
        $count_stmt->bind_param("i", $id);
        $count_stmt->execute();
        $count_stmt->bind_result($rec_count);
        $count_stmt->fetch();
        $count_stmt->close();

        // Get user's name
        $name_stmt = $mysqli->prepare("SELECT name FROM users WHERE id = ?");
        $name_stmt->bind_param("i", $id);
        $name_stmt->execute();
        $name_stmt->bind_result($user_name);
        $name_stmt->fetch();
        $name_stmt->close();

        $name = htmlspecialchars($user_name ?? "This user");

        $warning = $rec_count > 0
            ? "$name has submitted $rec_count recommendation(s)!\\n\\nDELETING THIS ACCOUNT WILL:\\n\\n• Permanently delete the student account\\n• Permanently delete ALL their recommendations\\n• This action CANNOT be undone\\n\\nAre you ABSOLUTELY sure?"
            : "Delete $name permanently?\\n\\nThis action CANNOT be undone.";

        $confirm_url = "?delete=$id&force_delete=1";
        if (!empty($_GET['search'])) $confirm_url .= "&search=" . urlencode($_GET['search']);
        if (!empty($_GET['page'])) $confirm_url .= "&page=" . (int)$_GET['page'];

        echo "<script>
                if (confirm('$warning')) {
                    window.location.href = '$confirm_url&_=" . time() . "';
                } else {
                    window.location.href = 'admin_users.php?_=" . time() . "';
                }
              </script>";
        exit;
    }
}

// ACTUALLY PERFORM DELETION AFTER CONFIRMATION
if (isset($_GET['force_delete']) && isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];

    if ($id == $admin_id) {
        $message = "You cannot delete your own admin account!";
    } else {
        $mysqli->autocommit(false);

        try {
            // Delete recommendations first
            $del_rec = $mysqli->prepare("DELETE FROM recommendations WHERE user_id = ?");
            $del_rec->bind_param("i", $id);
            $del_rec->execute();
            $del_rec->close();

            // Then delete user
            $del_user = $mysqli->prepare("DELETE FROM users WHERE id = ?");
            $del_user->bind_param("i", $id);
            $del_user->execute();

            if ($del_user->affected_rows > 0) {
                $mysqli->commit();
                $redirect = "admin_users.php?msg=deleted&_=" . time();
                if (!empty($_GET['search'])) $redirect .= "&search=" . urlencode($_GET['search']);
                if (!empty($_GET['page'])) $redirect .= "&page=" . (int)$_GET['page'];
                header("Location: $redirect");
                exit;
            } else {
                throw new Exception("User not found.");
            }
        } catch (Exception $e) {
            $mysqli->rollback();
            $message = "Error: Failed to delete user.";
        }
        $mysqli->autocommit(true);
    }
}
// ====================================================================

// === SEARCH & PAGINATION ===
$search = trim($_GET['search'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 10;
$offset = ($page - 1) * $limit;

$whereClause = "WHERE role = 'user'";
$params = [];
$types  = '';

if ($search !== '') {
    $whereClause .= " AND (name LIKE ? OR email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types   .= 'ss';
}

$count_stmt = $mysqli->prepare("SELECT COUNT(*) FROM users $whereClause");
if (!empty($params)) $count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$count_stmt->bind_result($total);
$count_stmt->fetch();
$count_stmt->close();

$pages = ceil($total / $limit);

$query = "SELECT id, name, email, created_at FROM users $whereClause ORDER BY id DESC LIMIT ? OFFSET ?";
$stmt = $mysqli->prepare($query);
$bindParams = array_merge($params, [$limit, $offset]);
$bindTypes = $types . 'ii';
$stmt->bind_param($bindTypes, ...$bindParams);
$stmt->execute();
$users = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - <?= htmlspecialchars(SITE_NAME) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <style>
        *{margin:0;padding:0;box-sizing:border-box;}
        body{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:#f4f7fa;color:#333;line-height:1.6;min-height:100vh;}
        a{text-decoration:none;color:inherit;}
        .header{background:#56ab2f;color:white;padding:1rem 2rem;display:flex;justify-content:space-between;align-items:center;box-shadow:0 2px 10px rgba(0,0,0,.1);position:sticky;top:0;z-index:1000;}
        .logo{display:flex;align-items:center;gap:12px;font-size:1.4rem;font-weight:600;}
        .logo img{width:40px;height:40px;border-radius:50%;border:2px solid rgba(255,255,255,.3);object-fit:cover;}
        .user-profile{display:flex;align-items:center;gap:10px;font-size:.95rem;}
        .user-profile img{width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,.4);}
        .menu-toggle{display:none;background:none;border:none;color:white;font-size:1.5rem;cursor:pointer;}
        .sidebar{width:260px;background:#2c3e50;color:white;height:calc(100vh - 64px);position:fixed;top:64px;left:0;overflow-y:auto;transition:transform .3s;z-index:900;}
        .nav-menu{list-style:none;padding:1.5rem 0;}
        .nav-item{padding:.9rem 1.5rem;display:flex;align-items:center;gap:12px;transition:.3s;cursor:pointer;}
        .nav-item:hover,.nav-item.active{background:#34495e;}
        .nav-item i{width:20px;text-align:center;}
        .main-content{margin-left:260px;padding:2rem;transition:margin-left .3s;}
        .main-content.expanded{margin-left:0;}
        .container{max-width:1100px;margin:0 auto;}
        .page-title{font-size:1.8rem;color:#2c3e50;margin-bottom:1.5rem;display:flex;align-items:center;gap:10px;}
        .card{background:white;border-radius:12px;padding:1.5rem;box-shadow:0 4px 15px rgba(0,0,0,.05);margin-bottom:1.5rem;}
        .search-bar{margin-bottom:1rem;}
        .search-bar input{padding:.7rem 1rem;border:1px solid #ddd;border-radius:8px;width:100%;max-width:400px;font-size:1rem;}
        .table-container{overflow-x:auto;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.05);}
        table{width:100%;border-collapse:collapse;font-size:.95rem;}
        th{background:#56ab2f;color:white;padding:1rem;text-align:left;font-weight:600;}
        td{padding:.9rem 1rem;border-bottom:1px solid #eee;}
        tr:hover{background:#f8fff8;}
        .action-btn{padding:.4rem .8rem;border:none;border-radius:6px;font-size:.85rem;cursor:pointer;font-weight:600;}
        .btn-delete{background:#dc3545;color:white;}
        .btn-delete:hover{background:#c82333;}
        .pagination{margin-top:1.5rem;display:flex;gap:.5rem;justify-content:center;flex-wrap:wrap;}
        .pagination a{padding:.6rem 1rem;border:1px solid #ddd;border-radius:6px;color:#333;font-weight:500;}
        .pagination a:hover{background:#56ab2f;color:white;border-color:#56ab2f;}
        .pagination .current{background:#56ab2f;color:white;border-color:#56ab2f;}
        .message{background:#d4edda;color:#155724;padding:.8rem 1rem;border-radius:8px;margin-bottom:1rem;border:1px solid #c3e6cb;}
        @media (max-width:992px){.sidebar{transform:translateX(-100%);}.sidebar.active{transform:translateX(0);}.main-content{margin-left:0;}.menu-toggle{display:block;}}
        @media (max-width:576px){.header{padding:1rem;}.logo span{display:none;}}
        .overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:800;}
        .overlay.active{display:block;}
        .logout-popup{display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:white;padding:1.5rem 2rem;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.2);z-index:1001;text-align:center;max-width:320px;width:90%;}
        .logout-popup.active{display:block;animation:fadeIn .3s ease-out;}
        .logout-popup h3{margin:0 0 1rem;color:#2c3e50;font-size:1.3rem;}
        .logout-popup p{color:#666;margin-bottom:1.5rem;}
        .logout-btns{display:flex;gap:1rem;justify-content:center;}
        .logout-btns button{padding:.6rem 1.2rem;border:none;border-radius:8px;font-weight:600;cursor:pointer;}
        .logout-btns .yes{background:#dc3545;color:white;}
        .logout-btns .yes:hover{background:#c82333;}
        .logout-btns .no{background:#6c757d;color:white;}
        .logout-btns .no:hover{background:#5a6268;}
        @keyframes fadeIn{from{opacity:0;transform:translate(-50%,-60%)}to{opacity:1;transform:translate(-50%,-50%)}}
    </style>
</head>
<body>

    <header class="header">
        <div class="logo">
            <img src="<?= htmlspecialchars(LOGO) ?>" alt="Logo">
            <span>Admin Panel</span>
        </div>
        <div class="user-profile">
            <img src="<?= htmlspecialchars($profile_image_path) ?>" 
                 onerror="this.onerror=null;this.src='<?= $default_image ?>';">
            <span><?= htmlspecialchars($admin_name) ?></span>
        </div>
        <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    </header>

    <aside class="sidebar" id="sidebar">
        <ul class="nav-menu">
            <li class="nav-item"><a href="admin_dashboard.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a></li>
            <li class="nav-item active"><i class="fas fa-users"></i><span>Manage Users</span></li>
            <li class="nav-item"><a href="admin_courses.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;"><i class="fas fa-book"></i><span>Manage Courses</span></a></li>
            <li class="nav-item"><a href="admin_skills.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;"><i class="fas fa-tools"></i><span>Manage Skills</span></a></li>
            <li class="nav-item"><a href="admin_interests.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;"><i class="fas fa-heart"></i><span>Manage Interests</span></a></li>
            <li class="nav-item"><a href="admin_careers.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;"><i class="fas fa-briefcase"></i><span>Manage Careers</span></a></li>
            <li class="nav-item"><a href="admin_recommendations.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;"><i class="fas fa-chart-line"></i><span>All Recommendations</span></a></li>
            <li class="nav-item">
    <a href="admin_analytics.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;">
        <i class="fas fa-comments"></i>
        <span>User feedbacks</span>
    </a>
</li>
            <li class="nav-item"><a href="#" id="logoutLink" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a></li>
        </ul>
    </aside>

    <div class="overlay" id="overlay"></div>
    <div class="logout-popup" id="logoutPopup">
        <h3>Logout Confirmation</h3>
        <p>Are you sure you want to log out?</p>
        <div class="logout-btns">
            <button class="yes" onclick="window.location.href='admin_logout.php'">Yes</button>
            <button class="no" onclick="window.closeLogoutPopup()">No</button>
        </div>
    </div>

    <main class="main-content" id="mainContent">
        <div class="container">
            <h1 class="page-title"><i class="fas fa-users"></i> Manage Users</h1>

            <?php if ($message): ?>
                <div class="message"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <div class="card">
                <div class="search-bar">
                    <form method="GET">
                        <input type="text" name="search" placeholder="Search users by name or email..." value="<?= htmlspecialchars($search) ?>">
                    </form>
                </div>

                <?php if ($users->num_rows > 0): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Joined</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($u = $users->fetch_assoc()): ?>
                            <tr>
                                <td><?= $u['id'] ?></td>
                                <td><strong><?= htmlspecialchars($u['name']) ?></strong></td>
                                <td><?= htmlspecialchars($u['email']) ?></td>
                                <td><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                                <td>
                                    <a href="?delete=<?= $u['id'] ?>&search=<?= urlencode($search) ?>&page=<?= $page ?>"
                                       class="action-btn btn-delete">
                                       Delete
                                    </a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($pages > 1): ?>
                <div class="pagination">
                    <?php for ($i = 1; $i <= $pages; $i++): ?>
                        <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>"
                           class="<?= $i == $page ? 'current' : '' ?>">
                           <?= $i ?>
                        </a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>

                <?php else: ?>
                    <p style="text-align:center;padding:2rem;color:#95a5a6;">No users found.</p>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
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
            sidebar.classList.remove('active');
            mainContent.classList.remove('expanded');
            logoutPopup.classList.remove('active');
            overlay.classList.remove('active');
        });
    </script>
</body>
</html>

<?php
if (isset($stmt) && $stmt instanceof mysqli_stmt) $stmt->close();
$mysqli->close();
?>