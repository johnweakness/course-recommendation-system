<?php
session_start();
include 'config.php';

// --- ADMIN AUTH CHECK ---
if (!isset($_SESSION['admin_auth']['logged_in']) || !$_SESSION['admin_auth']['logged_in']) {
    header("Location: admin_login.php");
    exit;
}

$admin_id   = $_SESSION['admin_auth']['id'];
$admin_name = $_SESSION['admin_auth']['name'];

// ---------- PROFILE IMAGE LOGIC ----------
$upload_dir    = 'uploads/profile/';
$default_image = 'uploads/profile/avatar.png';

// Ensure default image exists (create placeholder if missing)
if (!file_exists($default_image)) {
    $img = imagecreatetruecolor(100, 100);
    $bg = imagecolorallocate($img, 86, 171, 47);  // #56ab2f
    $white = imagecolorallocate($img, 255, 255, 255);
    imagefilledrectangle($img, 0, 0, 100, 100, $bg);
    $font = dirname(__FILE__) . '/fonts/arial.ttf'; // Ensure font path is correct or use generic
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

$profile_image      = $db_profile_image ?? 'avatar.png';
$profile_image_path = $upload_dir . $profile_image;

if (!file_exists($profile_image_path)) {
    $profile_image_path = $default_image;
}

// 1. FETCH VOTE COUNTS
$query = "SELECT rating, COUNT(*) as count FROM feedback GROUP BY rating";
$result = $mysqli->query($query);

$yes_votes = 0;
$no_votes = 0;

while ($row = $result->fetch_assoc()) {
    if ($row['rating'] == 'yes') $yes_votes = $row['count'];
    if ($row['rating'] == 'no')  $no_votes = $row['count'];
}

$total_votes = $yes_votes + $no_votes;
$accuracy_rate = ($total_votes > 0) ? round(($yes_votes / $total_votes) * 100) : 0;

// 2. FETCH RECENT FEEDBACK LOG (Updated to include comment)
$log_query = "SELECT f.rating, f.comment, f.created_at, u.name 
              FROM feedback f 
              JOIN users u ON f.user_id = u.id 
              ORDER BY f.created_at DESC LIMIT 10";
$log_result = $mysqli->query($log_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics - <?= htmlspecialchars(SITE_NAME) ?></title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:#f4f7fa;color:#333;line-height:1.6;min-height:100vh;}
        a {text-decoration:none;color:inherit;}

        /* Header & Sidebar (Copied from Design) */
        .header{background:#56ab2f;color:white;padding:1rem 2rem;display:flex;justify-content:space-between;align-items:center;box-shadow:0 2px 10px rgba(0,0,0,.1);position:sticky;top:0;z-index:1000;}
        .logo{display:flex;align-items:center;gap:12px;font-size:1.4rem;font-weight:600;}
        .logo img{width:40px;height:40px;border-radius:50%;border:2px solid rgba(255,255,255,.3);object-fit:cover;}
        .user-profile{display:flex;align-items:center;gap:10px;font-size:.95rem;}
        .user-profile img{width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,.4);}
        .menu-toggle{display:none;background:none;border:none;color:white;font-size:1.5rem;cursor:pointer;}

        .sidebar{width:260px;background:#2c3e50;color:white;height:calc(100vh - 64px);position:fixed;top:64px;left:0;overflow-y:auto;transition:transform .3s;z-index:900;}
        .sidebar.collapsed{transform:translateX(-100%);}
        .nav-menu{list-style:none;padding:1.5rem 0;}
        .nav-item{padding:.9rem 1.5rem;display:flex;align-items:center;gap:12px;transition:.3s;cursor:pointer;}
        .nav-item:hover,.nav-item.active{background:#34495e;}
        .nav-item i{width:20px;text-align:center;}

        /* Main Content Layout */
        .main-content{margin-left:260px;padding:2rem;transition:margin-left .3s;}
        .main-content.expanded{margin-left:0;}
        .container{max-width:1100px;margin:0 auto;}
        .page-title{font-size:1.8rem;color:#2c3e50;margin-bottom:1.5rem;display:flex;align-items:center;gap:10px;}

        /* Cards & Components */
        .card{background:white;border-radius:12px;padding:1.5rem;box-shadow:0 4px 15px rgba(0,0,0,.05);margin-bottom:1.5rem;}
        
        /* Specific Analytics Styles */
        .dashboard-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem; }
        .score-card { text-align: center; display: flex; flex-direction: column; justify-content: center; align-items: center; border-left: 5px solid #56ab2f; }
        .big-number { font-size: 4rem; font-weight: bold; color: #56ab2f; margin: 0; line-height: 1; }
        .label { font-size: 1.1rem; color: #7f8c8d; font-weight: 600; margin-top: 10px; }
        .sub-text { margin-top: 5px; font-size: 0.9rem; color: #999; }
        .chart-container { position: relative; height: 250px; width: 100%; display: flex; justify-content: center; }

        /* Tables */
        .table-container { overflow-x: auto; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        table { width: 100%; border-collapse: collapse; font-size: 0.95rem; }
        th { background: #56ab2f; color: white; padding: 1rem; text-align: left; font-weight: 600; }
        td { padding: 0.9rem 1rem; border-bottom: 1px solid #eee; }
        tr:hover { background: #f8fff8; }
        
        .badge { padding: 0.3rem 0.7rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
        .badge-yes { background: #d4edda; color: #155724; }
        .badge-no { background: #f8d7da; color: #721c24; }

        /* Mobile & Utilities */
        @media (max-width:992px){
            .sidebar{transform:translateX(-100%);}
            .sidebar.active{transform:translateX(0);}
            .main-content{margin-left:0;}
            .menu-toggle{display:block;}
        }
        @media (max-width:768px){
            .dashboard-grid { grid-template-columns: 1fr; }
        }
        @media (max-width:576px){
            .header{padding:1rem;}
            .logo span{display:none;}
            th, td { font-size: 0.85rem; padding: 0.7rem; }
        }

        .overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:800;}
        .overlay.active{display:block;}

        /* Logout Popup */
        .logout-popup {
            display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);
            background: white; padding: 1.5rem 2rem; border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2); z-index: 1001; text-align: center; max-width: 320px; width: 90%;
        }
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
                 alt="Admin Profile"
                 onerror="this.onerror=null; this.src='<?= $default_image ?>';">
            <span><?= htmlspecialchars($admin_name) ?></span>
        </div>
        <button class="menu-toggle" id="menuToggle">
            <i class="fas fa-bars"></i>
        </button>
    </header>

    <aside class="sidebar" id="sidebar">
        <ul class="nav-menu">
            <li class="nav-item">
                <a href="admin_dashboard.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;">
                    <i class="fas fa-tachometer-alt"></i><span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="admin_users.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;">
                    <i class="fas fa-users"></i><span>Manage Users</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="admin_courses.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;">
                    <i class="fas fa-book"></i><span>Manage Courses</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="admin_skills.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;">
                    <i class="fas fa-tools"></i><span>Manage Skills</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="admin_interests.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;">
                    <i class="fas fa-heart"></i><span>Manage Interests</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="admin_careers.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;">
                    <i class="fas fa-briefcase"></i><span>Manage Careers</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="admin_recommendations.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;">
                    <i class="fas fa-chart-line"></i><span>All Recommendations</span>
                </a>
            </li>
            <li class="nav-item active">
                <i class="fas fa-comments"></i><span>User feedbacks</span>
            </li>
            <li class="nav-item">
                <a href="#" id="logoutLink" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;">
                    <i class="fas fa-sign-out-alt"></i><span>Logout</span>
                </a>
            </li>
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
            <h1 class="page-title"><i class="fas fa-chart-pie"></i> Recommendation Accuracy Report</h1>

            <div class="dashboard-grid">
                <div class="card score-card">
                    <h3 style="margin-top:0; color:#333;">User Satisfaction Rate</h3>
                    <div class="big-number"><?= $accuracy_rate ?>%</div>
                    <div class="label">Positive Feedback</div>
                    <div class="sub-text">Based on <?= $total_votes ?> total user reviews</div>
                </div>

                <div class="card">
                    <h3 style="margin-top:0; color:#333;">Vote Distribution</h3>
                    <div class="chart-container">
                        <canvas id="accuracyChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="card">
                <h3 class="section-title"><i class="fas fa-history"></i> Recent Validation Logs</h3>
                <?php if ($total_votes > 0): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>User Name</th>
                                    <th>Feedback</th>
                                    <th>Comment</th>
                                    <th>Date & Time</th>
                                </tr>
                            </thead>
                            <tbody>
<?php while($log = $log_result->fetch_assoc()): ?>
    <tr>
        <td><strong><?= htmlspecialchars($log['name']) ?></strong></td>
        <td>
            <?php if($log['rating'] == 'yes'): ?>
                <span class="badge badge-yes"><i class="fas fa-thumbs-up"></i> Helpful</span>
            <?php else: ?>
                <span class="badge badge-no"><i class="fas fa-thumbs-down"></i> Not Helpful</span>
            <?php endif; ?>
        </td>
        <td style="color:#555; font-style:italic;">
            <?= !empty($log['comment']) ? htmlspecialchars($log['comment']) : '<span style="color:#ccc;">No comment</span>' ?>
        </td>
        <td><?= date('M d, Y h:i A', strtotime($log['created_at'])) ?></td>
    </tr>
<?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p style="color:#999; text-align:center; padding: 2rem;">No feedback data collected yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        // --- UI SCRIPTS (Sidebar & Popup) ---
        const menuToggle   = document.getElementById('menuToggle');
        const sidebar      = document.getElementById('sidebar');
        const mainContent  = document.getElementById('mainContent');
        const overlay      = document.getElementById('overlay');
        const logoutLink   = document.getElementById('logoutLink');
        const logoutPopup  = document.getElementById('logoutPopup');

        menuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
            mainContent.classList.toggle('expanded');
        });

        logoutLink.addEventListener('click', (e) => {
            e.preventDefault();
            logoutPopup.classList.add('active');
            overlay.classList.add('active');
        });

        window.closeLogoutPopup = function() {
            logoutPopup.classList.remove('active');
            overlay.classList.remove('active');
        };

        overlay.addEventListener('click', () => {
            if (sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
                mainContent.classList.remove('expanded');
            }
            if (logoutPopup.classList.contains('active')) {
                window.closeLogoutPopup();
            }
            overlay.classList.remove('active');
        });

        window.addEventListener('resize', () => {
            if (window.innerWidth > 992) {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                mainContent.classList.remove('expanded');
            }
        });

        // --- CHART JS ---
        const ctx = document.getElementById('accuracyChart').getContext('2d');
        const accuracyChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Helpful (Yes)', 'Not Helpful (No)'],
                datasets: [{
                    data: [<?= $yes_votes ?>, <?= $no_votes ?>],
                    backgroundColor: [
                        '#56ab2f', // Green
                        '#e74c3c'  // Red
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    </script>
</body>
</html>
<?php
$mysqli->close();
?>