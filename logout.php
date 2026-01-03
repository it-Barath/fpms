<?php
// logout.php - Debug version
session_start();
session_unset();
session_destroy();

// Clear all cookies
if (isset($_SERVER['HTTP_COOKIE'])) {
    $cookies = explode(';', $_SERVER['HTTP_COOKIE']);
    foreach($cookies as $cookie) {
        $parts = explode('=', $cookie);
        $name = trim($parts[0]);
        setcookie($name, '', time()-1000);
        setcookie($name, '', time()-1000, '/');
    }
}

// Clear session cookie
setcookie(session_name(), '', time()-3600, '/');

// Redirect to login
header('Location: login.php?loggedout=1');
exit();
?>