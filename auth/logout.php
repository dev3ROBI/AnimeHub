<?php
session_start();
session_unset();
session_destroy();

// Clear cookies if set
setcookie("userID", "", time() - 3600, "/");
setcookie("userName", "", time() - 3600, "/");
setcookie("userRole", "", time() - 3600, "/");

header("Location: ../authentication.php");
exit;
?>