<?php
include 'config.php';

// ADMIN AUTH CHECK - COMPLETELY INDEPENDENT
if (!isset($_SESSION['admin_auth']['logged_in']) || !$_SESSION['admin_auth']['logged_in']) {
    header("Location: admin_login.php");
    exit;
}

// --- EXPORT LOGIC (CSV) ---
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=recommendations_report_' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, array('ID', 'Student Name', 'Email', '1st Choice', '2nd Choice', '3rd Choice', 'Submitted At'));
    
    $export_query = "SELECT r.id, u.name, u.email, r.first_choice, r.second_choice, r.third_choice, r.submitted_at 
                     FROM recommendations r JOIN users u ON r.user_id = u.id ORDER BY r.submitted_at DESC";
    $result = $mysqli->query($export_query);
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

$admin_id   = $_SESSION['admin_auth']['id'];
$admin_name = $_SESSION['admin_auth']['name'];

if (!$admin_id || !$admin_name) {
    session_destroy();
    header("Location: admin_login.php");
    exit;
}

// ---------- PROFILE IMAGE ----------
$upload_dir    = 'uploads/profile/';
$default_image = 'uploads/profile/avatar.png';

if (!file_exists($default_image)) {
    $img = imagecreatetruecolor(100, 100);
    $bg = imagecolorallocate($img, 86, 171, 47);
    $white = imagecolorallocate($img, 255, 255, 255);
    imagefilledrectangle($img, 0, 0, 100, 100, $bg);
    imagestring($img, 5, 42, 40, '?', $white);
    imagepng($img, $default_image);
    imagedestroy($img);
}

$stmt = $mysqli->prepare("SELECT name, profile_image FROM users WHERE id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$stmt->bind_result($db_name, $db_profile_image);
$stmt->fetch();
$stmt->close();

$profile_image_path = $upload_dir . ($db_profile_image ?? 'avatar.png');
if (!file_exists($profile_image_path)) $profile_image_path = $default_image;

// === FETCH ALL MASTER COURSES FOR FILTER ===
$course_list_query = $mysqli->query("SELECT name FROM courses ORDER BY name ASC");

// === PAGINATION & FETCH WITH SEARCH + FILTER ===
$search = trim($_GET['q'] ?? '');
$filter_course = trim($_GET['course'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

$query_base = "FROM recommendations r JOIN users u ON r.user_id = u.id WHERE 1=1";
$params = [];
$types = "";

if ($search !== '') {
    $query_base .= " AND (u.name LIKE ? OR u.email LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param; $params[] = $search_param;
    $types .= "ss";
}
if ($filter_course !== '') {
    $query_base .= " AND r.first_choice = ?";
    $params[] = $filter_course;
    $types .= "s";
}

$t_stmt = $mysqli->prepare("SELECT COUNT(*) $query_base");
if ($types) $t_stmt->bind_param($types, ...$params);
$t_stmt->execute();
$total_recs = $t_stmt->get_result()->fetch_row()[0];
$t_stmt->close();

$main_query = "SELECT r.id, r.first_choice, r.second_choice, r.third_choice, r.submitted_at, u.name, u.email $query_base ORDER BY r.submitted_at DESC LIMIT ? OFFSET ?";
$stmt = $mysqli->prepare($main_query);
$types .= "ii";
$params[] = $limit; $params[] = $offset;
$stmt->bind_param($types, ...$params);
$stmt->execute();
$recs = $stmt->get_result();
$pages = ceil($total_recs / $limit);

// --- ANALYSIS LOGIC ---
$total_count = $mysqli->query("SELECT COUNT(*) FROM recommendations")->fetch_row()[0];
$student_count = $mysqli->query("SELECT COUNT(DISTINCT user_id) FROM recommendations")->fetch_row()[0];

// Data for Chart and Summary
$distribution = $mysqli->query("SELECT first_choice, COUNT(*) as qty FROM recommendations GROUP BY first_choice ORDER BY qty DESC");
$chart_labels = [];
$chart_values = [];
while($d = $distribution->fetch_assoc()) {
    $chart_labels[] = $d['first_choice'];
    $chart_values[] = $d['qty'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics - <?= date('Y-m-d') ?></title>
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
        .nav-menu{list-style:none;padding:1.5rem 0;}
        .nav-item{padding:.9rem 1.5rem;display:flex;align-items:center;gap:12px;transition:.3s;cursor:pointer;}
        .nav-item:hover,.nav-item.active{background:#34495e;}
        .nav-item i{width:20px;text-align:center;}

        .main-content{margin-left:260px;padding:2rem;transition:margin-left .3s;}
        .main-content.expanded{margin-left:0;}
        .container{max-width:1200px;margin:0 auto;}
        
        .title-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem; }
        .page-title{font-size:1.8rem;color:#2c3e50;display:flex;align-items:center;gap:10px;margin-bottom: 0;}
        
        .action-bar { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .search-form { display: flex; background: white; border-radius: 8px; overflow: hidden; border: 1px solid #ddd; align-items: center; }
        .search-form input, .search-form select { border: none; padding: 0.6rem 1rem; outline: none; }
        .search-form select { border-left: 1px solid #eee; background: #fff; cursor: pointer; max-width: 250px; }
        .search-form button { background: #56ab2f; color: white; border: none; padding: 0 1.2rem; cursor: pointer; height: 38px; }
        
        .reset-btn { background: #f1f2f6; color: #747d8c; border-left: 1px solid #eee; padding: 0 1rem; height: 38px; display: flex; align-items: center; transition: 0.2s; }
        .reset-btn:hover { background: #dfe4ea; color: #2f3542; }

        .btn-group { display: flex; gap: 5px; }
        .export-btn { background: #2c3e50; color: white; padding: 0.6rem 1rem; border-radius: 8px; font-weight: 600; display: flex; align-items: center; gap: 6px; transition: 0.3s; height: 38px; font-size: 0.85rem; }
        .export-btn.pdf { background: #e74c3c; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
        .stat-card { background: white; padding: 1.5rem; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,.05); border-left: 5px solid #56ab2f; }
        .stat-card h3 { font-size: 0.85rem; color: #7f8c8d; text-transform: uppercase; margin-bottom: 1rem; border-bottom: 1px solid #f0f0f0; padding-bottom: 5px; }
        .stat-card .value { font-size: 1.8rem; font-weight: 700; color: #2c3e50; }
        
        /* Analysis Card Specifics */
        .analysis-container { display: flex; gap: 20px; align-items: flex-start; margin-top: 10px; }
        .chart-box { width: 140px; height: 140px; }
        .dist-table { flex: 1; font-size: 0.85rem; border-collapse: collapse; }
        .dist-table td { padding: 5px 0; border-bottom: 1px dashed #eee; }
        .dist-count { font-weight: bold; color: #56ab2f; text-align: right; }

        .card{background:white;border-radius:12px;padding:1.5rem;box-shadow:0 4px 15px rgba(0,0,0,.05);margin-bottom:1.5rem;}
        .table-container{overflow-x:auto;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.05);}
        table{width:100%;border-collapse:collapse;font-size:.95rem;}
        th{background:#56ab2f;color:white;padding:1rem;text-align:left;font-weight:600;}
        td{padding:.9rem 1rem;border-bottom:1px solid #eee;}
        tr:hover{background:#f8fff8;}

        .badge-success{background:#d4edda;color:#155724;padding:.35rem .75rem;border-radius:20px;font-size:.8rem;font-weight:600;}

        .pagination{display:flex;justify-content:center;gap:.5rem;margin-top:1.5rem;flex-wrap:wrap;}
        .pagination a{padding:.6rem 1rem;background:white;color:#56ab2f;border:1px solid #56ab2f;border-radius:8px;font-weight:600;}
        .pagination a.current{background:#56ab2f;color:white;cursor:default;}

        @media (max-width:992px){.sidebar{transform:translateX(-100%);}.sidebar.active{transform:translateX(0);}.main-content{margin-left:0;}.menu-toggle{display:block;}}
        .overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:800;}
        .overlay.active{display:block;}

        @media print {
            .header, .sidebar, .action-bar, .pagination, .menu-toggle, .overlay, .logout-popup, .chart-box { display: none !important; }
            .main-content { margin: 0 !important; padding: 0 !important; width: 100% !important; display: block !important; }
            .table-container { overflow: visible !important; }
            table { width: 100% !important; table-layout: fixed !important; }
            th, td { font-size: 9pt !important; word-wrap: break-word !important; }
            .stat-card { break-inside: avoid; border: 1px solid #eee !important; margin-bottom: 10px; }
        }

        .logout-popup {display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 1.5rem 2rem; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); z-index: 1001; text-align: center; max-width: 320px; width: 90%;}
        .logout-popup.active { display: block; animation: fadeIn 0.3s ease-out; }
        .logout-btns { display: flex; gap: 1rem; justify-content: center; margin-top: 1.5rem; }
        .logout-btns button { padding: 0.6rem 1.2rem; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: 0.2s; min-width: 80px; }
        .logout-btns .yes { background: #dc3545; color: white; }
        .logout-btns .no { background: #6c757d; color: white; }
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
            <img src="<?= htmlspecialchars($profile_image_path) ?>" alt="Admin Profile" onerror="this.onerror=null; this.src='<?= $default_image ?>';">
            <span><?= htmlspecialchars($admin_name) ?></span>
        </div>
        <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    </header>

    <aside class="sidebar" id="sidebar">
        <ul class="nav-menu">
            <li class="nav-item"><a href="admin_dashboard.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a></li>
            <li class="nav-item"><a href="admin_users.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;"><i class="fas fa-users"></i><span>Manage Users</span></a></li>
            <li class="nav-item"><a href="admin_courses.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;"><i class="fas fa-book"></i><span>Manage Courses</span></a></li>
            <li class="nav-item"><a href="admin_skills.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;"><i class="fas fa-tools"></i><span>Manage Skills</span></a></li>
            <li class="nav-item"><a href="admin_interests.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;"><i class="fas fa-heart"></i><span>Manage Interests</span></a></li>
            <li class="nav-item"><a href="admin_careers.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;"><i class="fas fa-briefcase"></i><span>Manage Careers</span></a></li>
            <li class="nav-item active"><i class="fas fa-chart-line"></i><span>All Recommendations</span></li>
            <li class="nav-item"><a href="admin_analytics.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;"><i class="fas fa-comments"></i><span>User feedbacks</span></a></li>
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
            <div class="title-row">
                <h1 class="page-title"><i class="fas fa-chart-line"></i> All Recommendations</h1>
                
                <div class="action-bar">
                    <form class="search-form" method="GET">
                        <input type="text" name="q" placeholder="Student Name/Email..." value="<?= htmlspecialchars($search) ?>">
                        
                        <select name="course">
                            <option value="">All Available Courses</option>
                            <?php 
                            $course_list_query->data_seek(0);
                            while($cl = $course_list_query->fetch_assoc()): 
                            ?>
                                <option value="<?= htmlspecialchars($cl['name']) ?>" <?= $filter_course == $cl['name'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cl['name']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                        
                        <button type="submit" title="Search"><i class="fas fa-search"></i></button>
                        <a href="?" class="reset-btn" title="Clear All Filters"><i class="fas fa-undo"></i></a>
                    </form>
                    <div class="btn-group">
                        <a href="?export=csv" class="export-btn"><i class="fas fa-file-csv"></i> CSV</a>
                        <button onclick="window.print()" class="export-btn pdf"><i class="fas fa-file-pdf"></i> PDF</button>
                    </div>
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <h3>Engagement Overview</h3>
                    <div style="display:flex; justify-content:space-between; align-items:center; height: 140px;">
                        <div>
                            <div class="value"><?= number_format($total_count) ?></div>
                            <div style="font-size:0.8rem; color:#7f8c8d;">Total Recommendations</div>
                        </div>
                        <div style="text-align:right;">
                            <div class="value"><?= number_format($student_count) ?></div>
                            <div style="font-size:0.8rem; color:#7f8c8d;">Unique Students</div>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <h3>Course Selection Distribution</h3>
                    <div class="analysis-container">
                        <div class="chart-box">
                            <canvas id="distributionChart"></canvas>
                        </div>
                        <table class="dist-table">
                            <?php 
                            for($i=0; $i < min(4, count($chart_labels)); $i++):
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($chart_labels[$i]) ?></td>
                                <td class="dist-count"><?= $chart_values[$i] ?></td>
                            </tr>
                            <?php endfor; ?>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>User</th>
                                <th>Email</th>
                                <th>1st Choice</th>
                                <th>2nd Choice</th>
                                <th>3rd Choice</th>
                                <th>Submitted</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recs->num_rows > 0): ?>
                                <?php while ($r = $recs->fetch_assoc()): ?>
                                <tr>
                                    <td><?= $r['id'] ?></td>
                                    <td><strong><?= htmlspecialchars($r['name']) ?></strong></td>
                                    <td><?= htmlspecialchars($r['email']) ?></td>
                                    <td><span class="badge-success"><?= htmlspecialchars($r['first_choice']) ?></span></td>
                                    <td><?= htmlspecialchars($r['second_choice']) ?></td>
                                    <td><?= htmlspecialchars($r['third_choice']) ?></td>
                                    <td><?= date('M j, Y g:i A', strtotime($r['submitted_at'])) ?></td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="7" style="text-align:center;color:#95a5a6;padding:2rem;font-style:italic;">No results found. Try adjusting your filters.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($pages > 1): ?>
                <div class="pagination">
                    <?php for ($i = 1; $i <= $pages; $i++): ?>
                        <a href="?page=<?= $i ?>&q=<?= urlencode($search) ?>&course=<?= urlencode($filter_course) ?>" class="<?= $i == $page ? 'current' : '' ?>"><?= $i ?></a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        // CHART LOGIC
        const ctx = document.getElementById('distributionChart').getContext('2d');
        new Chart(ctx, {
            type: 'pie',
            data: {
                labels: <?= json_encode(array_slice($chart_labels, 0, 5)) ?>,
                datasets: [{
                    data: <?= json_encode(array_slice($chart_values, 0, 5)) ?>,
                    backgroundColor: ['#56ab2f', '#2c3e50', '#8e44ad', '#e67e22', '#16a085'],
                    borderWidth: 0
                }]
            },
            options: {
                plugins: { legend: { display: false } },
                maintainAspectRatio: false
            }
        });

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

        logoutLink.addEventListener('click', (e) => {
            e.preventDefault();
            logoutPopup.classList.add('active');
            overlay.classList.add('active');
        });

        window.closeLogoutPopup = () => {
            logoutPopup.classList.remove('active');
            overlay.classList.remove('active');
        };

        overlay.addEventListener('click', () => {
            if (sidebar.classList.contains('active')) { sidebar.classList.remove('active'); mainContent.classList.remove('expanded'); }
            if (logoutPopup.classList.contains('active')) window.closeLogoutPopup();
            overlay.classList.remove('active');
        });
    </script>
</body>
</html>
<?php
$stmt->close();
$mysqli->close();
?>