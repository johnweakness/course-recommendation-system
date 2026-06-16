<?php
include 'config.php';

// --- SCROLL POSITION FIX ---
$scroll_position = (isset($_POST['scroll_pos'])) ? (int)$_POST['scroll_pos'] : 0;
if (isset($_GET['scroll_pos'])) $scroll_position = (int)$_GET['scroll_pos'];
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

// === ACTIONS (Add/Edit/Delete) ===
if (isset($_POST['action'])) {
    $current_scroll = (int)($_POST['scroll_pos'] ?? 0);

    if ($_POST['action'] === 'add') {
        $desc = trim($_POST['description'] ?? '');
        if ($desc) {
            $stmt = $mysqli->prepare("INSERT INTO careers (description) VALUES (?)");
            $stmt->bind_param("s", $desc);
            $stmt->execute();
            $stmt->close();
            $message = "Career added successfully!";
        }
    }

    if ($_POST['action'] === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $desc = trim($_POST['description'] ?? '');
        if ($id && $desc) {
            $stmt = $mysqli->prepare("UPDATE careers SET description = ? WHERE id = ?");
            $stmt->bind_param("si", $desc, $id);
            $stmt->execute();
            $stmt->close();
            $message = "Career updated successfully!";
        }
    }

    if ($message) {
        header("Location: admin_careers.php?message=" . urlencode($message) . "&scroll_pos=" . $current_scroll);
        exit;
    }
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $scroll_pos = (int)($_GET['scroll_pos'] ?? 0);
    $stmt = $mysqli->prepare("DELETE FROM careers WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    header("Location: admin_careers.php?message=Career%20deleted%20successfully!&scroll_pos=" . $scroll_pos);
    exit;
}

if (isset($_GET['message'])) {
    $message = htmlspecialchars($_GET['message']);
}

// === Fetch Careers with Linked Courses ===
$careers_result = $mysqli->query("
    SELECT 
        c.id, 
        c.description,
        GROUP_CONCAT(
            DISTINCT CONCAT(co.name, ' (', co.college_code, ')')
            ORDER BY co.college_code, co.name
            SEPARATOR '<br>'
        ) AS linked_courses
    FROM careers c
    LEFT JOIN courses co ON FIND_IN_SET(c.id, co.leading_careers)
    GROUP BY c.id, c.description
    ORDER BY c.description
");

$careers = [];
while ($row = $careers_result->fetch_assoc()) {
    $careers[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Careers - <?= htmlspecialchars(SITE_NAME) ?></title>
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

        /* --- STANDARDIZED SIDEBAR CSS --- */
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
        .table-container{overflow-x:auto;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,0.05);}
        table{width:100%;border-collapse:collapse;font-size:.95rem;}
        th{background:#56ab2f;color:white;padding:1rem;text-align:left;font-weight:600;}
        td{padding:.9rem 1rem;border-bottom:1px solid #eee;vertical-align:top;}
        tr:hover{background:#f8fff8;}

        .actions{display:flex;gap:8px;align-items:center;}
        .action-btn{padding:.45rem .9rem;border:none;border-radius:6px;font-size:.85rem;font-weight:500;cursor:pointer;transition:background .3s ease, box-shadow .3s ease;min-width:65px;text-align:center;}
        .btn-edit{background:#ffc107;color:white;}
        .btn-edit:hover{background:#e0a800;box-shadow:0 4px 10px rgba(0,0,0,0.15);}
        .btn-delete{background:#dc3545;color:white;}
        .btn-delete:hover{background:#c82333;box-shadow:0 4px 10px rgba(0,0,0,0.15);}

        .form-grid{display:grid;grid-template-columns:1fr auto;gap:1rem;margin-bottom:1.5rem;align-items:end;}
        .form-grid input{padding:.7rem 1rem;border:1px solid #ddd;border-radius:8px;font-size:.95rem;}
        .form-grid button{background:#56ab2f;color:white;padding:.7rem 1.2rem;border:none;border-radius:8px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:8px;}
        .form-grid button:hover{background:#468f24;}

        .message{background:#d4edda;color:#155724;padding:.8rem 1rem;border-radius:8px;margin-bottom:1rem;font-weight:500;}
        .linked-courses{font-size:0.9em;line-height:1.5;color:#2c3e50;}
        .no-courses{color:#999;font-style:italic;}

        /* LIVE SEARCH */
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
            .form-grid button{width:100%;justify-content:center;}
        }

        .overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:800;}
        .overlay.active{display:block;}
        .logout-popup{display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:white;padding:1.5rem 2rem;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.2);z-index:1001;text-align:center;max-width:320px;width:90%;}
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
        <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    </header>

    <aside class="sidebar" id="sidebar">
        <ul class="nav-menu">
            <li class="nav-item"><a href="admin_dashboard.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a></li>
            <li class="nav-item"><a href="admin_users.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;"><i class="fas fa-users"></i><span>Manage Users</span></a></li>
            <li class="nav-item"><a href="admin_courses.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;"><i class="fas fa-book"></i><span>Manage Courses</span></a></li>
            <li class="nav-item"><a href="admin_skills.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;"><i class="fas fa-tools"></i><span>Manage Skills</span></a></li>
            <li class="nav-item"><a href="admin_interests.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;"><i class="fas fa-heart"></i><span>Manage Interests</span></a></li>
            
            <li class="nav-item active"><i class="fas fa-briefcase"></i><span>Manage Careers</span></li>
            
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
            <h1 class="page-title"><i class="fas fa-briefcase"></i> Manage Careers</h1>

            <?php if ($message): ?>
                <div class="message"><?= $message ?></div>
            <?php endif; ?>

            <div class="card">
                <h3 style="margin-bottom:1rem;color:#56ab2f;">Add New Career Goal</h3>
                <form method="POST" class="form-grid" id="addForm">
                    <input type="text" name="description" placeholder="e.g., Graphic Designer" required>
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="scroll_pos" id="addScrollPos" value="0">
                    <button type="submit"><i class="fas fa-plus"></i> Add Career</button>
                </form>

                <div class="search-section">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="careerSearch" placeholder="Search careers by name or linked courses..." autocomplete="off">
                    </div>
                    <div class="search-count" id="searchCount"></div>
                </div>

                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Career Goal</th>
                                <th>Linked Courses</th>
                                <th style="text-align:center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="careersTableBody">
                            <?php foreach ($careers as $c): ?>
                            <tr class="career-row">
                                <td><?= $c['id'] ?></td>
                                <td class="career-name" data-original="<?= htmlspecialchars($c['description']) ?>">
                                    <strong><?= htmlspecialchars($c['description']) ?></strong>
                                </td>
                                <td class="linked-courses">
                                    <?php 
                                    echo $c['linked_courses'] 
                                        ? $c['linked_courses'] 
                                        : '<span class="no-courses">No courses linked yet<br><small>(Will be auto-linked based on program)</small></span>'; 
                                    ?>
                                </td>
                                <td>
                                    <div class="actions">
                                        <button onclick="editCareer(<?= $c['id'] ?>, '<?= addslashes(htmlspecialchars($c['description'])) ?>')" class="action-btn btn-edit">Edit</button>
                                        <a href="?delete=<?= $c['id'] ?>&scroll_pos=<?= $scroll_position ?>" 
                                           class="action-btn btn-delete" 
                                           onclick="return confirm('Delete this career? All links will be removed.')">Delete</a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <script>
        // === LIVE SEARCH ===
        document.getElementById('careerSearch').addEventListener('input', function(e) {
            const term = e.target.value.trim().toLowerCase();
            const rows = document.querySelectorAll('.career-row');
            let visible = 0;

            rows.forEach(row => {
                const nameCell = row.querySelector('.career-name');
                const originalName = nameCell.dataset.original;
                const fullText = row.textContent.toLowerCase();

                // Reset highlight
                nameCell.innerHTML = '<strong>' + originalName + '</strong>';

                if (term === '' || fullText.includes(term)) {
                    row.style.display = '';
                    visible++;

                    // Highlight matching text in career name
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

            const countEl = document.getElementById('searchCount');
            if (term === '') {
                countEl.textContent = '';
            } else {
                countEl.textContent = visible === 0 ? 'No careers found' : 
                                      `${visible} career${visible === 1 ? '' : 's'} found`;
                countEl.style.color = visible === 0 ? '#e74c3c' : '#27ae60';
            }
        });

        // === SCROLL POSITION PRESERVATION ===
        window.currentScrollPos = 0;
        const submitForms = (form) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'scroll_pos';
            input.value = window.currentScrollPos || window.scrollY;
            form.appendChild(input);
            form.submit();
        };

        function editCareer(id, current) {
            const newDesc = prompt("Edit Career Goal:", current);
            if (newDesc && newDesc.trim() && newDesc.trim() !== current) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" value="${id}">
                    <input type="hidden" name="description" value="${newDesc.trim()}">
                `;
                document.body.appendChild(form);
                submitForms(form);
            }
        }

        function updateScroll() {
            window.currentScrollPos = window.scrollY;
            document.getElementById('addScrollPos').value = window.currentScrollPos;
            document.querySelectorAll('.btn-delete').forEach(a => {
                let href = a.getAttribute('href');
                href = href.replace(/scroll_pos=\d+/, 'scroll_pos=' + window.currentScrollPos);
                if (!href.includes('scroll_pos=')) href += (href.includes('?') ? '&' : '?') + 'scroll_pos=' + window.currentScrollPos;
                a.href = href;
            });
        }

        window.addEventListener('scroll', updateScroll);
        window.onload = () => {
            if (<?= $scroll_position ?> > 0) window.scrollTo(0, <?= $scroll_position ?>);
            updateScroll();
        };

        // === MOBILE MENU & LOGOUT ===
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
    </script>
</body>
</html>
<?php $mysqli->close(); ?>