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

// Unread notifications (students): shown as a small number next to "Notifications" in the menu
$unread_count = 0;
if ($role === 'student') {
    try {
        $c = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = FALSE");
        $c->execute([$_SESSION['user_id']]);
        $unread_count = (int)$c->fetchColumn();
    } catch (Exception $e) { $unread_count = 0; }
}

// The menu: [ file name, label ].  Admins and students see different menus.
if ($role === 'admin') {
    $menu = [
        ['index.php',           'Dashboard'],
        ['manage_items.php',    'Manage Items'],
        ['manage_requests.php', 'Manage Request'],
        ['damage_reports.php',  'Damage Reports'],
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
        ['notifications.php',      'Notifications'],
        ['profile.php',            'My Profile'],
    ];
}

/**
 * nav_icon()  —  the small drawing in front of each menu item (inline SVG, no image files, works offline).
 * To change an icon, replace its <path>/<line> shapes; the key is the page file name.
 */
function nav_icon(string $file): string
{
    $icons = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/>',
        'box'       => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
        'book'      => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
        'clipboard' => '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/>',
        'archive'   => '<polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5" rx="1"/><line x1="10" y1="12" x2="14" y2="12"/>',
        'alert'     => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'users'     => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'chart'     => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
        'sliders'   => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
        'user'      => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'activity'  => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
        'bell'      => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
        'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'menu'      => '<line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>',
    ];
    $by_page = [
        'index.php' => 'dashboard', 'student_dashboard.php' => 'dashboard',
        'manage_items.php' => 'box', 'student_catalog.php' => 'book',
        'manage_requests.php' => 'clipboard', 'student_borrowings.php' => 'archive',
        'damage_reports.php' => 'alert', 'user_data.php' => 'users',
        'reports.php' => 'chart', 'settings.php' => 'sliders',
        'profile.php' => 'user', 'student_activity.php' => 'activity',
        'notifications.php' => 'bell', 'logout.php' => 'logout',
    ];
    $name = $by_page[$file] ?? $file;               // a key of $icons can also be used directly ('menu', 'bell' ...)
    $shapes = $icons[$name] ?? $icons['dashboard'];
    return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $shapes . '</svg>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <!-- Viewport tag: REQUIRED for phones, otherwise they show the page zoomed out -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page_title) ?> - <?= e($site_name) ?></title>
    <?= bootstrap_head() ?>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

    <!-- ============================================================
         TOP BAR (blue strip): logo on the left, user avatar on the right
         ============================================================ -->
    <header class="topbar">
        <div class="topbar-left">
            <!-- Hamburger button: only visible on phones, opens the sidebar -->
            <button class="menu-toggle" onclick="toggleSidebar()" aria-label="Open menu"><?= nav_icon('menu') ?></button>

            <!-- LOGO: put your file at  assets/img/logo.png  (or .svg / .jpg)  -->
            <a href="<?= e($home_link) ?>" class="brand">
                <?= img_slot('logo', 'brand-logo', 'GoTake logo') ?>
            </a>
        </div>

        <div class="topbar-right">
            <?php if ($role === 'student'): ?>
                <!-- Bell with the number of unread notifications -->
                <a href="notifications.php" class="topbar-bell" title="Notifications" aria-label="Notifications">
                    <?= nav_icon('bell') ?>
                    <?php if ($unread_count > 0): ?><span class="topbar-badge"><?= $unread_count > 9 ? '9+' : $unread_count ?></span><?php endif; ?>
                </a>
            <?php endif; ?>

            <!-- Name + role (hidden on phones) -->
            <div class="topbar-user">
                <span class="topbar-name"><?= e($_SESSION['full_name'] ?? '') ?></span>
                <span class="topbar-role"><?= $role === 'admin' ? 'Administrator' : 'Student' ?></span>
            </div>

            <!-- Round avatar with the user's initials. Click = go to profile -->
            <a href="profile.php" class="user-avatar" title="<?= e($_SESSION['full_name'] ?? '') ?>">
                <?= e($initials) ?>
            </a>
        </div>
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
                        <span class="nav-icon"><?= nav_icon($file) ?></span><?= e($label) ?>
                        <?php if ($file === 'notifications.php' && $unread_count > 0): ?><span class="notif-new"><?= $unread_count > 99 ? '99+' : $unread_count ?></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>

                <!-- Log out is the same for everybody -->
                <div class="nav-divider"></div>
                <a href="logout.php" class="nav-logout"><span class="nav-icon"><?= nav_icon('logout.php') ?></span>Log Out</a>
            </nav>
        </aside>

        <!-- ============================================================
             MAIN CONTENT: each page writes its own HTML in here.
             footer.php closes these tags.
             ============================================================ -->
        <main class="main-content">
            <div class="content">
