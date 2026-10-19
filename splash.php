<?php
/**
 * splash.php  —  LOADING SCREEN  (first screen in PBL.pdf)
 * ------------------------------------------------------------
 * WHAT THIS PAGE DOES
 *   Shows the logo and a loading bar for about 2 seconds, then
 *   automatically moves on to the login page. If the visitor is
 *   already logged in, it moves on to their dashboard instead.
 *
 *   It is optional: use it as your site's entry point (open
 *   splash.php) or ignore it and open login.php directly.
 *
 * The logo is the blank slot: put your file at assets/img/logo.png
 * ------------------------------------------------------------
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/includes/functions.php';

// Where should we go after the loading bar finishes?
$next = 'login.php';
if (isset($_SESSION['user_id'])) {
    $next = (($_SESSION['role'] ?? '') === 'admin') ? 'index.php' : 'student_dashboard.php';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GoTake - Loading</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <!-- Fallback: if JavaScript is off, the browser itself redirects after 3 seconds -->
    <noscript><meta http-equiv="refresh" content="3;url=<?= e($next) ?>"></noscript>
</head>
<body>
    <div class="splash">
        <?= img_slot('logo', 'brand-logo', 'GoTake') ?>

        <!-- The bar fills over 2.2 seconds (CSS animation .progress-fill) -->
        <div class="progress"><div class="progress-fill"></div></div>
    </div>

    <script>
        // After 2.4 seconds, go to the next page.
        setTimeout(function () { window.location.href = <?= json_encode($next) ?>; }, 2400);
    </script>
</body>
</html>
