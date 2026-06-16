<?php
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

// ---------- PROFILE IMAGE ----------
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

// === HANDLE ADD / EDIT / DELETE ===
$message = '';

if (($_POST['action'] ?? '') === 'add') {
    $desc = trim($_POST['description'] ?? '');
    if ($desc) {
        $stmt = $mysqli->prepare("INSERT INTO skills (description) VALUES (?)");
        $stmt->bind_param("s", $desc);
        $stmt->execute();
        $_SESSION['message'] = "Skill added successfully!";
        header("Location: admin_skills.php");
        exit;
    }
}

if (($_POST['action'] ?? '') === 'edit') {
    $id   = (int)($_POST['id'] ?? 0);
    $desc = trim($_POST['description'] ?? '');
    if ($id && $desc) {
        $stmt = $mysqli->prepare("UPDATE skills SET description = ? WHERE id = ?");
        $stmt->bind_param("si", $desc, $id);
        $stmt->execute();
        $_SESSION['message'] = "Skill updated!";
        header("Location: admin_skills.php");
        exit;
    }
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $mysqli->prepare("DELETE FROM skills WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $_SESSION['message'] = "Skill deleted successfully!";
    header("Location: admin_skills.php");
    exit;
}

if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}

// Fetch skills
$skills = $mysqli->query("SELECT id, description FROM skills ORDER BY description")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Skills - <?= htmlspecialchars(SITE_NAME) ?></title>
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

        /* --- SIDEBAR CSS MATCHING ADMIN COURSES --- */
        .sidebar{width:260px;background:#2c3e50;color:white;height:calc(100vh - 64px);position:fixed;top:64px;left:0;overflow-y:auto;transition:transform .3s;z-index:900;}
        .nav-menu{list-style:none;padding:1.5rem 0;}
        .nav-item{padding:.9rem 1.5rem;display:flex;align-items:center;gap:12px;transition:.3s;cursor:pointer;}
        .nav-item:hover,.nav-item.active{background:#34495e;}
        .nav-item i{width:20px;text-align:center;} /* Removed font-size:1.1rem to match */

        .main-content{margin-left:260px;padding:2rem;transition:margin-left .3s;}
        .main-content.expanded{margin-left:0;}
        .container{max-width:1100px;margin:0 auto;}
        .page-title{font-size:1.8rem;color:#2c3e50;margin-bottom:1.5rem;display:flex;align-items:center;gap:10px;}

        .card{background:white;border-radius:12px;padding:1.5rem;box-shadow:0 4px 15px rgba(0,0,0,.05);margin-bottom:1.5rem;}
        .table-container{overflow-x:auto;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,0.05);}
        table{width:100%;border-collapse:collapse;font-size:.95rem;}
        th{background:#56ab2f;color:white;padding:1rem;text-align:left;font-weight:600;}
        td{padding:.9rem 1rem;border-bottom:1px solid #eee;vertical-align:middle;}
        tr:hover{background:#f8fff8;}

        .actions {display:flex;gap:8px;align-items:center;justify-content:flex-start;}
        .action-btn {padding:.45rem .9rem;border:none;border-radius:6px;font-size:.85rem;font-weight:500;cursor:pointer;transition:background .3s ease, box-shadow .3s ease;min-width:65px;text-align:center;}
        .btn-edit{background:#ffc107;color:white;}
        .btn-edit:hover{background:#e0a800;box-shadow:0 4px 10px rgba(0,0,0,0.15);}
        .btn-delete{background:#dc3545;color:white;}
        .btn-delete:hover{background:#c82333;box-shadow:0 4px 10px rgba(0,0,0,0.15);}

        .form-grid{display:grid;grid-template-columns:1fr 1fr auto;gap:1rem;margin-bottom:1.5rem;align-items:end;}
        .form-grid input{padding:.7rem 1rem;border:1px solid #ddd;border-radius:8px;font-size:.95rem;}
        .form-grid button{background:#56ab2f;color:white;padding:.7rem 1.2rem;border:none;border-radius:8px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:8px;}
        .form-grid button:hover{background:#468f24;}

        .message{background:#d4edda;color:#155724;padding:.8rem 1rem;border-radius:8px;margin-bottom:1rem;font-weight:500;}

        /* Search Section */
        .search-section {
            margin: 2rem 0 1.2rem 0;
            padding-top: 1.5rem;
            border-top: 1px solid #eee;
        }
        .search-box {
            position: relative;
            max-width: 520px;
        }
        .search-box i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #777;
            font-size: 1.1rem;
        }
        .search-box input {
            width: 100%;
            padding: 0.95rem 1rem 0.95rem 2.9rem;
            border: 1px solid #ddd;
            border-radius: 12px;
            font-size: 1rem;
            background: #fdfdfd;
            transition: all 0.3s;
        }
        .search-box input:focus {
            outline: none;
            border-color: #56ab2f;
            box-shadow: 0 0 0 4px rgba(86,171,47,0.15);
        }
        .search-count {
            margin-top: 0.5rem;
            font-size: 0.9rem;
            color: #555;
            font-style: italic;
        }

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
        .logout-popup {display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:white;padding:1.5rem 2rem;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,0.2);z-index:1001;text-align:center;max-width:320px;width:90%;}
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
            <li class="nav-item"><a href="admin_dashboard.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a></li>
            <li class="nav-item"><a href="admin_users.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;"><i class="fas fa-users"></i><span>Manage Users</span></a></li>
            <li class="nav-item"><a href="admin_courses.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;"><i class="fas fa-book"></i><span>Manage Courses</span></a></li>
            
            <li class="nav-item active">
                <i class="fas fa-tools"></i><span>Manage Skills</span>
            </li>
            
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
            <h1 class="page-title"><i class="fas fa-tools"></i> Manage Skills</h1>

            <?php if ($message): ?>
                <div class="message"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <div class="card">
                <h3 style="margin-bottom:1rem;color:#56ab2f;">Add New Skill</h3>

                <form method="POST" class="form-grid">
                    <input type="hidden" name="action" value="add">
                    <input type="text" name="description" placeholder="Skill Description (e.g., Programming)" required>
                    <button type="submit">Add Skill</button>
                </form>

                <div class="search-section">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="skillSearch" placeholder="Search skills..." autocomplete="off">
                    </div>
                    <div class="search-count" id="searchCount"></div>
                </div>

                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Skill Description</th>
                                <th style="text-align:center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($skills)): ?>
                                <tr>
                                    <td colspan="3" style="text-align:center;color:#95a5a6;padding:2rem;font-style:italic;">
                                        No skills found.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($skills as $s): ?>
                                <tr class="skill-row">
                                    <td><?= $s['id'] ?></td>
                                    <td class="skill-name" data-original="<?= htmlspecialchars($s['description']) ?>">
                                        <strong><?= htmlspecialchars($s['description']) ?></strong>
                                    </td>
                                    <td>
                                        <div class="actions">
                                            <button onclick="editSkill(<?= $s['id'] ?>, '<?= addslashes(htmlspecialchars($s['description'])) ?>')" 
                                                    class="action-btn btn-edit">Edit</button>
                                            <a href="?delete=<?= $s['id'] ?>" 
                                               class="action-btn btn-delete" 
                                               onclick="return confirm('Delete this skill?')">Delete</a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <script>
        function editSkill(id, desc) {
            const newDesc = prompt("Edit Skill Description:", desc);
            if (newDesc !== null && newDesc.trim() !== "") {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" value="${id}">
                    <input type="hidden" name="description" value="${newDesc.trim()}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        document.getElementById('skillSearch').addEventListener('input', function(e) {
            const term = e.target.value.trim().toLowerCase();
            const rows = document.querySelectorAll('.skill-row');
            let visible = 0;

            rows.forEach(row => {
                const nameCell = row.querySelector('.skill-name');
                const original = nameCell.dataset.original;
                const fullText = row.textContent.toLowerCase();

                nameCell.innerHTML = '<strong>' + original + '</strong>';

                if (term === '' || fullText.includes(term)) {
                    row.style.display = '';
                    visible++;

                    if (term !== '' && original.toLowerCase().includes(term)) {
                        const escaped = term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                        const regex = new RegExp(`(${escaped})`, 'gi');
                        const highlighted = original.replace(regex, '<mark style="background:#fff3cd;padding:0 4px;border-radius:4px;">$1</mark>');
                        nameCell.innerHTML = '<strong>' + highlighted + '</strong>';
                    }
                } else {
                    row.style.display = 'none';
                }
            });

            const countEl = document.getElementById('searchCount');
            if (term === '') {
                countEl.textContent = '';
            } else if (visible === 0) {
                countEl.textContent = 'No skills found';
                countEl.style.color = '#e74c3c';
            } else {
                countEl.textContent = visible + (visible === 1 ? ' skill found' : ' skills found');
                countEl.style.color = '#27ae60';
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
            if (sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
                mainContent.classList.remove('expanded');
            }
            if (logoutPopup.classList.contains('active')) window.closeLogoutPopup();
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