<?php
/**
 * includes/bootstrap_assets.php  —  ONE PLACE TO SWITCH BOOTSTRAP ON / OFF
 * ------------------------------------------------------------
 * USE_BOOTSTRAP = false  -> the site goes back to exactly how it was before
 *                           (old alert boxes and the old notification pop-up).
 *
 * bootstrap_head()    the <link> for assets/css/bootstrap-layer.css   (goes in <head>)
 * bootstrap_script()  Bootstrap's JavaScript + the small starter that shows the toasts
 *                     (goes just before </body>)
 *
 * OFFLINE USE: put bootstrap.bundle.min.js in assets/vendor/bootstrap/ and it is used
 * instead of the internet copy (see also the note in assets/css/bootstrap-layer.css).
 * ------------------------------------------------------------
 */
const USE_BOOTSTRAP = true;
const BOOTSTRAP_JS_CDN = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js';

function bootstrap_head(): string
{
    return USE_BOOTSTRAP ? '<link rel="stylesheet" href="assets/css/bootstrap-layer.css">' : '';
}

function bootstrap_script(): string
{
    if (!USE_BOOTSTRAP) return '';
    $local = 'assets/vendor/bootstrap/bootstrap.bundle.min.js';
    $src   = is_file(__DIR__ . '/../' . $local) ? $local : BOOTSTRAP_JS_CDN;

    return '<script src="' . htmlspecialchars($src, ENT_QUOTES) . '"></script>' . "\n"
         . '<script>' . "\n"
         . '// Show every toast marked data-gt-show (flash messages + notification pop-up).' . "\n"
         . '// If Bootstrap could not load, the toast is simply shown without animation.' . "\n"
         . 'document.querySelectorAll(".toast[data-gt-show]").forEach(function (el) {' . "\n"
         . '    if (window.bootstrap) { bootstrap.Toast.getOrCreateInstance(el).show(); } else { el.classList.add("show"); }' . "\n"
         . '});' . "\n"
         . '</script>';
}
