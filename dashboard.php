<?php
session_start();
include 'config.php';

// USER AUTH CHECK - ONLY CHECKS USER NAMESPACE
if (!isset($_SESSION['user_auth']['logged_in']) || !$_SESSION['user_auth']['logged_in']) {
    header("Location: index.php");
    exit;
}

$user_id    = $_SESSION['user_auth']['id'];
$user_name  = $_SESSION['user_auth']['name'];
$user_email = $_SESSION['user_auth']['email'];
$user_role  = $_SESSION['user_auth']['role']; // 'user' only
$is_admin   = false; // optional, or remove

// ---------- PROFILE IMAGE ----------
$upload_dir    = 'uploads/profile/';
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

// --- NEW: FETCH RECENT TOP 3 RECOMMENDATIONS ---
$recent_rec = null;
$stmt = $mysqli->prepare("SELECT first_choice, second_choice, third_choice, submitted_at FROM recommendations WHERE user_id = ? ORDER BY submitted_at DESC LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows > 0) {
    $recent_rec = $res->fetch_assoc();
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?= htmlspecialchars(SITE_NAME) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:#f4f7fa;color:#333;line-height:1.6;min-height:100vh;}
        a {text-decoration:none;color:inherit;}

        .header{background:#56ab2f;color:white;padding:1rem 2rem;display:flex;justify-content:space-between;align-items:center;box-shadow:0 2px 10px rgba(0,0,0,.1);position:sticky;top:0;z-index:1000;}
        .logo{display:flex;align-items:center;gap:12px;font-size:1.4rem;font-weight:600;}
        .logo img{width:40px;height:40px;border-radius:50%;border:2px solid rgba(255,255,255,.3);object-fit:cover;}
        
        /* UPDATED USER PROFILE STYLES FOR DROPDOWN */
        .user-profile {
            display:flex; align-items:center; gap:10px; font-size:.95rem;
            position: relative; /* Essential for dropdown positioning */
            cursor: pointer;
            padding: 5px 10px;
            border-radius: 8px;
            transition: background 0.3s;
        }
        .user-profile:hover {
            background: rgba(255,255,255,0.1);
        }
        .user-profile img { width:38px; height:38px; border-radius:50%; object-fit:cover; border:2px solid rgba(255,255,255,.4); }
        
        /* DROPDOWN MENU CSS */
        .profile-dropdown {
            display: none;
            position: absolute;
            top: 120%; /* Push it down slightly */
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
        .profile-dropdown.active {
            display: block;
        }
        .profile-dropdown a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            text-decoration: none;
            font-size: 0.95rem;
            color: #2c3e50;
            transition: background 0.2s;
        }
        .profile-dropdown a:hover {
            background: #f4f7fa;
            color: #56ab2f;
        }
        .profile-dropdown i {
            width: 20px;
            text-align: center;
            color: #7f8c8d;
        }
        .profile-dropdown a:hover i {
            color: #56ab2f;
        }
        @keyframes fadeInDropdown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .menu-toggle{display:none;background:none;border:none;color:white;font-size:1.5rem;cursor:pointer;}

        .sidebar{width:260px;background:#2c3e50;color:white;height:calc(100vh - 64px);position:fixed;top:64px;left:0;overflow-y:auto;transition:transform .3s;z-index:900;}
        .sidebar.collapsed{transform:translateX(-100%);}
        .nav-menu{list-style:none;padding:1.5rem 0;}
        .nav-item{padding:.9rem 1.5rem;display:flex;align-items:center;gap:12px;transition:.3s;cursor:pointer;}
        .nav-item:hover,.nav-item.active{background:#34495e;}
        .nav-item i{width:20px;text-align:center;}

        .main-content{margin-left:260px;padding:2rem;transition:margin-left .3s;}
        .main-content.expanded{margin-left:0;}
        .container{max-width:1000px;margin:0 auto;}
        .page-title{font-size:1.8rem;color:#2c3e50;margin-bottom:1.5rem;display:flex;align-items:center;gap:10px;}

        .card{background:white;border-radius:12px;padding:1.5rem;box-shadow:0 4px 15px rgba(0,0,0,.05);margin-bottom:1.5rem;}
        .welcome-card h2{color:#56ab2f;margin-bottom:.5rem;}
        .welcome-card p{color:#7f8c8d;}

        /* Recommendation Highlights */
        .rec-highlight-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        .rec-box {
            background: #fff;
            border: 1px solid #e0e0e0;
            padding: 1.2rem;
            border-radius: 10px;
            text-align: center;
            transition: 0.3s;
            position: relative;
        }
        .rec-box:hover { transform: translateY(-3px); box-shadow: 0 5px 15px rgba(0,0,0,0.08); border-color: #56ab2f; }
        .rec-box i { font-size: 1.5rem; margin-bottom: 8px; }
        .rec-box.gold i { color: #FFD700; }
        .rec-box.silver i { color: #C0C0C0; }
        .rec-box.bronze i { color: #CD7F32; }
        .rec-box h4 { font-size: 0.95rem; color: #2c3e50; margin: 5px 0; font-weight: 700; }
        .rec-box p { font-size: 0.8rem; color: #7f8c8d; text-transform: uppercase; letter-spacing: 0.5px; }

        .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-top:1rem;}
        .stat-box{background:#f8fff8;border-left:5px solid #56ab2f;padding:1rem;border-radius:8px;text-align:center;transition:transform .2s;}
        .stat-box:hover{transform:translateY(-3px);box-shadow:0 4px 12px rgba(86,171,47,.15);}
        .stat-value{font-size:1.8rem;font-weight:700;color:#56ab2f;margin:.3rem 0;}
        .stat-label{font-size:.9rem;color:#7f8c8d;}

        .btn-group{display:flex;gap:1rem;margin-top:1.5rem;flex-wrap:wrap;}
        .btn{padding:.8rem 1.5rem;border:none;border-radius:8px;font-weight:600;cursor:pointer;transition:.3s;display:inline-flex;align-items:center;gap:8px;font-size:.95rem;}
        .btn-primary{background:#56ab2f;color:white;}
        .btn-primary:hover{background:#468f24;transform:translateY(-1px);}
        .btn-secondary{background:#34495e;color:white;}
        .btn-secondary:hover{background:#2c3e50;}
        .btn i{font-size:1.1rem;}

        @media (max-width:992px){
            .sidebar{transform:translateX(-100%);}
            .sidebar.active{transform:translateX(0);}
            .main-content{margin-left:0;}
            .menu-toggle{display:block;}
        }
        @media (max-width:576px){
            .header{padding:1rem;}
            .logo span{display:none;}
            .btn-group{flex-direction:column;}
            .btn{width:100%;justify-content:center;}
        }
        .overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:800;}
        .overlay.active{display:block;}

        .logout-popup {
            display: none;
            position: fixed;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            padding: 1.5rem 2rem;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            z-index: 1001;
            text-align: center;
            max-width: 320px;
            width: 90%;
        }
        .logout-popup.active { display: block; animation: fadeIn 0.3s ease-out; }
        .logout-popup h3 { margin: 0 0 1rem; color: #2c3e50; font-size: 1.3rem; }
        .logout-popup p { color: #666; margin-bottom: 1.5rem; font-size: 0.95rem; }
        .logout-btns { display: flex; gap: 1rem; justify-content: center; }
        .logout-btns button {
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: 0.2s;
            min-width: 80px;
        }
        .logout-btns .yes {
            background: #dc3545; color: white;
        }
        .logout-btns .yes:hover { background: #c82333; }
        .logout-btns .no {
            background: #6c757d; color: white;
        }
        .logout-btns .no:hover { background: #5a6268; }
        @keyframes fadeIn {
            from { opacity: 0; transform: translate(-50%, -60%); }
            to { opacity: 1; transform: translate(-50%, -50%); }
        }

        /* Courses Grid */
        .courses-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        /* FULL GREEN CARD - FIXED */
        .college-card {
            background: linear-gradient(135deg, #56ab2f, #468f24) !important;
            color: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 3px 10px rgba(0,0,0,.08);
            transition: all .3s ease;
            cursor: pointer;
            border: none;
        }

        .college-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(0,0,0,.12);
            background: linear-gradient(135deg, #468f24, #3a7d1e) !important;
        }

        .college-header {
            padding: 1.2rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .college-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .college-logo {
            width: 45px;
            height: 45px;
            background: rgba(255,255,255,.25);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            border: 2px solid rgba(255,255,255,.3);
        }

        .college-logo img {
            width: 100%;
            height: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .college-logo .placeholder {
            font-size: 1.2rem;
            font-weight: bold;
            color: rgba(255,255,255,.8);
        }

        .college-info h4 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 600;
        }

        .college-info p {
            margin: 4px 0 0;
            font-size: .88rem;
            opacity: 0.9;
        }

        .course-count {
            font-size: .8rem;
            background: rgba(255,255,255,.2);
            padding: 2px 8px;
            border-radius: 12px;
            margin-top: 6px;
            display: inline-block;
        }

        @media (max-width: 768px) {
            .courses-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Course Message Overlay */
        .course-message {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            width: 90%;
            max-width: 500px;
            max-height: 70vh;
            overflow-y: auto;
            border-radius: 16px;
            box-shadow: 0 15px 40px rgba(0,0,0,.2);
            z-index: 1002;
            padding: 1.5rem;
            animation: popIn 0.3s ease-out;
        }

        .course-message.active {
            display: block;
        }

        .course-message-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: .8rem;
            border-bottom: 1px solid #eee;
        }

        .course-message-header h3 {
            margin: 0;
            color: #2c3e50;
            font-size: 1.4rem;
            font-weight: 600;
        }

        .close-message {
            background: none;
            border: none;
            font-size: 1.4rem;
            color: #7f8c8d;
            cursor: pointer;
            padding: 4px;
            border-radius: 50%;
            transition: .2s;
        }

        .close-message:hover {
            background: #f1f1f1;
            color: #e74c3c;
        }

        .course-message ul {
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .course-message li {
            padding: .6rem 0;
            border-bottom: 1px dashed #ddd;
            font-size: .98rem;
            color: #2c3e50;
        }

        .course-message li:last-child {
            border-bottom: none;
        }

        .no-courses {
            color: #95a5a6;
            font-style: italic;
            text-align: center;
            padding: 1rem 0;
            font-size: .95rem;
        }

        @keyframes popIn {
            from { opacity: 0; transform: translate(-50%, -60%) scale(0.9); }
            to { opacity: 1; transform: translate(-50%, -50%) scale(1); }
        }
    </style>
</head>
<body>

    <header class="header">
        <div class="logo">
            <img src="<?= htmlspecialchars(LOGO) ?>" alt="Logo">
            <span>Course Recommendation System</span>
        </div>
        
        <div class="user-profile" id="userProfileTrigger">
            <img src="<?= htmlspecialchars($profile_image_path) ?>" alt="Profile"
                 onerror="this.src='uploads/profile/avatar.png'">
            <span><?= htmlspecialchars($user_name) ?></span>
            
            <div class="profile-dropdown" id="profileDropdown">
                <a href="profile.php"><i class="fas fa-user"></i> Profile</a>
            </div>
        </div>

        <button class="menu-toggle" id="menuToggle">
            <i class="fas fa-bars"></i>
        </button>
    </header>

    <aside class="sidebar" id="sidebar">
        <ul class="nav-menu">
            <li class="nav-item active"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></li>
            <li class="nav-item">
                <a href="recommendations.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;">
                    <i class="fas fa-lightbulb"></i><span>Get Recommendation</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="history.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;">
                    <i class="fas fa-history"></i><span>History</span>
                </a>
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
            <button class="yes" onclick="window.location.href='index.php'">Yes</button>
            <button class="no" onclick="window.closeLogoutPopup()">No</button>
        </div>
    </div>

    <div class="course-message" id="courseMessage">
        <div class="course-message-header">
            <h3 id="messageTitle"></h3>
            <button class="close-message" id="closeMessage">×</button>
        </div>
        <div id="messageContent"></div>
    </div>

    <main class="main-content" id="mainContent">
        <div class="container">
            <h1 class="page-title"><i class="fas fa-tachometer-alt"></i> User Dashboard</h1>

            <div class="card welcome-card">
                <h2>Welcome back, <strong><?= htmlspecialchars($user_name) ?>!</strong></h2>
            </div>

            <?php if ($recent_rec): ?>
            <div class="card">
                <h3 class="section-title"><i class="fas fa-medal"></i> Your Top 3 Recommendations</h3>
                <p>Based on your last test on <?= date('M d, Y', strtotime($recent_rec['submitted_at'])) ?></p>
                
                <div class="rec-highlight-grid">
                    <div class="rec-box gold">
                        <p>#1 Priority</p>
                        <i class="fas fa-trophy"></i>
                        <h4><?= htmlspecialchars($recent_rec['first_choice']) ?></h4>
                    </div>
                    <div class="rec-box silver">
                        <p>#2 Choice</p>
                        <i class="fas fa-medal"></i>
                        <h4><?= htmlspecialchars($recent_rec['second_choice']) ?></h4>
                    </div>
                    <div class="rec-box bronze">
                        <p>#3 Choice</p>
                        <i class="fas fa-award"></i>
                        <h4><?= htmlspecialchars($recent_rec['third_choice']) ?></h4>
                    </div>
                </div>
                <div style="margin-top: 1.5rem; text-align: right;">
                    <a href="history.php" class="btn btn-outline" style="border: 2px solid #56ab2f; color: #56ab2f; padding: 8px 16px; border-radius: 8px; font-weight: 600;">
                        View Details <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>
            <?php else: ?>
            <div class="card" style="border-left: 5px solid #3498db;">
                <h3 class="section-title" style="border-bottom-color: #3498db;"><i class="fas fa-rocket"></i> Get Started!</h3>
                <p>You haven't taken a recommendation test yet. Find out which course fits your skills and interests!</p>
                <div style="margin-top: 1rem;">
                    <a href="recommendations.php" class="btn btn-primary">Take Test Now <i class="fas fa-play"></i></a>
                </div>
            </div>
            <?php endif; ?>

            <div class="card">
                <h3 class="section-title"><i class="fas fa-graduation-cap"></i> Courses Offered</h3>
                <p>Click on a college to view available programs.</p>

                <div class="courses-grid" id="coursesGrid">
                    <?php
                    // Define base colleges with custom logo paths
                    $colleges = [
                        'CCMADI' => ['name' => 'College of Computing and Multimedia Arts', 'logo' => 'ccmadi.png'],
                        'CED'    => ['name' => 'College of Education', 'logo' => 'ced.png'],
                        'CBA'    => ['name' => 'College of Business Administration', 'logo' => 'cba.png'],
                        'CAS'    => ['name' => 'College of Arts and Sciences', 'logo' => 'cas.png'],
                        'CET'    => ['name' => 'College of Engineering and Technology', 'logo' => 'cet.png']
                    ];

                    $logo_dir = 'uploads/logos/';

                    // Fetch and group courses
                    $stmt = $mysqli->prepare("SELECT name, college_code FROM courses ORDER BY college_code, name");
                    $stmt->execute();
                    $result = $stmt->get_result();

                    $grouped = [];
                    while ($row = $result->fetch_assoc()) {
                        $code = $row['college_code'];
                        $base = ($code === 'BMMA') ? 'CCMADI' : explode('-', $code)[0];
                        $grouped[$base][] = $row['name'];
                    }
                    $stmt->close();

                    foreach ($colleges as $base => $info) {
                        $courses = $grouped[$base] ?? [];
                        $count   = count($courses);
                        $logo_path = $logo_dir . $info['logo'];
                        $has_logo = file_exists($logo_path);
                        ?>
                        <div class="college-card" data-code="<?= $base ?>" data-courses="<?= htmlspecialchars(json_encode($courses), ENT_QUOTES) ?>">
                            <div class="college-header">
                                <div class="college-info">
                                    <div class="college-logo">
                                        <?php if ($has_logo): ?>
                                            <img src="<?= htmlspecialchars($logo_path) ?>" alt="<?= $base ?> Logo">
                                        <?php else: ?>
                                            <div class="placeholder"><?= strtoupper(substr($base, 0, 2)) ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <h4><?= $base ?></h4>
                                        <p><?= htmlspecialchars($info['name']) ?></p>
                                        <span class="course-count"><?= $count ?> program<?= $count !== 1 ? 's' : '' ?></span>
                                    </div>
                                </div>
                                <i class="fas fa-info-circle" style="font-size:1.1rem;"></i>
                            </div>
                        </div>
                        <?php
                    }
                    ?>
                </div>
            </div>
        </div>
    </main>

    <script>
        const menuToggle   = document.getElementById('menuToggle');
        const sidebar      = document.getElementById('sidebar');
        const mainContent  = document.getElementById('mainContent');
        const overlay      = document.getElementById('overlay');
        const logoutLink   = document.getElementById('logoutLink');
        const logoutPopup  = document.getElementById('logoutPopup');
        const courseMessage = document.getElementById('courseMessage');
        const messageTitle = document.getElementById('messageTitle');
        const messageContent = document.getElementById('messageContent');
        const closeMessage = document.getElementById('closeMessage');
        
        // ADDED: Profile Dropdown Elements
        const userProfileTrigger = document.getElementById('userProfileTrigger');
        const profileDropdown = document.getElementById('profileDropdown');

        // ADDED: Profile Dropdown Toggle Logic
        userProfileTrigger.addEventListener('click', (e) => {
            e.stopPropagation(); // Prevent bubbling so document click doesn't close it immediately
            profileDropdown.classList.toggle('active');
        });

        // ADDED: Global click to close dropdown
        document.addEventListener('click', (e) => {
            if (!userProfileTrigger.contains(e.target)) {
                profileDropdown.classList.remove('active');
            }
        });

        // Mobile menu toggle
        menuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
            mainContent.classList.toggle('expanded');
        });

        // Show logout popup
        logoutLink.addEventListener('click', (e) => {
            e.preventDefault();
            logoutPopup.classList.add('active');
            overlay.classList.add('active');
        });

        // Global close function
        window.closeLogoutPopup = function() {
            logoutPopup.classList.remove('active');
            overlay.classList.remove('active');
        };

        // Close message
        closeMessage.addEventListener('click', () => {
            courseMessage.classList.remove('active');
            overlay.classList.remove('active');
        });

        // Click college card → show message
        document.querySelectorAll('.college-card').forEach(card => {
            card.addEventListener('click', () => {
                const courses = JSON.parse(card.getAttribute('data-courses') || '[]');
                const fullName = card.querySelector('.college-info p').textContent.trim();

                messageTitle.textContent = fullName;
                messageContent.innerHTML = '';

                if (courses.length > 0) {
                    const ul = document.createElement('ul');
                    courses.forEach(course => {
                        const li = document.createElement('li');
                        li.textContent = course;
                        ul.appendChild(li);
                    });
                    messageContent.appendChild(ul);
                } else {
                    messageContent.innerHTML = '<p class="no-courses">No programs listed yet.</p>';
                }

                courseMessage.classList.add('active');
                overlay.classList.add('active');
            });
        });

        // SINGLE overlay handler
        overlay.addEventListener('click', () => {
            if (sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
                mainContent.classList.remove('expanded');
            }
            if (logoutPopup.classList.contains('active')) {
                window.closeLogoutPopup();
            }
            if (courseMessage.classList.contains('active')) {
                courseMessage.classList.remove('active');
            }
            overlay.classList.remove('active');
        });

        // Auto-collapse on resize
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
<?php
$mysqli->close();
?>