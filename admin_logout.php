<?php
session_start();
unset($_SESSION['admin_auth']); // Only destroy admin part
session_regenerate_id(true);
header("Location: admin_login.php");
exit;
?>