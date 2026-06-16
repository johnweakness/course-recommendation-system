<?php
session_start();
include 'config.php';

// ADMIN AUTH CHECK - COMPLETELY INDEPENDENT
if (!isset($_SESSION['admin_auth']['logged_in']) || !$_SESSION['admin_auth']['logged_in']) {
    header("Location: admin_login.php");
    exit;
}

$admin_id   = $_SESSION['admin_auth']['id'];
$admin_name = $_SESSION['admin_auth']['name'];

if (!$admin_id || !$admin_name) {
    session_destroy();
    header("Location: admin_login.php");
    exit;
}

// ---------- PROFILE IMAGE LOGIC UPDATED ----------
$upload_dir    = 'uploads/profile/';
$default_image = 'uploads/profile/admin.png'; 

if (!file_exists($default_image)) {
    $img = imagecreatetruecolor(100, 100);
    $bg = imagecolorallocate($img, 86, 171, 47);  // #56ab2f
    $white = imagecolorallocate($img, 255, 255, 255);
    imagefilledrectangle($img, 0, 0, 100, 100, $bg);
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

$profile_image      = $db_profile_image ?? 'admin.png'; 
$profile_image_path = $upload_dir . $profile_image;

if (!file_exists($profile_image_path)) {
    $profile_image_path = $default_image;
}

// ---------- ADMIN STATS ----------
$total_users = 0;
$total_recommendations = 0;
$total_courses = 0;
$total_skills = 0;
$total_interests = 0;
$total_careers = 0;
$recent_recs = [];
$top_courses = []; 

// 1. Basic Counts
$queries = [
    "SELECT COUNT(*) FROM users WHERE role = 'user'" => &$total_users,
    "SELECT COUNT(*) FROM recommendations" => &$total_recommendations,
    "SELECT COUNT(*) FROM courses" => &$total_courses,
    "SELECT COUNT(*) FROM skills" => &$total_skills,
    "SELECT COUNT(*) FROM interests" => &$total_interests,
    "SELECT COUNT(*) FROM careers" => &$total_careers
];

foreach ($queries as $sql => &$var) {
    $stmt = $mysqli->prepare($sql);
    $stmt->execute();
    $stmt->bind_result($var);
    $stmt->fetch();
    $stmt->close();
}

// 2. Recent 5 Recommendations
$stmt = $mysqli->prepare("
    SELECT r.first_choice, r.second_choice, r.third_choice, r.submitted_at, u.name 
    FROM recommendations r
    JOIN users u ON r.user_id = u.id
    ORDER BY r.submitted_at DESC
    LIMIT 5
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $recent_recs[] = $row;
}
$stmt->close();

// 3. FIXED: TOP 3 RECOMMENDED COURSES
$stmt = $mysqli->prepare("
    SELECT TRIM(all_choices.course) AS course, COUNT(*) AS cnt, c.college_code
    FROM (
        SELECT first_choice AS course FROM recommendations WHERE first_choice != ''
        UNION ALL
        SELECT second_choice AS course FROM recommendations WHERE second_choice != ''
        UNION ALL
        SELECT third_choice AS course FROM recommendations WHERE third_choice != ''
    ) AS all_choices
    LEFT JOIN courses c ON TRIM(all_choices.course) = TRIM(c.name)
    GROUP BY course, c.college_code
    ORDER BY cnt DESC
    LIMIT 3
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $top_courses[] = $row;
}
$stmt->close();

// Prepare data for JS Charts
$chart_values = [(int)$total_users, (int)$total_recommendations, (int)$total_courses, (int)$total_skills, (int)$total_interests, (int)$total_careers];
$course_labels = [];
$course_counts = [];
foreach($top_courses as $tc) {
    $course_labels[] = $tc['course'];
    $course_counts[] = (int)$tc['cnt'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - <?= htmlspecialchars(SITE_NAME) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:#f4f7fa;color:#333;line-height:1.6;min-height:100vh;}
        a {text-decoration:none;color:inherit;}

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

        .main-content{margin-left:260px;padding:2rem;transition:margin-left .3s;}
        .main-content.expanded{margin-left:0;}
        .container{max-width:1100px;margin:0 auto;}
        .page-title{font-size:1.8rem;color:#2c3e50;margin-bottom:1.5rem;display:flex;align-items:center;gap:10px;}

        .card{background:white;border-radius:12px;padding:1.5rem;box-shadow:0 4px 15px rgba(0,0,0,.05);margin-bottom:1.5rem;}
        .welcome-card h2{color:#56ab2f;margin-bottom:.5rem;}
        .welcome-card p{color:#7f8c8d;}

        .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-top:1rem;}
        .stat-box{background:#f8fff8;border-left:5px solid #56ab2f;padding:1rem;border-radius:8px;text-align:center;transition:transform .2s; height: 100%; display: block;}
        .stat-box:hover{transform:translateY(-3px);box-shadow:0 4px 12px rgba(86,171,47,.15);}
        .stat-value{font-size:1.8rem;font-weight:700;color:#56ab2f;margin:.3rem 0;}
        .stat-label{font-size:.9rem;color:#7f8c8d;}

        /* FIXED CHART CONTAINER SIZE */
        .visual-container { display: grid; grid-template-columns: 1.5fr 1fr; gap: 1.5rem; margin-top: 2rem; align-items: stretch; }
        .chart-wrapper { 
            background: #fff; 
            padding: 1rem; 
            border-radius: 10px; 
            border: 1px solid #eee; 
            position: relative; 
            height: 260px; /* Reduced and fixed height */
            width: 100%;
        }
        .chart-wrapper h5 { margin-bottom: 0.5rem; color: #2c3e50; font-size: 0.95rem; text-align: center; }

        .ranking-grid {
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); 
            gap: 1.5rem; 
            margin-top: 1.5rem;
        }
        .rank-card {
            background: linear-gradient(145deg, #ffffff, #f0f0f0);
            border-radius: 10px;
            padding: 1rem;
            display: flex;
            align-items: center;
            gap: 15px;
            border: 1px solid #eee;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            position: relative;
            overflow: hidden;
            transition: transform 0.2s;
        }
        .rank-card:hover { transform: translateY(-3px); box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .rank-badge {
            width: 40px; height: 40px;
            background: #56ab2f; color: white;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.2rem; font-weight: bold;
            flex-shrink: 0;
        }
        .rank-1 .rank-badge { background: #FFD700; color: #fff; text-shadow: 0 1px 2px rgba(0,0,0,0.2); } 
        .rank-2 .rank-badge { background: #C0C0C0; color: #fff; text-shadow: 0 1px 2px rgba(0,0,0,0.2); } 
        .rank-3 .rank-badge { background: #CD7F32; color: #fff; text-shadow: 0 1px 2px rgba(0,0,0,0.2); } 
        
        .rank-info { flex: 1; }
        .rank-info h4 { margin: 0; font-size: 1rem; color: #333; line-height: 1.3; }
        .rank-college { font-size: 0.8rem; color: #56ab2f; font-weight: 600; margin-top: 2px; }
        .rank-count { font-size: 0.85rem; color: #7f8c8d; margin-top: 4px; display: flex; align-items: center; gap: 5px; }

        .table-container { overflow-x: auto; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        table { width: 100%; border-collapse: collapse; font-size: 0.95rem; }
        th { background: #56ab2f; color: white; padding: 1rem; text-align: left; font-weight: 600; }
        td { padding: 0.9rem 1rem; border-bottom: 1px solid #eee; }
        tr:hover { background: #f8fff8; }
        .badge { padding: 0.3rem 0.7rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
        .badge-success { background: #d4edda; color: #155724; }

        .btn-group{display:flex;gap:1rem;margin-top:1.5rem;flex-wrap:wrap;}
        .btn{padding:.8rem 1.5rem;border:none;border-radius:8px;font-weight:600;cursor:pointer;transition:.3s;display:inline-flex;align-items:center;gap:8px;font-size:.95rem;}
        .btn-primary{background:#56ab2f;color:white;}
        .btn-primary:hover{background:#468f24;transform:translateY(-1px);}
        .btn-secondary{background:#34495e;color:white;}
        .btn-secondary:hover{background:#2c3e50;}

        @media (max-width:992px){
            .sidebar{transform:translateX(-100%);}
            .sidebar.active{transform:translateX(0);}
            .main-content{margin-left:0;}
            .menu-toggle{display:block;}
            .visual-container { grid-template-columns: 1fr; }
        }
        .overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:800;}
        .overlay.active{display:block;}

        .logout-popup { display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 1.5rem 2rem; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); z-index: 1001; text-align: center; max-width: 320px; width: 90%; }
        .logout-popup.active { display: block; animation: fadeIn 0.3s ease-out; }
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
            <li class="nav-item active">
                <i class="fas fa-tachometer-alt"></i><span>Dashboard</span>
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
            <li class="nav-item">
                <a href="admin_analytics.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;">
                    <i class="fas fa-comments"></i>
                    <span>User feedbacks</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="admin_login.php" id="logoutLink" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;">
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
            <h1 class="page-title"><i class="fas fa-shield-alt"></i> Admin Dashboard</h1>

            <div class="card welcome-card">
                <h2>Welcome back, <strong><?= htmlspecialchars($admin_name) ?>!</strong></h2>
                <p>Monitor and manage the Course Recommendation System.</p>
            </div>

            <div class="card">
                <h3 class="section-title"><i class="fas fa-chart-pie"></i> System Overview</h3>
                <div class="stats-grid">
                    <a href="admin_users.php" class="stat-box">
                        <div class="stat-value"><?= $total_users ?></div>
                        <div class="stat-label">Total Users</div>
                    </a>
                    <a href="admin_recommendations.php" class="stat-box">
                        <div class="stat-value"><?= $total_recommendations ?></div>
                        <div class="stat-label">Recommendations</div>
                    </a>
                    <a href="admin_courses.php" class="stat-box">
                        <div class="stat-value"><?= $total_courses ?></div>
                        <div class="stat-label">Courses</div>
                    </a>
                    <a href="admin_skills.php" class="stat-box">
                        <div class="stat-value"><?= $total_skills ?></div>
                        <div class="stat-label">Skills</div>
                    </a>
                    <a href="admin_interests.php" class="stat-box">
                        <div class="stat-value"><?= $total_interests ?></div>
                        <div class="stat-label">Interests</div>
                    </a>
                    <a href="admin_careers.php" class="stat-box">
                        <div class="stat-value"><?= $total_careers ?></div>
                        <div class="stat-label">Career Goals</div>
                    </a>
                </div>

                <div class="visual-container">
                    <div class="chart-wrapper">
                        <h5><i class="fas fa-chart-bar"></i> System Totals Distribution</h5>
                        <canvas id="overviewChart"></canvas>
                    </div>
                    <div class="chart-wrapper">
                        <h5><i class="fas fa-chart-pie"></i> Popular Course Share</h5>
                        <canvas id="courseRatioChart"></canvas>
                    </div>
                </div>

                <div style="margin-top: 2.5rem; border-top: 2px solid #eee; padding-top: 1.5rem;">
                    <h4 style="color:#56ab2f; font-size:1.2rem; margin-bottom:1rem; display:flex; align-items:center; gap:8px;">
                        <i class="fas fa-trophy"></i> Most Popular Recommendations
                    </h4>
                    
                    <?php if (!empty($top_courses)): ?>
                        <div class="ranking-grid">
                            <?php $rank = 1; foreach ($top_courses as $top): ?>
                                <a href="admin_courses.php" class="rank-card rank-<?= $rank ?>">
                                    <div class="rank-badge">#<?= $rank ?></div>
                                    <div class="rank-info">
                                        <h4><?= htmlspecialchars($top['course']) ?></h4>
                                        <div class="rank-college"><i class="fas fa-university"></i> <?= htmlspecialchars($top['college_code'] ?? 'Unknown College') ?></div>
                                        <div class="rank-count"><i class="fas fa-check-circle"></i> Recommended <strong><?= $top['cnt'] ?></strong> times</div>
                                    </div>
                                </a>
                            <?php $rank++; endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p style="color:#999; font-style:italic;">No recommendation data available yet.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <h3 class="section-title"><i class="fas fa-history"></i> Recent Recommendations</h3>
                <?php if (!empty($recent_recs)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>1st Choice</th>
                                    <th>2nd Choice</th>
                                    <th>3rd Choice</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_recs as $rec): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($rec['name']) ?></strong></td>
                                        <td><span class="badge badge-success"><?= htmlspecialchars($rec['first_choice']) ?></span></td>
                                        <td><?= htmlspecialchars($rec['second_choice']) ?></td>
                                        <td><?= htmlspecialchars($rec['third_choice']) ?></td>
                                        <td><?= date('M j, Y', strtotime($rec['submitted_at'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="btn-group">
                        <a href="admin_recommendations.php" class="btn btn-primary">
                            <i class="fas fa-list"></i> View All Recommendations
                        </a>
                    </div>
                <?php else: ?>
                    <p style="color:#95a5a6;text-align:center;padding:1.5rem;">No recommendations yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // SIDEBAR LOGIC
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

            // CHART INITIALIZATION
            const overviewCtx = document.getElementById('overviewChart');
            const ratioCtx    = document.getElementById('courseRatioChart');

            if (overviewCtx) {
                new Chart(overviewCtx, {
                    type: 'bar',
                    data: {
                        labels: ['Users', 'Recs', 'Courses', 'Skills', 'Interests', 'Careers'],
                        datasets: [{
                            label: 'Totals',
                            data: <?= json_encode($chart_values) ?>,
                            backgroundColor: 'rgba(86, 171, 47, 0.6)',
                            borderColor: '#56ab2f',
                            borderWidth: 1,
                            borderRadius: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: { y: { beginAtZero: true, grid: { display: false } } }
                    }
                });
            }

            if (ratioCtx) {
                const labels = <?= json_encode($course_labels) ?>;
                if (labels.length > 0) {
                    new Chart(ratioCtx, {
                        type: 'doughnut',
                        data: {
                            labels: labels,
                            datasets: [{
                                data: <?= json_encode($course_counts) ?>,
                                backgroundColor: ['#FFD700', '#C0C0C0', '#CD7F32'],
                                borderWidth: 2,
                                borderColor: '#ffffff'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } }
                            },
                            cutout: '60%'
                        }
                    });
                } else {
                    ratioCtx.parentElement.innerHTML += '<p style="text-align:center; color:#999; margin-top:50px;">No data to display</p>';
                }
            }
        });

        window.addEventListener('resize', () => {
            if (window.innerWidth > 992) {
                document.getElementById('sidebar').classList.remove('active');
                document.getElementById('overlay').classList.remove('active');
                document.getElementById('mainContent').classList.remove('expanded');
            }
        });
    </script>
</body>
</html>
<?php
$mysqli->close();
?>