<?php
/**
 * includes/auth_header.php  —  TOP PART OF THE LOGIN / REGISTER / FORGOT-PASSWORD PAGES
 * ------------------------------------------------------------
 * These pages are for people who are NOT logged in yet, so they cannot use
 * includes/header.php (that one sends visitors to login.php if they are not
 * logged in, which would loop forever).
 *
 * This file prints:   blue top bar (logo)  +  left picture area  +  opens the form area.
 * includes/auth_footer.php closes it.
 *
 * Optional variable a page can set BEFORE including this file:
 *   $page_title = 'Login'     (text in the browser tab)
 * ------------------------------------------------------------
 */
ob_start();                                              // see header.php for why
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';

$page_title = $page_title ?? 'Welcome';
$site_name  = get_setting($pdo, 'site_name', 'GoTake - Campus Loan System');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page_title) ?> - <?= e($site_name) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-body">

    <!-- Blue top bar with the logo (put assets/img/logo.png) -->
    <header class="topbar topbar-static">
        <a href="login.php" class="brand"><?= img_slot('logo', 'brand-logo', 'GoTake logo') ?></a>
    </header>

    <div class="auth-wrap">

        <!-- LEFT SIDE: big picture (the shopping-cart illustration in the design).
             Put your file at  assets/img/auth-banner.png  -->
        <div class="auth-art">
            <?= img_slot('GotakeTroli', '', 'Just borrow, go take') ?>
        </div>

        <!-- RIGHT SIDE: the form. The page writes its own form after this line. -->
        <div class="auth-panel">
            <div class="auth-card">
