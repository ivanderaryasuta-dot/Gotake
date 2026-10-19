<?php
/**
 * includes/header.php  —  THE TOP PART OF EVERY LOGGED-IN PAGE
 * ------------------------------------------------------------
 * HOW THE WHOLE SITE IS BUILT (same idea as your previous version)
 *
 *   Every page looks like this:
 *
 *        <?php
 *        $page_title = 'Something';              // (optional) name in the browser tab
 *        require_once 'includes/header.php';     // <-- THIS FILE: opens the layout
 *        ... the page's own HTML ...
 *        include 'includes/footer.php';          // closes the layout
 *        ?>
 *
 *   So the top bar + sidebar are written ONCE here, not in every page.
 *
 * WHAT THIS FILE DOES, STEP BY STEP
 *   1. Starts output buffering   (so a page can still redirect after this file ran)
 *   2. Starts the session        (PHP's memory of "who is logged in")
 *   3. Sends visitors who are not logged in to login.php
 *   4. Connects to the database and loads the helper functions
 *   5. Prints the HTML: blue top bar, white sidebar, opens the content area
 *
 * OPTIONAL VARIABLES A PAGE CAN SET *BEFORE* INCLUDING THIS FILE
 *   $page_title  = text for the browser tab, e.g. 'Item Catalog'
 *   $nav_active  = which sidebar item to highlight if it is NOT the
 *                  page's own file name (e.g. the borrow-request page
 *                  highlights "Item Catalog").
 * ------------------------------------------------------------
 */

// 1. OUTPUT BUFFERING
// Normally PHP cannot redirect (header("Location: ...")) after any HTML was
// already printed. The pages call header.php FIRST and may redirect afterwards
// (for example a student opening an admin page). ob_start() holds the HTML in
// memory until footer.php sends it, which makes those redirects work.
ob_start();

// 2. SESSION
if (session_status() === PHP_SESSION_NONE) session_start();

// 3. NOT LOGGED IN? -> go to the login page
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// 4. DATABASE + HELPERS
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';

// ---- Information used by the HTML below ----
$role         = $_SESSION['role'] ?? 'student';                 // 'admin' or 'student'
$initials     = initials($_SESSION['full_name'] ?? 'User');     // "Abu Dzar" -> "AD"
$current_page = $nav_active ?? basename($_SERVER['PHP_SELF']);  // file name of the current page
$site_name    = get_setting($pdo, 'site_name', 'GoTake - Campus Loan System');
$page_title   = $page_title ?? 'Dashboard';
$home_link    = ($role === 'admin') ? 'index.php' : 'student_dashboard.php';

// The menu: [ file name, label ].  Admins and students see different menus.
if ($role === 'admin') {
    $menu = [
        ['index.php',           'Dashboard'],
        ['manage_items.php',    'Manage Items'],
        ['manage_requests.php', 'Manage Request'],
        ['user_data.php',       'User Data'],
        ['reports.php',         'Reports'],
        ['settings.php',        'Settings'],
        ['profile.php',         'Profile Account'],
    ];
} else {
    $menu = [
        ['student_dashboard.php',  'Dashboard'],
        ['student_catalog.php',    'Item Catalog'],
        ['student_borrowings.php', 'My Borrowings'],
        ['student_activity.php',   'My Activity'],
        ['account_settings.php',   'Account Settings'],
        ['profile.php',            'My Profile'],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <!-- Viewport tag: REQUIRED for phones, otherwise they show the page zoomed out -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page_title) ?> - <?= e($site_name) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

    <!-- ============================================================
         TOP BAR (blue strip): logo on the left, user avatar on the right
         ============================================================ -->
    <header class="topbar">
        <div class="topbar-left">
            <!-- Hamburger button: only visible on phones, opens the sidebar -->
            <button class="menu-toggle" onclick="toggleSidebar()" aria-label="Open menu">☰</button>

            <!-- LOGO: put your file at  assets/img/logo.png  (or .svg / .jpg)  -->
            <a href="<?= e($home_link) ?>" class="brand">
                <?= img_slot('logo', 'brand-logo', 'GoTake logo') ?>
            </a>
        </div>

        <!-- Round avatar with the user's initials. Click = go to profile -->
        <a href="profile.php" class="user-avatar" title="<?= e($_SESSION['full_name'] ?? '') ?>">
            <?= e($initials) ?>
        </a>
    </header>

    <!-- Dark overlay behind the sidebar on phones. Clicking it closes the sidebar -->
    <div class="overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <div class="app-container">

        <!-- ============================================================
             SIDEBAR (white menu on the left)
             ============================================================ -->
        <aside class="sidebar" id="sidebar">
            <!-- Close (x) button: only visible on phones -->
            <button class="sidebar-close" onclick="toggleSidebar()" aria-label="Close menu">×</button>

            <div class="sidebar-title">CAMPUS LOAN SYSTEM</div>

            <nav>
                <?php foreach ($menu as [$file, $label]): ?>
                    <!-- class="active" is added to the link of the page you are on -->
                    <a href="<?= e($file) ?>" class="<?= $current_page === $file ? 'active' : '' ?>">
                        <span class="nav-icon"></span><?= e($label) ?>
                    </a>
                <?php endforeach; ?>

                <!-- Log out is the same for everybody -->
                <a href="logout.php"><span class="nav-icon"></span>Log Out</a>
            </nav>
        </aside>

        <!-- ============================================================
             MAIN CONTENT: each page writes its own HTML in here.
             footer.php closes these tags.
             ============================================================ -->
        <main class="main-content">
            <div class="content">
