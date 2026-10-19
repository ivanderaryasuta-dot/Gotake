<?php
/**
 * logout.php  —  LOG OUT
 * ------------------------------------------------------------
 * WHAT THIS FILE DOES
 *   Forgets who the visitor is and sends them back to the login page.
 *   (The sidebar "Log Out" links point here. Your previous version's
 *   menu linked to this file but it did not exist yet.)
 * ------------------------------------------------------------
 */
if (session_status() === PHP_SESSION_NONE) session_start();

// 1. Empty the session data (user_id, full_name, role ...)
$_SESSION = [];

// 2. Delete the session cookie from the browser
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}

// 3. Destroy the session on the server
session_destroy();

// 4. Back to the login page
header('Location: login.php');
exit;
