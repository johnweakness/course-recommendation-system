<?php
session_start();
include 'config.php';

// USER AUTH CHECK
if (!isset($_SESSION['user_auth']['logged_in']) || !$_SESSION['user_auth']['logged_in']) {
    header("Location: index.php");
    exit;
}

$user_id    = $_SESSION['user_auth']['id'];
$user_name  = $_SESSION['user_auth']['name'];
$user_role  = $_SESSION['user_auth']['role'];

// ---------- PROFILE IMAGE ----------
$upload_dir = 'uploads/profile/';
$default_image = 'uploads/profile/avatar.png';

$stmt = $mysqli->prepare("SELECT name, profile_image FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($db_name, $db_profile_image);
$stmt->fetch();
$stmt->close();

$profile_image      = $db_profile_image ?? 'avatar.png';
$profile_image_path = $upload_dir . $profile_image;

if (!file_exists($profile_image_path)) {
    $profile_image_path = $default_image;
}

// === HISTORY FETCHING LOGIC ===
$stmt = $mysqli->prepare("
    SELECT 
        id, selected_skills, selected_interests, selected_careers,
        first_choice, second_choice, third_choice,
        score1, score2, score3,
        submitted_at
    FROM recommendations 
    WHERE user_id = ? 
    ORDER BY submitted_at DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$history_records = [];
$all_skill_ids = [];
$all_interest_ids = [];
$all_career_ids = [];

while ($row = $result->fetch_assoc()) {
    $history_records[] = $row;
    if (!empty($row['selected_skills'])) $all_skill_ids = array_merge($all_skill_ids, explode(',', $row['selected_skills']));
    if (!empty($row['selected_interests'])) $all_interest_ids = array_merge($all_interest_ids, explode(',', $row['selected_interests']));
    if (!empty($row['selected_careers'])) $all_career_ids = array_merge($all_career_ids, explode(',', $row['selected_careers']));
}
$result->free();
$stmt->close();

$all_skill_ids    = array_unique(array_filter($all_skill_ids, 'is_numeric'));
$all_interest_ids = array_unique(array_filter($all_interest_ids, 'is_numeric'));
$all_career_ids   = array_unique(array_filter($all_career_ids, 'is_numeric'));

function fetch_names_from_ids($mysqli, $ids, $table) {
    if (empty($ids)) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $mysqli->prepare("SELECT id, description FROM `$table` WHERE id IN ($placeholders)");
    $params = array_merge([$types], $ids);
    $refs = array();
    foreach($params as $key => $value) $refs[$key] = &$params[$key];
    call_user_func_array([$stmt, 'bind_param'], $refs);
    $stmt->execute();
    $res = $stmt->get_result();
    $names_map = [];
    while ($row = $res->fetch_assoc()) {
        $names_map[$row['id']] = $row['description'];
    }
    $stmt->close();
    return $names_map;
}

$skill_name_map    = fetch_names_from_ids($mysqli, $all_skill_ids, 'skills');
$interest_name_map = fetch_names_from_ids($mysqli, $all_interest_ids, 'interests');
$career_name_map   = fetch_names_from_ids($mysqli, $all_career_ids, 'careers');

$latest_submission = !empty($history_records) ? $history_records[0] : null;
$past_submissions  = !empty($history_records) ? array_slice($history_records, 1) : [];

function render_list($ids_str, $map, $icon, $title) {
    $ids = array_filter(array_map('trim', explode(',', $ids_str)));
    $html = '<div class="selection-group"><h4><i class="'.$icon.'"></i> '.$title.'</h4><ul class="selection-list">';
    if (empty($ids)) {
        $html .= '<li><em>None selected</em></li>';
    } else {
        foreach ($ids as $id) {
            if (isset($map[$id])) {
                $html .= '<li>'.htmlspecialchars($map[$id]).'</li>';
            }
        }
    }
    $html .= '</ul></div>';
    return $html;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>History - <?= htmlspecialchars(SITE_NAME) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:#f4f7fa;color:#333;line-height:1.6;min-height:100vh;}
        a {text-decoration:none;color:inherit;}

        /* --- HEADER (MATCHING DASHBOARD) --- */
        .header {
            background:#56ab2f;
            color:white;
            padding:1rem 2rem;
            display:flex;
            justify-content:space-between;
            align-items:center;
            box-shadow:0 2px 10px rgba(0,0,0,.1);
            position:sticky; /* Changed back to sticky to match dashboard */
            top:0;
            z-index:1000;
        }
        .logo{display:flex;align-items:center;gap:12px;font-size:1.4rem;font-weight:600;}
        .logo img{width:40px;height:40px;border-radius:50%;border:2px solid rgba(255,255,255,.3);object-fit:cover;}
        
        .user-profile {
            display:flex; align-items:center; gap:10px; font-size:.95rem;
            position: relative;
            cursor: pointer;
            padding: 5px 10px;
            border-radius: 8px;
            transition: background 0.3s;
        }
        .user-profile:hover { background: rgba(255,255,255,0.1); }
        .user-profile img { width:38px; height:38px; border-radius:50%; object-fit:cover; border:2px solid rgba(255,255,255,.4); }
        
        /* DROPDOWN MENU CSS */
        .profile-dropdown {
            display: none;
            position: absolute;
            top: 120%;
            right: 0;
            background: white;
            color: #333;
            min-width: 160px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
            z-index: 1100;
            overflow: hidden;
            animation: fadeInDropdown 0.2s ease-out;
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

        .menu-toggle{display:none;background:none;border:none;color:white;font-size:1.5rem;cursor:pointer;}

        /* --- SIDEBAR (MATCHING DASHBOARD) --- */
        .sidebar{
            width:260px;
            background:#2c3e50;
            color:white;
            height:calc(100vh - 64px);
            position:fixed;
            top:64px;
            left:0;
            overflow-y:auto;
            transition:transform .3s;
            z-index:900;
        }
        .sidebar.collapsed{transform:translateX(-100%);}
        .nav-menu{list-style:none;padding:1.5rem 0;}
        .nav-item{padding:.9rem 1.5rem;display:flex;align-items:center;gap:12px;transition:.3s;cursor:pointer;}
        .nav-item:hover,.nav-item.active{background:#34495e;}
        .nav-item i{width:20px;text-align:center;}

        /* --- MAIN CONTENT (MATCHING DASHBOARD) --- */
        .main-content{margin-left:260px;padding:2rem;transition:margin-left .3s;}
        .main-content.expanded{margin-left:0;}
        .container{max-width:1000px;margin:0 auto;}
        .page-title{font-size:1.8rem;color:#2c3e50;margin-bottom:1.5rem;display:flex;align-items:center;gap:10px;}

        .card{background:white;border-radius:12px;padding:1.5rem;box-shadow:0 4px 15px rgba(0,0,0,.05);margin-bottom:1.5rem;}

        /* --- HISTORY SPECIFIC STYLES --- */
        .tab-nav { display: flex; gap: 1rem; margin-bottom: 1.5rem; border-bottom: 2px solid #eee; padding-bottom: 0; }
        .tab-btn {
            background: none; border: none; padding: 1rem 1.5rem; font-size: 1rem; font-weight: 600; color: #7f8c8d; cursor: pointer; transition: all 0.3s;
            border-bottom: 3px solid transparent; margin-bottom: -2px;
        }
        .tab-btn:hover { color: #56ab2f; }
        .tab-btn.active { color: #56ab2f; border-bottom-color: #56ab2f; }
        .tab-content { display: none; animation: fadeIn 0.3s ease-out; }
        .tab-content.active { display: block; }

        .history-card { background: white; border: 1px solid #e0e0e0; border-radius: 10px; margin-bottom: 1rem; overflow: hidden; transition: all 0.2s; }
        .history-card:hover { border-color: #56ab2f; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .history-summary { padding: 1.2rem; display: flex; align-items: center; justify-content: space-between; cursor: pointer; background: #fdfdfd; }
        .history-summary:hover { background: #f8fff8; }
        .summary-info { display: flex; flex-direction: column; gap: 4px; }
        .summary-date { font-size: 0.85rem; color: #7f8c8d; font-weight: 500; display: flex; align-items: center; gap: 6px; }
        .summary-top-pick { font-size: 1.1rem; font-weight: 700; color: #2c3e50; }
        .summary-badge { background: #e8f5e8; color: #56ab2f; padding: 2px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; margin-left: 8px; }
        .toggle-icon { color: #ccc; transition: transform 0.3s; font-size: 1.2rem; }
        .history-card.open .toggle-icon { transform: rotate(180deg); color: #56ab2f; }

        .history-details { display: none; padding: 1.5rem; border-top: 1px dashed #e0e0e0; background: #fff; }
        .history-card.open .history-details { display: block; animation: slideDown 0.3s ease-out; }

        .selections { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem; font-size: 0.95rem; }
        .selection-group h4 { color: #56ab2f; margin-bottom: 0.6rem; font-size: 1rem; display: flex; align-items: center; gap: 8px; }
        .selection-list { list-style: none; padding-left: 0.5rem; color: #555; }
        .selection-list li { margin: 0.3rem 0; padding-left: 15px; position: relative; }
        .selection-list li:before { content: '•'; color: #56ab2f; font-weight: bold; position: absolute; left: 0; }

        .rec-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; }
        .mini-rec { background: #f9f9f9; padding: 1rem; border-radius: 8px; text-align: center; border: 1px solid #eee; }
        .mini-rank { font-weight: bold; color: #56ab2f; display: block; margin-bottom: 5px; }
        .mini-score { font-size: 0.8rem; background: #56ab2f; color: white; padding: 2px 8px; border-radius: 10px; }

        .no-data { text-align: center; padding: 3rem; color: #95a5a6; }
        .btn-group { margin-top: 1.5rem; display: flex; gap: 10px; }
        .btn { padding: 0.8rem 1.5rem; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; font-size: .95rem; }
        .btn-primary { background: #56ab2f; color: white; }
        .btn-primary:hover { background: #468f24; }

        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }

        @media (max-width:992px){
            .sidebar{transform:translateX(-100%);}
            .sidebar.active{transform:translateX(0);}
            .main-content{margin-left:0;}
            .menu-toggle{display:block;}
        }
        @media (max-width:576px){
            .header{padding:1rem;}
            .logo span{display:none;}
            .selections { grid-template-columns: 1fr; }
            .history-summary { flex-direction: column; align-items: flex-start; gap: 10px; }
            .toggle-icon { align-self: flex-end; margin-top: -30px; }
        }

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
    </style>
</head>
<body>

    <header class="header">
        <div class="logo">
            <img src="<?= htmlspecialchars(LOGO) ?>" alt="Logo">
            <span>Course Recommendation System</span>
        </div>
        <div class="user-profile" id="userProfileTrigger">
            <img src="<?= htmlspecialchars($profile_image_path) ?>" alt="Profile" onerror="this.src='uploads/profile/avatar.png'">
            <span><?= htmlspecialchars($user_name) ?></span>
            <div class="profile-dropdown" id="profileDropdown">
                <a href="profile.php"><i class="fas fa-user"></i> Profile</a>
            </div>
        </div>
        <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    </header>

    <aside class="sidebar" id="sidebar">
        <ul class="nav-menu">
            <li class="nav-item">
                <a href="dashboard.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;">
                    <i class="fas fa-tachometer-alt"></i><span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="recommendations.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;">
                    <i class="fas fa-lightbulb"></i><span>Get Recommendation</span>
                </a>
            </li>
            <li class="nav-item active">
                <i class="fas fa-history"></i><span>History</span>
            </li>
            <li class="nav-item">
                <a href="logout_process.php" id="logoutLink" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;">
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
            <button class="yes" onclick="window.location.href='logout_process.php'">Yes</button>
            <button class="no" onclick="window.closeLogoutPopup()">No</button>
        </div>
    </div>

    <main class="main-content" id="mainContent">
        <div class="container">
            <h1 class="page-title"><i class="fas fa-history"></i> Recommendation History</h1>

            <div class="card" style="padding: 0; overflow: hidden; background: transparent; box-shadow: none;">
                
                <div class="tab-nav">
                    <button class="tab-btn active" onclick="switchTab('latest')">
                        <i class="fas fa-star"></i> Latest Submission
                    </button>
                    <button class="tab-btn" onclick="switchTab('past')">
                        <i class="fas fa-archive"></i> Past History
                    </button>
                </div>

                <div id="latest" class="tab-content active">
                    <?php if ($latest_submission): ?>
                        <div class="card">
                            <h3 class="section-title" style="border-bottom: 2px solid #56ab2f; padding-bottom: 0.5rem; display: inline-block; margin-bottom: 1rem;">Most Recent Result <small style="color:#777; font-size:0.9rem;">(<?= date('M j, Y - g:i A', strtotime($latest_submission['submitted_at'])) ?>)</small></h3>
                            
                            <div class="selections">
                                <?= render_list($latest_submission['selected_skills'], $skill_name_map, 'fa-tools', 'Skills Used') ?>
                                <?= render_list($latest_submission['selected_interests'], $interest_name_map, 'fa-heart', 'Interests') ?>
                                <?= render_list($latest_submission['selected_careers'], $career_name_map, 'fa-briefcase', 'Career Goals') ?>
                            </div>

                            <h4 style="color:#2c3e50; margin-bottom:10px;">Top Recommended Courses:</h4>
                            <div class="rec-grid">
                                <div class="mini-rec" style="border-color:#56ab2f; background:#f8fff8;">
                                    <span class="mini-rank">#1 Best Match</span>
                                    <div style="font-size:1.1rem; font-weight:600; margin-bottom:5px;"><?= htmlspecialchars($latest_submission['first_choice']) ?></div>
                                    <span class="mini-score">Score: <?= $latest_submission['score1'] ?></span>
                                </div>
                                <div class="mini-rec">
                                    <span class="mini-rank" style="color:#f39c12;">#2 Option</span>
                                    <div style="font-weight:600; margin-bottom:5px;"><?= htmlspecialchars($latest_submission['second_choice']) ?></div>
                                    <span class="mini-score" style="background:#f39c12;">Score: <?= $latest_submission['score2'] ?></span>
                                </div>
                                <div class="mini-rec">
                                    <span class="mini-rank" style="color:#7f8c8d;">#3 Option</span>
                                    <div style="font-weight:600; margin-bottom:5px;"><?= htmlspecialchars($latest_submission['third_choice']) ?></div>
                                    <span class="mini-score" style="background:#7f8c8d;">Score: <?= $latest_submission['score3'] ?></span>
                                </div>
                            </div>

                            <div class="btn-group">
                                <a href="recommendations.php" class="btn btn-primary">
                                    <i class="fas fa-redo"></i> Get new recommendations
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="card no-data">
                            <i class="fas fa-ghost" style="font-size:3rem; color:#ddd; margin-bottom:1rem;"></i>
                            <p>You haven't submitted any data yet.</p>
                            <a href="recommendations.php" class="btn btn-primary" style="margin-top:1rem;">Get Started</a>
                        </div>
                    <?php endif; ?>
                </div>

                <div id="past" class="tab-content">
                    <?php if (!empty($past_submissions)): ?>
                        <?php foreach ($past_submissions as $row): ?>
                            <div class="history-card">
                                <div class="history-summary" onclick="toggleDetails(this)">
                                    <div class="summary-info">
                                        <div class="summary-date">
                                            <i class="far fa-calendar-alt"></i> 
                                            <?= date('M j, Y', strtotime($row['submitted_at'])) ?>
                                            at <?= date('g:i A', strtotime($row['submitted_at'])) ?>
                                        </div>
                                        <div class="summary-top-pick">
                                            <?= htmlspecialchars($row['first_choice']) ?>
                                            <span class="summary-badge">Score: <?= $row['score1'] ?></span>
                                        </div>
                                    </div>
                                    <div class="toggle-icon">
                                        <i class="fas fa-chevron-down"></i>
                                    </div>
                                </div>

                                <div class="history-details">
                                    <h5 style="color:#2c3e50; margin-bottom:1rem; border-bottom:1px solid #eee; padding-bottom:5px;">Full Submission Details</h5>
                                    
                                    <div class="selections">
                                        <?= render_list($row['selected_skills'], $skill_name_map, 'fa-tools', 'Skills') ?>
                                        <?= render_list($row['selected_interests'], $interest_name_map, 'fa-heart', 'Interests') ?>
                                        <?= render_list($row['selected_careers'], $career_name_map, 'fa-briefcase', 'Goals') ?>
                                    </div>

                                    <h5 style="color:#2c3e50; margin-bottom:10px;">Other Recommendations in this run:</h5>
                                    <ul style="list-style:none; color:#555;">
                                        <li><strong>2nd:</strong> <?= htmlspecialchars($row['second_choice']) ?> (<?= $row['score2'] ?>)</li>
                                        <li><strong>3rd:</strong> <?= htmlspecialchars($row['third_choice']) ?> (<?= $row['score3'] ?>)</li>
                                    </ul>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="card no-data">
                            <p>No past history available yet (records move here after your next submission).</p>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </main>

    <script>
        // Tab Switching Logic
        function switchTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
            document.getElementById(tabName).classList.add('active');
            
            const buttons = document.querySelectorAll('.tab-btn');
            if(tabName === 'latest') { buttons[0].classList.add('active'); }
            else { buttons[1].classList.add('active'); }
        }

        // Accordion Toggle Logic
        function toggleDetails(element) {
            const card = element.closest('.history-card');
            card.classList.toggle('open');
        }

        // --- DASHBOARD JS LOGIC ---
        const menuToggle   = document.getElementById('menuToggle');
        const sidebar      = document.getElementById('sidebar');
        const mainContent  = document.getElementById('mainContent');
        const overlay      = document.getElementById('overlay');
        const logoutLink   = document.getElementById('logoutLink');
        const logoutPopup  = document.getElementById('logoutPopup');
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
            if (profileDropdown.classList.contains('active')) {
                profileDropdown.classList.remove('active');
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
    </script>
</body>
</html>
<?php $mysqli->close(); ?>