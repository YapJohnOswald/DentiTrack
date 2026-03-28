<?php
session_start();
if (isset($_COOKIE['remember_token'])) {
  setcookie('remember_token', '', time() - 3600, "/");
}
session_unset();
session_destroy();
header('Location: ../public/login.php'); // or where your login is
exit;
?>
