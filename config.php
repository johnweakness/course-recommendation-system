<?php
// Start session with secure settings
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
    ini_set('session.use_strict_mode', 1);
    session_start();
}

// === DATABASE CONNECTION ===
$host = 'localhost';
$db   = 'course_recommender';
$user = 'root';
$pass = '';

if (getenv('DB_HOST')) {
    $host = getenv('DB_HOST');
    $db   = getenv('DB_NAME');
    $user = getenv('DB_USER');
    $pass = getenv('DB_PASS');
}

$mysqli = new mysqli($host, $user, $pass, $db);

if ($mysqli->connect_error) {
    error_log("Database connection failed: " . $mysqli->connect_error);
    http_response_code(500);
    die("We're experiencing technical difficulties. Please try again later.");
}

// ✅ Use UTC everywhere
date_default_timezone_set('UTC');
$mysqli->query("SET time_zone = '+00:00'");

$mysqli->set_charset('utf8mb4');

// === SITE CONFIGURATION ===
define('SITE_NAME', 'Course Recommendation System');
define('LOGO', 'logo.png');
define('BASE_URL', 'http://localhost/course-recommender/');
