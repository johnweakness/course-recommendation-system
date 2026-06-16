<?php
include 'config.php';

// --- SCROLL POSITION FIX ---
$scroll_position = (isset($_POST['scroll_pos'])) ? (int)$_POST['scroll_pos'] : 0;
if (isset($_GET['scroll_pos'])) {
    $scroll_position = (int)$_GET['scroll_pos'];
}
$message = '';

// ADMIN AUTH CHECK
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

$upload_dir     = 'uploads/profile/';
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

// Process POST requests
if (isset($_POST['action'])) {
    $current_scroll = (int)($_POST['scroll_pos'] ?? 0);

    if ($_POST['action'] === 'add') {
        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['code'] ?? '');
        if (strtoupper($name) === 'BACHELOR OF MULTIMEDIA ARTS') {
            $code = 'CCMADI';
        }
        $required_skills   = trim($_POST['required_skills'] ?? ''); 
        $related_interests = trim($_POST['related_interests'] ?? '');
        $leading_careers   = trim($_POST['leading_careers'] ?? '');

        if ($name && $code) {
            $stmt = $mysqli->prepare("INSERT INTO courses (name, college_code, required_skills, related_interests, leading_careers) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $name, $code, $required_skills, $related_interests, $leading_careers);
            $stmt->execute();
            $stmt->close();
            $message = "Course added successfully!";
        }
    }

    if ($_POST['action'] === 'edit') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['code'] ?? '');
        if ($id && $name && $code) {
            $stmt = $mysqli->prepare("UPDATE courses SET name = ?, college_code = ? WHERE id = ?");
            $stmt->bind_param("ssi", $name, $code, $id);
            $stmt->execute();
            $stmt->close();
            $message = "Course Name and Code updated successfully!";
        }
    }
    
    if ($_POST['action'] === 'update_attributes') {
        $id                 = (int)($_POST['id'] ?? 0);
        $required_skills   = trim($_POST['required_skills'] ?? '');
        $related_interests = trim($_POST['related_interests'] ?? '');
        $leading_careers   = trim($_POST['leading_careers'] ?? '');

        if ($id) {
            $stmt = $mysqli->prepare("UPDATE courses SET required_skills = ?, related_interests = ?, leading_careers = ? WHERE id = ?");
            $stmt->bind_param("sssi", $required_skills, $related_interests, $leading_careers, $id);
            $stmt->execute();
            $stmt->close();
            $message = "Course attributes updated successfully!";
        }
    }
    
    if ($message) {
        header("Location: admin_courses.php?message=" . urlencode($message) . "&scroll_pos=" . $current_scroll);
        exit;
    }
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $scroll_pos_on_delete = (int)($_GET['scroll_pos'] ?? 0);
    $stmt = $mysqli->prepare("DELETE FROM courses WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    header("Location: admin_courses.php?scroll_pos=" . $scroll_pos_on_delete);
    exit;
}

if (isset($_GET['message'])) {
    $message = htmlspecialchars($_GET['message']);
}

$result = $mysqli->query("SELECT id, name, college_code, required_skills, related_interests, leading_careers FROM courses ORDER BY college_code ASC, name ASC");

$grouped_courses = [];
if ($result) {
    while ($c = $result->fetch_assoc()) {
        $code = $c['college_code'] ? strtoupper($c['college_code']) : 'UNCATEGORIZED';
        if (!isset($grouped_courses[$code])) {
            $grouped_courses[$code] = [];
        }
        $grouped_courses[$code][] = $c;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Courses - <?= htmlspecialchars(SITE_NAME) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
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
        
        /* --- NEW TABLE SCROLLING LOGIC --- */
        .table-container {
            /* This height constraint forces the scrollbar to appear on screen */
            max-height: 75vh; 
            overflow: auto; /* Handles both X and Y scrolling */
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,.05);
            border: 1px solid #eee;
        }
        
        table {
            width: 100%;
            /* Ensure table respects width but expands if needed */
            white-space: nowrap; 
            border-collapse: separate; /* Required for sticky headers to work well */
            border-spacing: 0;
            font-size: .95rem;
        }

        /* Sticky Main Header */
        thead th {
            background: #56ab2f;
            color: white;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 20; /* High z-index to sit on top of content */
            border-bottom: 2px solid #468f24;
        }

        td {
            padding: .9rem 1rem;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
            background: #fff; /* Ensure bg is opaque when scrolling */
        }
        tr:hover td { background: #f8fff8; }

        /* College Header (Sub-header) */
        .college-header th {
            background: #2c3e50;
            color: white;
            padding: .75rem 1rem;
            font-size: 1.1em;
            text-align: left;
            /* This header scrolls with content, it is not sticky to avoid complex overlapping */
        }

        /* ACTIONS COLUMN - STICKY RIGHT */
        th:last-child {
            position: sticky;
            right: 0;
            z-index: 25; /* Higher than normal headers */
            box-shadow: -2px 0 5px rgba(0,0,0,0.1);
        }
        td:last-child {
            position: sticky;
            right: 0;
            z-index: 10;
            box-shadow: -2px 0 5px rgba(0,0,0,0.05);
            background: #fff;
        }
        tr:hover td:last-child { background: #f8fff8; }

        /* --- BUTTON STYLES (Retained from previous fix) --- */
        .action-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            min-width: 170px;
            align-items: stretch;
        }

        .action-btn {
            padding: 8px 12px;
            border: none;
            border-radius: 6px;
            font-size: 0.85rem;
            cursor: pointer;
            font-weight: 600;
            text-align: center;
            text-decoration: none;
            transition: all 0.2s;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
            display: block;
        }

        /* Edit: Richer Gold/Yellow BG, Very Dark Text */
        .btn-edit { background: #ffd700; color: #3e2700; }
        .btn-edit:hover { background: #ffc107; transform: translateY(-1px); }

        /* Attributes: Richer Sky Blue BG, Very Dark Text */
        .btn-attributes { background: #87CEEB; color: #002b36; }
        .btn-attributes:hover { background: #4fc3f7; transform: translateY(-1px); }

        /* Delete: Richer Salmon Red BG, Very Dark Text */
        .btn-delete { background: #ff8a80; color: #4c1111; }
        .btn-delete:hover { background: #ff5252; transform: translateY(-1px); }

        .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.5rem;align-items:end;}
        .form-grid input{padding:.7rem 1rem;border:1px solid #ddd;border-radius:8px;font-size:.95rem;}
        .form-grid button{background:#56ab2f;color:white;padding:.7rem 1.2rem;border:none;border-radius:8px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:8px;}
        .form-grid button:hover{background:#468f24;}
        .span-2{grid-column:1/-1;}

        .message{background:#d4edda;color:#155724;padding:.8rem 1rem;border-radius:8px;margin-bottom:1rem;font-weight:500;}

        .search-section{margin:2rem 0 1.2rem;padding-top:1.5rem;border-top:1px solid #eee;}
        .search-box{position:relative;max-width:520px;}
        .search-box i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#777;font-size:1.1rem;}
        .search-box input{width:100%;padding:.95rem 1rem .95rem 2.9rem;border:1px solid #ddd;border-radius:12px;font-size:1rem;background:#fdfdfd;transition:all .3s;}
        .search-box input:focus{outline:none;border-color:#56ab2f;box-shadow:0 0 0 4px rgba(86,171,47,0.15);}
        .search-count{margin-top:.5rem;font-size:.9rem;color:#555;font-style:italic;}

        @media (max-width:992px){
            .sidebar{transform:translateX(-100%);}
            .sidebar.active{transform:translateX(0);}
            .main-content{margin-left:0;}
            .menu-toggle{display:block;}
        }
        @media (max-width:576px){
            .header{padding:1rem;}
            .logo span{display:none;}
            .form-grid{grid-template-columns:1fr;}
            .span-2{grid-column:auto;}
            .form-grid button{width:100%;justify-content:center;}
        }

        .overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:800;}
        .overlay.active{display:block;}
        .logout-popup{display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:white;padding:1.5rem 2rem;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,0.2);z-index:1001;text-align:center;max-width:320px;width:90%;}
        .logout-popup.active{display:block;animation:fadeIn .3s ease-out;}
        .logout-popup h3{margin:0 0 1rem;color:#2c3e50;font-size:1.3rem;}
        .logout-popup p{color:#666;margin-bottom:1.5rem;font-size:.95rem;}
        .logout-btns{display:flex;gap:1rem;justify-content:center;}
        .logout-btns button{padding:.6rem 1.2rem;border:none;border-radius:8px;font-weight:600;cursor:pointer;transition:.2s;min-width:80px;}
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
            <img src="<?= htmlspecialchars($profile_image_path) ?>" alt="Admin Profile" onerror="this.onerror=null;this.src='<?= $default_image ?>';">
            <span><?= htmlspecialchars($admin_name) ?></span>
        </div>
        <button class="menu-toggle" id="menuToggle">
            <i class="fas fa-bars"></i>
        </button>
    </header>

    <aside class="sidebar" id="sidebar">
        <ul class="nav-menu">
            <li class="nav-item"><a href="admin_dashboard.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a></li>
            <li class="nav-item"><a href="admin_users.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;"><i class="fas fa-users"></i><span>Manage Users</span></a></li>
            <li class="nav-item active"><a href="admin_courses.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;"><i class="fas fa-book"></i><span>Manage Courses</span></a></li>
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
            <h1 class="page-title"><i class="fas fa-book"></i> Manage Courses</h1>

            <?php if ($message): ?>
                <div class="message"><?= $message ?></div>
            <?php endif; ?>

            <div class="card">
                <h3 style="margin-bottom:1rem;color:#56ab2f;">Add New Course</h3>

                <form method="POST" class="form-grid" id="addCourseForm">
                    <input type="text" name="name" placeholder="Course Name (e.g., Bachelor of Multimedia Arts)" required>
                    <input type="text" name="code" placeholder="College Code (e.g., CCMADI)" required>
                    <input type="text" name="required_skills" placeholder="Required Skills (IDs, e.g., 1,5,10)" class="span-2">
                    <input type="text" name="related_interests" placeholder="Related Interests (IDs, e.g., 2,8,12)" class="span-2">
                    <input type="text" name="leading_careers" placeholder="Leading Careers (IDs, e.g., 3,9,15)" class="span-2">
                    <input type="hidden" name="scroll_pos" id="addScrollPos" value="0">
                    <button type="submit" name="action" value="add" class="span-2">Add Course</button>
                </form>

                <div class="search-section">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="courseSearch" placeholder="Search courses by name or college code..." autocomplete="off">
                    </div>
                    <div class="search-count" id="searchCount"></div>
                </div>

                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Course Name</th>
                                <th>College Code</th>
                                <th>Req. Skills (IDs)</th>
                                <th>Rel. Interests (IDs)</th>
                                <th>Leading Careers (IDs)</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grouped_courses as $college_code => $courses_in_college): ?>
                                <tr class="college-header">
                                    <th colspan="7">
                                        College Code: <?= htmlspecialchars($college_code) ?> (<?= count($courses_in_college) ?> Courses)
                                    </th>
                                </tr>
                                <?php foreach ($courses_in_college as $c): ?>
                                    <tr class="course-row">
                                        <td><?= $c['id'] ?></td>
                                        <td class="course-name" data-original="<?= htmlspecialchars($c['name']) ?>">
                                            <strong><?= htmlspecialchars($c['name']) ?></strong>
                                        </td>
                                        <td><code><?= htmlspecialchars($c['college_code']) ?></code></td>
                                        <td><code style="font-size:0.85em;"><?= htmlspecialchars($c['required_skills'] ?? 'NULL') ?></code></td>
                                        <td><code style="font-size:0.85em;"><?= htmlspecialchars($c['related_interests'] ?? 'NULL') ?></code></td>
                                        <td><code style="font-size:0.85em;"><?= htmlspecialchars($c['leading_careers'] ?? 'NULL') ?></code></td>
                                        <td>
                                            <div class="action-group">
                                                <button onclick="editCourse(<?= $c['id'] ?>, '<?= addslashes(htmlspecialchars($c['name'])) ?>', '<?= addslashes(htmlspecialchars($c['college_code'])) ?>')" class="action-btn btn-edit">Edit Name/Code</button>
                                                <button onclick="editAttributes(<?= $c['id'] ?>, '<?= addslashes(htmlspecialchars($c['required_skills'] ?? '')) ?>', '<?= addslashes(htmlspecialchars($c['related_interests'] ?? '')) ?>', '<?= addslashes(htmlspecialchars($c['leading_careers'] ?? '')) ?>')" class="action-btn btn-attributes">Manage Attributes</button>
                                                <a href="?delete=<?= $c['id'] ?>&scroll_pos=<?= $scroll_position ?>" class="action-btn btn-delete" onclick="return confirm('Delete this course?')">Delete</a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Live Search
        document.getElementById('courseSearch').addEventListener('input', function(e) {
            const term = e.target.value.trim().toLowerCase();
            const rows = document.querySelectorAll('.course-row');
            const headers = document.querySelectorAll('.college-header');
            let visible = 0;
            const shownGroups = new Set();

            rows.forEach(row => {
                const nameCell = row.querySelector('.course-name');
                const originalName = nameCell.dataset.original;
                const fullText = row.textContent.toLowerCase();
                nameCell.innerHTML = '<strong>' + originalName + '</strong>';

                if (term === '' || fullText.includes(term)) {
                    row.style.display = '';
                    visible++;

                    let prev = row.previousElementSibling;
                    while (prev && !prev.classList.contains('college-header')) prev = prev.previousElementSibling;
                    if (prev) {
                        prev.style.display = '';
                        shownGroups.add(prev);
                    }

                    if (term !== '' && originalName.toLowerCase().includes(term)) {
                        const escaped = term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                        const regex = new RegExp(`(${escaped})`, 'gi');
                        const highlighted = originalName.replace(regex, '<mark style="background:#fff3cd;padding:0 4px;border-radius:4px;">$1</mark>');
                        nameCell.innerHTML = '<strong>' + highlighted + '</strong>';
                    }
                } else {
                    row.style.display = 'none';
                }
            });

            headers.forEach(h => { if (!shownGroups.has(h)) h.style.display = 'none'; });

            const countEl = document.getElementById('searchCount');
            if (term === '') countEl.textContent = '';
            else countEl.textContent = visible === 0 ? 'No courses found' : visible + (visible === 1 ? ' course found' : ' courses found');
            countEl.style.color = visible === 0 ? '#e74c3c' : '#27ae60';
        });

        // Scroll position preservation
        window.currentScrollPos = 0;
        const submitForms = (form) => {
            const i = document.createElement('input');
            i.type = 'hidden'; i.name = 'scroll_pos'; i.value = window.currentScrollPos || window.scrollY;
            form.appendChild(i);
            form.submit();
        };

        function editCourse(id, name, code) {
            const newName = prompt("Edit Course Name:", name?.trim());
            if (!newName) return;
            const newCode = prompt("College Code:", code);
            if (!newCode) return;
            if (newName.toUpperCase().includes('MULTIMEDIA ARTS') && newCode.toUpperCase() !== 'CCMADI') {
                alert("Reminder: Bachelor of Multimedia Arts should use 'CCMADI' code.");
            }
            const f = document.createElement('form');
            f.method = 'POST'; f.style.display = 'none';
            ['action=edit', `id=${id}`, `name=${encodeURIComponent(newName.trim())}`, `code=${encodeURIComponent(newCode.trim())}`].forEach(val => {
                const input = document.createElement('input');
                input.type = 'hidden';
                [input.name, input.value] = val.split('=');
                f.appendChild(input);
            });
            document.body.appendChild(f);
            submitForms(f);
        }

        function editAttributes(id, skills, interests, careers) {
            const s = prompt("Required Skills (comma IDs):", skills?.trim());
            if (s === null) return;
            const i = prompt("Related Interests:", interests?.trim());
            if (i === null) return;
            const c = prompt("Leading Careers:", careers?.trim());
            if (c === null) return;
            const f = document.createElement('form');
            f.method = 'POST'; f.style.display = 'none';
            const data = {action: 'update_attributes', id, required_skills: s.trim(), related_interests: i.trim(), leading_careers: c.trim()};
            Object.entries(data).forEach(([k,v]) => {
                const input = document.createElement('input');
                input.type = 'hidden'; input.name = k; input.value = v;
                f.appendChild(input);
            });
            document.body.appendChild(f);
            submitForms(f);
        }

        function updateScrollElements(pos) {
            window.currentScrollPos = pos;
            document.getElementById('addScrollPos')?.setAttribute('value', pos);
            document.querySelectorAll('.btn-delete').forEach(a => {
                let href = a.getAttribute('href');
                href = href.replace(/scroll_pos=\d+/, 'scroll_pos='+pos);
                if (!href.includes('scroll_pos=')) href += (href.includes('?') ? '&' : '?') + 'scroll_pos=' + pos;
                a.setAttribute('href', href);
            });
        }
        window.addEventListener('scroll', () => updateScrollElements(window.scrollY));

        // Mobile menu & logout
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const overlay = document.getElementById('overlay');
        const logoutLink = document.getElementById('logoutLink');
        const logoutPopup = document.getElementById('logoutPopup');

        menuToggle.onclick = () => {
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
            mainContent.classList.toggle('expanded');
        };

        logoutLink.onclick = e => {
            e.preventDefault();
            logoutPopup.classList.add('active');
            overlay.classList.add('active');
        };

        window.closeLogoutPopup = () => {
            logoutPopup.classList.remove('active');
            overlay.classList.remove('active');
        };

        overlay.onclick = () => {
            sidebar.classList.remove('active');
            mainContent.classList.remove('expanded');
            closeLogoutPopup();
            overlay.classList.remove('active');
        };

        window.onresize = () => {
            if (window.innerWidth > 992) {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                mainContent.classList.remove('expanded');
            }
        };

        window.onload = () => {
            if (<?= $scroll_position ?> > 0) window.scrollTo(0, <?= $scroll_position ?>);
            updateScrollElements(window.scrollY);
        };
    </script>
</body>
</html>
<?php $mysqli->close(); ?>