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
$user_role  = $_SESSION['user_auth']['role']; 
$is_admin   = false; 

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

// --- CREATE LOOKUP MAPS (ID => Name) ---
$skill_map = [];
$skills_result = $mysqli->query("SELECT id, description FROM skills ORDER BY description");
while ($row = $skills_result->fetch_assoc()) {
    $skill_map[$row['id']] = $row['description'];
}

$interest_map = [];
$interests_result = $mysqli->query("SELECT id, description FROM interests ORDER BY description");
while ($row = $interests_result->fetch_assoc()) {
    $interest_map[$row['id']] = $row['description'];
}

$career_map = [];
$careers_result = $mysqli->query("SELECT id, description FROM careers ORDER BY description");
while ($row = $careers_result->fetch_assoc()) {
    $career_map[$row['id']] = $row['description'];
}

// Process form submission
$top3 = [];
$result_message = '';
$show_result = false;
$selected_career_descs = [];

// *** 1. INITIALIZE VARIABLES TO PREVENT ERRORS ON FIRST LOAD ***
$selected_skills    = [];
$selected_interests = [];
$selected_careers   = [];
$other_skill = '';
$other_interest = '';
$other_career = '';

// --- NEW: PANEL FIX - RESET TRIGGER ---
if (isset($_GET['reset'])) {
    $selected_skills = [];
    $selected_interests = [];
    $selected_careers = [];
    $other_skill = '';
    $other_interest = '';
    $other_career = '';
    $show_result = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // *** 2. CAPTURE SELECTIONS TO MAKE FORM STICKY ***
    $selected_skills    = $_POST['skills'] ?? [];
    $selected_interests = $_POST['interests'] ?? [];
    $selected_careers   = $_POST['careers'] ?? [];
    
    // Capture "Other" manual entries
    $other_skill = trim($_POST['other_skill'] ?? '');
    $other_interest = trim($_POST['other_interest'] ?? '');
    $other_career = trim($_POST['other_career'] ?? '');

    // === VALIDATION: MINIMUM SELECTIONS ===
    $errors = [];
    // Count total including manual entries
    $total_skills = count($selected_skills) + ($other_skill !== '' ? 1 : 0);
    $total_interests = count($selected_interests) + ($other_interest !== '' ? 1 : 0);
    $total_careers = count($selected_careers) + ($other_career !== '' ? 1 : 0);

    if ($total_skills < 2) {
        $errors[] = "Please select at least 2 skills (or enter your own).";
    }
    if ($total_interests < 2) {
        $errors[] = "Please select at least 2 interests (or enter your own).";
    }
    if ($total_careers < 1) {
        $errors[] = "Please select at least 1 dream career (or enter your own).";
    }

    if (!empty($errors)) {
        $result_message = implode("<br>", $errors);
        $show_result = true;
    } else {
        // Get descriptions for display
        foreach ($selected_careers as $cid) {
            if (isset($career_map[$cid])) {
                $selected_career_descs[] = $career_map[$cid];
            }
        }
        if ($other_career !== '') $selected_career_descs[] = $other_career;

        // === SCORING ALGORITHM ===
        $user_skills    = array_map('intval', $selected_skills);
        $user_interests = array_map('intval', $selected_interests);
        $user_careers   = array_map('intval', $selected_careers);

        // Max potential score based on user selection
        $user_max_score = ($total_skills * 1) + ($total_interests * 1) + ($total_careers * 2);

        $courses_query = "SELECT id, name, college_code, 
                                   required_skills, 
                                   related_interests, 
                                   leading_careers 
                          FROM courses";
        $courses_result = $mysqli->query($courses_query);

        $course_scores = [];

        while ($course = $courses_result->fetch_assoc()) {
            $skill_count    = 0;
            $interest_count = 0;
            $career_count   = 0;

            $missing_skills_list = [];
            $missing_interests_list = [];

            // Parse DB Lists
            $req_skills = array_filter(array_map('trim', explode(',', $course['required_skills'] ?? '')));
            $rel_interests = array_filter(array_map('trim', explode(',', $course['related_interests'] ?? '')));
            $lead_careers = array_filter(array_map('trim', explode(',', $course['leading_careers'] ?? '')));

            $req_skills = array_map('intval', $req_skills);
            $rel_interests = array_map('intval', $rel_interests);
            $lead_careers = array_map('intval', $lead_careers);

            // --- CHECK SKILLS ---
            foreach ($user_skills as $sid) {
                if (in_array($sid, $req_skills)) {
                    $skill_count++; 
                }
            }
            foreach ($req_skills as $rsid) {
                if (!in_array($rsid, $user_skills) && isset($skill_map[$rsid])) {
                    $missing_skills_list[] = $skill_map[$rsid];
                }
            }

            // --- CHECK INTERESTS ---
            foreach ($user_interests as $iid) {
                if (in_array($iid, $rel_interests)) {
                    $interest_count++; 
                }
            }
            foreach ($rel_interests as $riid) {
                if (!in_array($riid, $user_interests) && isset($interest_map[$riid])) {
                    $missing_interests_list[] = $interest_map[$riid];
                }
            }

            // --- CHECK CAREERS ---
            foreach ($user_careers as $cid) {
                if (in_array($cid, $lead_careers)) {
                    $career_count++; 
                }
            }
            
            // Calculate Score
            $score = ($skill_count * 1) + ($interest_count * 1) + ($career_count * 2);

            // Calculate Percentage
            $percentage = ($user_max_score > 0) ? round(($score / $user_max_score) * 100) : 0;
            if($percentage > 100) $percentage = 100;

            if ($score > 0) {
                $course_scores[] = [
                    'name'             => $course['name'],
                    'score'            => $score,
                    'percentage'       => $percentage, 
                    'skill_matches'    => $skill_count,
                    'interest_matches' => $interest_count,
                    'career_matches'   => $career_count,
                    'missing_skills'    => array_slice($missing_skills_list, 0, 3), 
                    'missing_interests' => array_slice($missing_interests_list, 0, 3) 
                ];
            }
        }

        usort($course_scores, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        $top3 = array_slice($course_scores, 0, 3);

        if (empty($top3)) {
            $result_message = "No matching courses found. Try selecting different options.";
        } else {
            $result_message = "Your personalized recommendations are ready!";
        }
        $show_result = true;

        // Save to DB (Merge manual entries into the CSV strings for Admin review)
        $final_skills = $selected_skills;
        if($other_skill !== '') $final_skills[] = "Manual: " . $other_skill;
        $skills_csv = implode(',', $final_skills);

        $final_interests = $selected_interests;
        if($other_interest !== '') $final_interests[] = "Manual: " . $other_interest;
        $interests_csv  = implode(',', $final_interests);

        $final_careers = $selected_careers;
        if($other_career !== '') $final_careers[] = "Manual: " . $other_career;
        $careers_csv    = implode(',', $final_careers);

        $choices = array_column($top3, 'name');
        $scores  = array_column($top3, 'score');

        $first  = $choices[0] ?? '';
        $second = $choices[1] ?? '';
        $third  = $choices[2] ?? '';
        $score1 = $scores[0] ?? 0;
        $score2 = $scores[1] ?? 0;
        $score3 = $scores[2] ?? 0;

        $save = $mysqli->prepare("
            INSERT INTO recommendations 
            (selected_skills, selected_interests, selected_careers,
             first_choice, second_choice, third_choice,
             score1, score2, score3, user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY 
            UPDATE
             first_choice=VALUES(first_choice), second_choice=VALUES(second_choice), third_choice=VALUES(third_choice),
             score1=VALUES(score1), score2=VALUES(score2), score3=VALUES(score3)
        ");
        $save->bind_param(
            "ssssssiiii",
            $skills_csv, $interests_csv, $careers_csv,
            $first, $second, $third,
            $score1, $score2, $score3,
            $user_id
        );
        $save->execute();
        $save->close();
    }
}

$hide_form_style = ($show_result && empty($errors) && !empty($top3)) ? 'display:none;' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Get Recommendation - <?= htmlspecialchars(SITE_NAME) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f4f7fa;
            color: #333;
            line-height: 1.6;
            min-height: 100vh;
        }
        a { text-decoration: none; color: inherit; }

        .header {
            background: #56ab2f;
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        .logo { display: flex; align-items: center; gap: 12px; font-size: 1.4rem; font-weight: 600; }
        .logo img { width: 40px; height: 40px; border-radius: 50%; border: 2px solid rgba(255,255,255,0.3); object-fit: cover; }
        
        .user-profile { 
            display: flex; align-items: center; gap: 10px; font-size: 0.95rem; 
            position: relative;
            cursor: pointer;
            padding: 5px 10px;
            border-radius: 8px;
            transition: background 0.3s;
        }
        .user-profile:hover { background: rgba(255,255,255,0.1); }
        .user-profile img { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; border: 2px solid rgba(255,255,255,0.4); }
        
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

        .menu-toggle { display: none; background: none; border: none; color: white; font-size: 1.5rem; cursor: pointer; }

        .sidebar {
            width: 260px;
            background: #2c3e50;
            color: white;
            height: calc(100vh - 64px);
            position: fixed;
            top: 64px;
            left: 0;
            overflow-y: auto;
            transition: transform 0.3s ease;
            z-index: 900;
        }
        .sidebar.collapsed { transform: translateX(-100%); }
        .nav-menu { list-style: none; padding: 1.5rem 0; }
        .nav-item {
            padding: 0.9rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: 0.3s;
            cursor: pointer;
        }
        .nav-item:hover, .nav-item.active { background: #34495e; }
        .nav-item i { width: 20px; text-align: center; }

        .main-content {
            margin-left: 260px;
            padding: 2rem;
            transition: margin-left 0.3s ease;
        }
        .main-content.expanded { margin-left: 0; }
        .container { max-width: 1000px; margin: 0 auto; }
        .page-title {
            font-size: 1.8rem;
            color: #2c3e50;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            margin-bottom: 1.5rem;
        }

        .section-title {
            font-size: 1.3rem;
            color: #2c3e50;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #56ab2f;
            display: inline-block;
        }

        .step-nav {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            position: relative;
        }
        .step-nav:after {
            content: ''; position: absolute; bottom: 0; left: 0; width: 100%; height: 2px; background: #eee; z-index: 1;
        }
        .step-item {
            flex: 1;
            text-align: center;
            padding: 10px;
            font-weight: 600;
            color: #999;
            cursor: pointer;
            position: relative;
            z-index: 2;
            border-bottom: 3px solid transparent;
            transition: 0.3s;
        }
        .step-item.active {
            color: #56ab2f;
            border-bottom-color: #56ab2f;
        }
        .step-item.completed {
            color: #2c3e50;
            border-bottom-color: #2c3e50;
        }
        .step-content {
            display: none;
            animation: fadeInStep 0.4s ease-out;
        }
        .step-content.active {
            display: block;
        }
        @keyframes fadeInStep {
            from { opacity: 0; transform: translateX(10px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .checkbox-group {
            background: #f8fff8;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 1rem;
            height: 380px; 
            overflow-y: auto;
            position: relative;
        }

        .search-box {
            width: 100%;
            padding: 0.7rem 1rem;
            margin-bottom: 0.8rem;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 0.95rem;
            background: white;
        }
        .search-box:focus { outline: none; border-color: #56ab2f; box-shadow: 0 0 0 3px rgba(86,171,47,0.1); }

        .checkbox-group h4 { color: #56ab2f; margin-bottom: 0.8rem; font-size: 1.1rem; display: flex; align-items: center; gap: 8px; }
        .checkbox-group h4 small { color: #e74c3c; font-weight: 500; font-size: 0.85rem; }
        .checkbox-item { display: flex; align-items: flex-start; margin: 0.6rem 0; font-size: 0.95rem; }
        .checkbox-item input[type="checkbox"] { margin-right: 10px; margin-top: 2px; accent-color: #56ab2f; }
        .checkbox-item label { cursor: pointer; color: #444; line-height: 1.4; }

        .other-entry { margin-top: 15px; padding-top: 15px; border-top: 1px dashed #ccc; }
        .other-input { width: 100%; padding: 0.6rem; border: 1px solid #ddd; border-radius: 6px; font-size: 0.9rem; margin-top: 5px; }

        .step-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 1rem;
            gap: 10px;
        }
        
        .btn { padding: 0.9rem 1.8rem; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.3s; display: inline-flex; align-items: center; gap: 10px; font-size: 1rem; }
        .btn-primary { background: #56ab2f; color: white; }
        .btn-primary:hover { background: #468f24; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(86,171,47,0.3); }
        .btn-secondary { background: #95a5a6; color: white; }
        .btn-secondary:hover { background: #7f8c8d; }
        .btn-outline { background: transparent; border: 2px solid #56ab2f; color: #56ab2f; }
        .btn-outline:hover { background: #56ab2f; color: white; }

        .result-card {
            background: linear-gradient(135deg, #eef5fc 0%, #f8fff8 100%);
            border-left: 6px solid #56ab2f;
            animation: fadeIn 0.6s ease-out;
        }

        .result-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
        .result-header h3 { color: #2c3e50; margin: 0; }
        .result-message { color: #27ae60; font-size: 0.9rem; font-weight: 500; }

        .rec-list { display: flex; flex-direction: column; gap: 0.8rem; }
        .rec-item { background: white; padding: 1rem; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); transition: all 0.2s; flex-wrap: wrap; gap: 10px; }
        .rec-item:hover { transform: translateX(4px); box-shadow: 0 4px 12px rgba(86,171,47,0.15); }
        .rec-rank { font-weight: bold; color: #56ab2f; font-size: 1.2rem; min-width: 40px; }
        .rec-name { font-weight: 600; color: #2c3e50; flex: 1; min-width: 200px; }
        .rec-score { background: #56ab2f; color: white; padding: 0.3rem 0.8rem; border-radius: 20px; font-size: 0.85rem; font-weight: 600; }

        .score-breakdown { display: flex; gap: 4px; margin-top: 10px; height: 8px; width: 100%; background: #e0e0e0; border-radius: 4px; overflow: hidden; }
        .bar-segment { height: 100%; transition: width 0.5s ease; }
        .bar-skill { background-color: #3498db; }   
        .bar-interest { background-color: #e67e22; } 
        .bar-career { background-color: #9b59b6; }   
        
        .legend { display: flex; gap: 15px; font-size: 0.75rem; margin-top: 5px; color: #666; margin-bottom: 5px; }
        .legend span { display: flex; align-items: center; gap: 5px; }
        .dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }

        .explanation { background: #f9f9f9; padding: 1.2rem; margin: 0.6rem 0 1.2rem 3rem; border-radius: 8px; border-left: 4px solid #56ab2f; font-size: 0.95rem; line-height: 1.7; }
        .explanation p.breakdown { margin: 1rem 0; padding: 10px; border-top: 1px dashed #e0e0e0; border-bottom: 1px dashed #e0e0e0; background: #f0f8ff; font-weight: 500; }
        
        .gap-analysis { margin-top: 1rem; background: #fff3e0; border: 1px solid #ffcc80; padding: 10px; border-radius: 6px; font-size: 0.9rem; }
        .gap-title { font-weight: bold; color: #e67e22; display: flex; align-items: center; gap: 6px; }
        .gap-list { margin-left: 20px; color: #555; font-style: italic; }

        .feedback-section { margin-top: 2rem; border-top: 1px solid #eee; padding-top: 1rem; text-align: center; }
        .feedback-btn { border: 1px solid #ccc; background: white; padding: 5px 15px; border-radius: 20px; cursor: pointer; margin: 0 5px; transition: 0.2s; }
        .feedback-btn:hover { background: #f0f0f0; border-color: #bbb; }
        .feedback-btn.active { background: #dff0d8; color: #3c763d; border-color: #d6e9c6; }
        .feedback-textarea {
            width: 100%;
            max-width: 400px;
            margin-top: 10px;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-family: inherit;
            resize: vertical;
            display: none; 
        }
        .feedback-textarea.active { display: block; }

        .explanation strong { color: #2c3e50; }
        .explanation .highlight { background: #e8f5e8; padding: 2px 6px; border-radius: 4px; font-weight: 600; }
        .explanation small { color: #7f8c8d; display: block; margin-top: 1rem; font-size: 0.85rem; }
        .error-message { background: #fdf2f2; color: #c53030; padding: 1rem; border-radius: 8px; border-left: 4px solid #c53030; margin-top: 1rem; font-size: 0.95rem; }

        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        @media (max-width: 992px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.active { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .menu-toggle { display: block; }
        }
        @media (max-width: 576px) {
            .header { padding: 1rem; }
            .logo span { display: none; }
            .form-grid { grid-template-columns: 1fr; }
            .btn-group { flex-direction: column; }
            .btn { width: 100%; justify-content: center; }
            .explanation { margin-left: 0; }
            .checkbox-group { height: 50vh; }
            .step-item { font-size: 0.9rem; padding: 8px 4px; }
        }

        .overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 800; opacity: 0; visibility: hidden; transition: opacity 0.3s ease, visibility 0.3s ease; }
        .overlay.active { opacity: 1; visibility: visible; }

        .logout-popup { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -45%) scale(0.95); background: white; padding: 1.5rem 2rem; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); z-index: 1001; text-align: center; max-width: 320px; width: 90%; opacity: 0; visibility: hidden; pointer-events: none; transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); }
        .logout-popup.active { opacity: 1; visibility: visible; pointer-events: auto; transform: translate(-50%, -50%) scale(1); }

        #processingOverlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(255, 255, 255, 0.9);
            z-index: 2000;
            display: flex; flex-direction: column; justify-content: center; align-items: center;
            opacity: 0; visibility: hidden; transition: all 0.3s;
        }
        #processingOverlay.active { opacity: 1; visibility: visible; }
        .spinner {
            width: 50px; height: 50px;
            border: 5px solid #f3f3f3; border-top: 5px solid #56ab2f; border-radius: 50%;
            animation: spin 1s linear infinite; margin-bottom: 15px;
        }
        .loading-text { font-size: 1.2rem; color: #2c3e50; font-weight: 600; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        @media print {
            .sidebar, .header, .step-nav, .step-actions, .search-box, .section-title, #recommendationForm, .menu-toggle, .feedback-section { display: none !important; }
            .main-content { margin: 0; padding: 0; }
            .result-card { border: none; box-shadow: none; background: white; border-left: none; }
            body { background: white; }
            .page-title::after { content: " - Generated Report"; font-size: 1rem; color: #666; }
            .explanation { margin-left: 0; border-left: 1px solid #ccc; }
        }
    </style>
</head>
<body>

    <div id="processingOverlay">
        <div class="spinner"></div>
        <div class="loading-text" id="loadingText">Analyzing your preferences...</div>
    </div>

    <header class="header">
        <div class="logo">
            <img src="<?= htmlspecialchars(LOGO) ?>" alt="Logo">
            <span>Course Recommendation System</span>
        </div>
        
        <div class="user-profile" id="userProfileTrigger">
            <img src="<?= htmlspecialchars($profile_image_path) ?>" 
                 alt="Profile" 
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
            <li class="nav-item">
                <a href="dashboard.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item active">
                <i class="fas fa-lightbulb"></i>
                <span>Get Recommendation</span>
            </li>
            <li class="nav-item">
                <a href="history.php" style="width:100%;display:flex;align-items:center;gap:12px;color:inherit;">
                    <i class="fas fa-history"></i>
                    <span>History</span>
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
            <button class="yes" onclick="window.location.href='logout_process.php'">Yes</button>
            <button class="no" onclick="window.closeLogoutPopup()">No</button>
        </div>
    </div>

    <main class="main-content" id="mainContent">
        <div class="container">
            <h1 class="page-title">Get Course Recommendation</h1>

            <form method="POST" class="card" id="recommendationForm" style="<?= $hide_form_style ?>">
                
                <div class="step-nav">
                    <div class="step-item active" onclick="showStep(1)" id="nav-step1">
                        <i class="fas fa-tools"></i> Skills
                    </div>
                    <div class="step-item" onclick="showStep(2)" id="nav-step2">
                        <i class="fas fa-heart"></i> Interests
                    </div>
                    <div class="step-item" onclick="showStep(3)" id="nav-step3">
                        <i class="fas fa-briefcase"></i> Careers
                    </div>
                </div>

                <div class="step-content active" id="step1">
                    <div class="checkbox-group">
                        <h4>Your Skills <small>(minimum of 2 choices)</small></h4>
                        <input type="text" class="search-box" placeholder="Search skills..." data-target="skills">
                        <?php 
                        $skills_result->data_seek(0);
                        while ($row = $skills_result->fetch_assoc()): 
                            $is_checked = in_array($row['id'], $selected_skills) ? 'checked' : '';
                        ?>
                            <div class="checkbox-item" data-label="<?= strtolower(htmlspecialchars($row['description'])) ?>">
                                <input type="checkbox" name="skills[]" value="<?= $row['id'] ?>" id="skill-<?= $row['id'] ?>" <?= $is_checked ?>>
                                <label for="skill-<?= $row['id'] ?>"><?= htmlspecialchars($row['description']) ?></label>
                            </div>
                        <?php endwhile; ?>
                        
                        <div class="other-entry">
                            <label style="font-size: 0.9rem; color: #666;">Can't find your skill? Enter it manually:</label>
                            <input type="text" name="other_skill" class="other-input" placeholder="e.g., Quantum Computing" value="<?= htmlspecialchars($other_skill) ?>">
                        </div>
                    </div>
                    <div class="step-actions">
                        <button type="button" class="btn btn-primary" onclick="showStep(2)">Next <i class="fas fa-arrow-right"></i></button>
                    </div>
                </div>

                <div class="step-content" id="step2">
                    <div class="checkbox-group">
                        <h4>Your Interests <small>(minimum of 2 choices)</small></h4>
                        <input type="text" class="search-box" placeholder="Search interests..." data-target="interests">
                        <?php 
                        $interests_result->data_seek(0);
                        while ($row = $interests_result->fetch_assoc()): 
                             $is_checked = in_array($row['id'], $selected_interests) ? 'checked' : '';
                        ?>
                            <div class="checkbox-item" data-label="<?= strtolower(htmlspecialchars($row['description'])) ?>">
                                <input type="checkbox" name="interests[]" value="<?= $row['id'] ?>" id="interest-<?= $row['id'] ?>" <?= $is_checked ?>>
                                <label for="interest-<?= $row['id'] ?>"><?= htmlspecialchars($row['description']) ?></label>
                            </div>
                        <?php endwhile; ?>

                        <div class="other-entry">
                            <label style="font-size: 0.9rem; color: #666;">Interest not listed? Enter here:</label>
                            <input type="text" name="other_interest" class="other-input" placeholder="e.g., Urban Farming" value="<?= htmlspecialchars($other_interest) ?>">
                        </div>
                    </div>
                    <div class="step-actions">
                        <button type="button" class="btn btn-secondary" onclick="showStep(1)"><i class="fas fa-arrow-left"></i> Back</button>
                        <button type="button" class="btn btn-primary" onclick="showStep(3)">Next <i class="fas fa-arrow-right"></i></button>
                    </div>
                </div>

                <div class="step-content" id="step3">
                    <div class="checkbox-group">
                        <h4>Your Dream Career(s) <small>(minimum of 1 choice)</small></h4>
                        <input type="text" class="search-box" placeholder="Search careers..." data-target="careers">
                        <?php 
                        $careers_result->data_seek(0);
                        while ($row = $careers_result->fetch_assoc()): 
                             $is_checked = in_array($row['id'], $selected_careers) ? 'checked' : '';
                        ?>
                            <div class="checkbox-item" data-label="<?= strtolower(htmlspecialchars($row['description'])) ?>">
                                <input type="checkbox" name="careers[]" value="<?= $row['id'] ?>" id="career-<?= $row['id'] ?>" <?= $is_checked ?>>
                                <label for="career-<?= $row['id'] ?>"><?= htmlspecialchars($row['description']) ?></label>
                            </div>
                        <?php endwhile; ?>

                        <div class="other-entry">
                            <label style="font-size: 0.9rem; color: #666;">Dream career not listed? Enter here:</label>
                            <input type="text" name="other_career" class="other-input" placeholder="e.g., Space Architect" value="<?= htmlspecialchars($other_career) ?>">
                        </div>
                    </div>
                    <div class="step-actions">
                        <button type="button" class="btn btn-secondary" onclick="showStep(2)"><i class="fas fa-arrow-left"></i> Back</button>
                        <button type="submit" name="btn_submit" class="btn btn-primary">
                            Show My Best Courses
                        </button>
                    </div>
                </div>

            </form>

            <?php if ($show_result && !empty($errors ?? [])): ?>
                <div class="card error-message">
                    <?= $result_message ?>
                </div>
            <?php endif; ?>

            <?php if ($show_result && empty($errors ?? []) && !empty($top3)): ?>
                <div class="card result-card" id="resultsArea">
                    <div class="result-header">
                        <h3>Your Top 3 Courses</h3>
                        <span class="result-message"><?= htmlspecialchars($result_message) ?></span>
                    </div>

                    <div class="rec-list">
                        <?php $rank = 1;
                        foreach ($top3 as $item):
                            $course = $item['name'];
                            $score  = $item['score'];
                            $pct    = $item['percentage'];
                            $skill_matches = $item['skill_matches'];
                            $interest_matches = $item['interest_matches'];
                            $career_matches = $item['career_matches'];
                            
                            $missing_skills = $item['missing_skills'];
                            $missing_interests = $item['missing_interests'];

                            $total_parts = $user_max_score > 0 ? $user_max_score : 1; 
                            $skill_w = (($skill_matches * 1) / $total_parts) * 100;
                            $int_w   = (($interest_matches * 1) / $total_parts) * 100;
                            $car_w   = (($career_matches * 2) / $total_parts) * 100;
                        ?>
                            <div class="rec-item">
                                <span class="rec-rank">#<?= $rank ?></span>
                                <span class="rec-name"><?= htmlspecialchars($course) ?></span>
                                <span class="rec-score">
                                    <?= $pct ?>% Match (<?= $score ?> pts)
                                </span>
                            </div>

                            <div style="padding: 0 1rem; margin-bottom: 0.5rem;">
                                <div class="score-breakdown">
                                    <div class="bar-segment bar-skill" style="width: <?= $skill_w ?>%;"></div>
                                    <div class="bar-segment bar-interest" style="width: <?= $int_w ?>%;"></div>
                                    <div class="bar-segment bar-career" style="width: <?= $car_w ?>%;"></div>
                                </div>
                                <div class="legend">
                                    <span><i class="dot" style="background:#3498db"></i> Skills</span>
                                    <span><i class="dot" style="background:#e67e22"></i> Interests</span>
                                    <span><i class="dot" style="background:#9b59b6"></i> Careers (x2 Weight)</span>
                                </div>
                            </div>

                            <div class="explanation">
                                <p><strong>Why #<?= $rank ?>?</strong></p>
                                <p style="margin: 0.8rem 0; font-weight:500;">
                                    <?php if ($rank === 1): ?>
                                        <strong>This is your #1 match.</strong> This course aligns perfectly with your skills, interests, and career goals.
                                    <?php elseif ($rank === 2): ?>
                                        <strong>Excellent second choice.</strong> Strong overlap with your profile, making it a very strong alternative.
                                    <?php else: ?>
                                        <strong>Solid backup option.</strong> Still a great fit based on your selections and criteria.
                                    <?php endif; ?>
                                </p>
                                
                                <p class="breakdown">
                                    Detailed Score Rationale:<br>
                                    - Skills Matches: <span class="highlight"><?= $skill_matches ?></span> (Total +<?= $skill_matches * 1 ?> points)<br>
                                    - Interest Matches: <span class="highlight"><?= $interest_matches ?></span> (Total +<?= $interest_matches * 1 ?> points)<br>
                                    - Career Matches: <span class="highlight"><?= $career_matches ?></span> (Total +<?= $career_matches * 2 ?> points)
                                </p>
                                
                                <?php if (!empty($selected_career_descs)): ?>
                                    <p>
                                        <strong>Your dream career:</strong> 
                                        <span class="highlight"><?= implode('</span> or <span class="highlight">', $selected_career_descs) ?></span>
                                    </p>
                                <?php endif; ?>

                                <?php if (!empty($missing_skills) || !empty($missing_interests)): ?>
                                    <div class="gap-analysis">
                                        <div class="gap-title"><i class="fas fa-exclamation-circle"></i> To improve your match for this course:</div>
                                        <?php if (!empty($missing_skills)): ?>
                                            <div style="margin-top:5px;">Consider developing these skills:</div>
                                            <div class="gap-list"><?= implode(', ', $missing_skills) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($missing_interests)): ?>
                                            <div style="margin-top:5px;">Check if you might be interested in:</div>
                                            <div class="gap-list"><?= implode(', ', $missing_interests) ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <small>
                                    FINAL Score: <strong><?= $score ?> points</strong> based on your preferences.
                                </small>
                            </div>
                        <?php $rank++; endforeach; ?>
                    </div>

                    <div class="feedback-section">
                        <p style="margin-bottom:10px; color:#666;">Was this recommendation helpful?</p>
                        
                        <div style="margin-bottom: 10px;">
                            <button class="feedback-btn" onclick="toggleComment('yes', this)"><i class="fas fa-thumbs-up"></i> Yes</button>
                            <button class="feedback-btn" onclick="toggleComment('no', this)"><i class="fas fa-thumbs-down"></i> No</button>
                        </div>

                        <textarea id="feedbackComment" class="feedback-textarea" rows="3" placeholder="Optional: Tell us why..."></textarea>
                        
                        <button id="submitFeedbackBtn" class="btn btn-primary" style="display:none; margin-top:10px; padding: 0.5rem 1rem; font-size: 0.9rem;" onclick="submitFinalFeedback()">
                            Submit Feedback
                        </button>
                    </div>

                    <div class="btn-group" style="margin-top:1.5rem;">
                        <button onclick="document.getElementById('recommendationForm').style.display='block'; document.getElementById('recommendationForm').scrollIntoView({behavior: 'smooth'})" class="btn btn-outline">
                            <i class="fas fa-pencil-alt"></i> Refine / Edit Selection
                        </button>
                        
                        <a href="?reset=1" class="btn btn-secondary">
                            <i class="fas fa-redo-alt"></i> Start New Recommendation
                        </a>

                        <a href="history.php" class="btn btn-primary">
                            View Full History
                        </a>
                        <button onclick="window.print()" class="btn" style="background: #34495e; color: white;">
                            <i class="fas fa-print"></i> Save/Print Report
                        </button>
                    </div>
                </div>
                
                <script>
                    document.addEventListener("DOMContentLoaded", function() {
                        document.getElementById('resultsArea').scrollIntoView({behavior: 'smooth'});
                    });
                </script>
                
            <?php elseif ($show_result && empty($top3)): ?>
                <div class="card error-message">
                    <?= $result_message ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        // --- STEPPER LOGIC ---
        function showStep(stepNumber) {
            document.querySelectorAll('.step-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.step-item').forEach(el => el.classList.remove('active'));
            
            document.getElementById('step' + stepNumber).classList.add('active');
            document.getElementById('nav-step' + stepNumber).classList.add('active');

            for(let i=1; i < stepNumber; i++) {
                document.getElementById('nav-step' + i).classList.add('completed');
            }
        }

        const menuToggle   = document.getElementById('menuToggle');
        const sidebar      = document.getElementById('sidebar');
        const mainContent  = document.getElementById('mainContent');
        const overlay      = document.getElementById('overlay');
        const logoutLink   = document.getElementById('logoutLink');
        const logoutPopup  = document.getElementById('logoutPopup');
        const form         = document.getElementById('recommendationForm');
        const processingOverlay = document.getElementById('processingOverlay');
        const loadingText = document.getElementById('loadingText');

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

        form.addEventListener('submit', function(e) {
            const skills = document.querySelectorAll('input[name="skills[]"]:checked').length;
            const interests = document.querySelectorAll('input[name="interests[]"]:checked').length;
            const careers = document.querySelectorAll('input[name="careers[]"]:checked').length;
            
            // Manual entries
            const otherSkill = document.querySelector('input[name="other_skill"]').value.trim() !== '';
            const otherInterest = document.querySelector('input[name="other_interest"]').value.trim() !== '';
            const otherCareer = document.querySelector('input[name="other_career"]').value.trim() !== '';

            const totalSkills = skills + (otherSkill ? 1 : 0);
            const totalInterests = interests + (otherInterest ? 1 : 0);
            const totalCareers = careers + (otherCareer ? 1 : 0);

            const errors = [];

            if (totalSkills < 2) errors.push("Please select at least 2 skills.");
            if (totalInterests < 2) errors.push("Please select at least 2 interests.");
            if (totalCareers < 1) errors.push("Please select at least 1 dream career.");

            if (errors.length > 0) {
                e.preventDefault();
                alert(errors.join("\n"));
            } else {
                e.preventDefault(); 
                processingOverlay.classList.add('active');
                
                setTimeout(() => { loadingText.innerText = "Matching skills against curriculum..."; }, 600);
                setTimeout(() => { loadingText.innerText = "Calculating career alignment scores..."; }, 1400);
                setTimeout(() => { loadingText.innerText = "Finalizing recommendations..."; }, 2200);

                setTimeout(() => {
                    form.submit();
                }, 2800);
            }
        });

        document.querySelectorAll('.search-box').forEach(input => {
            input.addEventListener('input', function() {
                const query = this.value.toLowerCase().trim();
                const container = this.closest('.checkbox-group');
                const items = container.querySelectorAll('.checkbox-item');

                items.forEach(item => {
                    const label = item.getAttribute('data-label');
                    if (label.includes(query)) {
                        item.style.display = 'flex';
                    } else {
                        item.style.display = 'none';
                    }
                });
            });
        });

        let selectedRating = null;

        function toggleComment(rating, btn) {
            selectedRating = rating;
            const buttons = btn.parentElement.querySelectorAll('.feedback-btn');
            buttons.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById('feedbackComment').classList.add('active');
            document.getElementById('submitFeedbackBtn').style.display = 'inline-block';
            document.getElementById('feedbackComment').focus();
        }

        function submitFinalFeedback() {
            if (!selectedRating) return;

            const commentText = document.getElementById('feedbackComment').value;
            const submitBtn = document.getElementById('submitFeedbackBtn');

            submitBtn.disabled = true;
            submitBtn.innerText = "Sending...";

            fetch('save_feedback.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    rating: selectedRating,
                    comment: commentText
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    document.querySelector('.feedback-section').innerHTML = 
                        '<p style="color:#56ab2f; font-weight:bold;"><i class="fas fa-check-circle"></i> Thank you! Your feedback has been saved.</p>';
                } else {
                    alert('Error: ' + data.message);
                    submitBtn.disabled = false;
                    submitBtn.innerText = "Submit Feedback";
                }
            })
            .catch((error) => {
                console.error('Error:', error);
                submitBtn.disabled = false;
                submitBtn.innerText = "Submit Feedback";
            });
        }
    </script>
</body>
</html>
<?php $mysqli->close(); ?>