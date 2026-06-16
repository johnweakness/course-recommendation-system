<?php
session_start();
unset($_SESSION['user_auth']);  // Only destroy user part
session_regenerate_id(true);
header("Location: index.php");
exit;
?>