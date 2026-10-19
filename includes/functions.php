<?php
/**
 * includes/functions.php  —  SMALL HELPER FUNCTIONS ("the toolbox")
 * ------------------------------------------------------------
 * WHAT THIS FILE DOES
 *   Holds little functions that many pages need, so we write them
 *   ONCE here instead of copy-pasting them into every page.
 *   It is loaded automatically by includes/header.php (and by the
 *   login / register / API files that need it).
 *
 * NEW IN THIS VERSION
 *   Your old version had no shared helpers. Everything here is new,
 *   but it does not change how your database or API works.
 * ------------------------------------------------------------
 */

/* ============================================================
   1. SAFE OUTPUT
   ============================================================ */

/**
 * e()  =  "escape".
 * Any text that comes from a user or the database MUST pass through
 * this before being printed in HTML. It turns characters like < and >
 * into harmless text, which stops "XSS" attacks (someone typing
 * <script> into a form field).
 *
 * Usage in a page:   <?= e($row['full_name']) ?>
 */
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* ============================================================
   2. IMAGE SLOTS  (this is where YOUR images go)
   ============================================================ */

/**
 * img_slot()  —  shows an image if the file exists, otherwise a BLANK box.
 *
 * You asked for every image to be left blank so you can add them yourself.
 * So all images in the site are printed with this function:
 *
 *   - If you drop a file into  assets/img/  with the right name,
 *     it appears automatically (no code change needed).
 *   - If the file is missing, a blank dashed box is shown instead.
 *
 * $base  = file name WITHOUT the extension, for example 'logo'.
 *          Allowed extensions: png, jpg, jpeg, webp, svg, gif
 * $class = extra CSS class(es) to control the size/shape.
 * $alt   = text for screen readers.
 *
 * Example:  img_slot('logo', 'brand-logo', 'GoTake')
 *           -> looks for assets/img/logo.png (or .jpg, .svg ...)
 */
function img_slot(string $base, string $class = '', string $alt = ''): string
{
    // Keep only safe characters so nobody can sneak in "../" tricks.
    $base = preg_replace('/[^A-Za-z0-9_\-\/]/', '', $base);

    foreach (['png', 'jpg', 'jpeg', 'webp', 'svg', 'gif'] as $ext) {
        $relative = $base . '.' . $ext;                       // e.g. logo.png
        if (is_file(__DIR__ . '/../assets/img/' . $relative)) { // does the file exist on disk?
            return '<img src="assets/img/' . e($relative) . '" alt="' . e($alt)
                 . '" class="' . e($class) . '">';
        }
    }

    // File not found -> blank placeholder. Hover over it to see the file name it wants.
    return '<div class="img-placeholder ' . e($class) . '" '
         . 'title="Put your image here: assets/img/' . e($base) . '.png"></div>';
}

/* ============================================================
   3. PASSWORDS
   ============================================================ */

/**
 * hash_new_password()  —  turns a password into a scrambled "hash".
 * We store the hash in the database, never the real password.
 */
function hash_new_password(string $plain): string
{
    return password_hash($plain, PASSWORD_DEFAULT);
}

/**
 * verify_password()  —  checks a typed password against what is stored.
 *
 * Your OLD version saved passwords as plain text and compared them
 * directly. To keep your existing accounts working, this function
 * accepts BOTH:
 *   - a real hash (starts with $2y$ ...)  -> checked with password_verify()
 *   - an old plain-text password          -> compared directly
 * New accounts (register / admin-created / password reset) always
 * get a real hash.
 */
function verify_password(string $typed, string $stored): bool
{
    if (is_hashed($stored)) {
        return password_verify($typed, $stored);
    }
    return hash_equals($stored, $typed);   // legacy plain-text comparison
}

/**
 * is_hashed()  —  does this stored value LOOK like a real password hash?
 * bcrypt hashes start with $2y$ (PHP) or $2a$ / $2b$ (other tools);
 * argon2 hashes start with $argon2.
 */
function is_hashed(string $stored): bool
{
    return (bool)preg_match('/^\$(2[abxy]|argon2)/', $stored);
}

/* ============================================================
   4. SETTINGS TABLE (key / value pairs)
   ============================================================ */

/**
 * get_setting()  —  reads one value from the `settings` table.
 * Returns $default if the key (or the table) does not exist,
 * so a missing setting never crashes the page.
 */
function get_setting(PDO $pdo, string $key, $default = null)
{
    try {
        $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = ?");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return ($value === false || $value === null) ? $default : $value;
    } catch (Exception $e) {
        return $default;
    }
}

/**
 * set_setting()  —  saves one value into the `settings` table.
 * "UPSERT": inserts the key, or updates it if it already exists.
 */
function set_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO settings (key, value) VALUES (?, ?)
         ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()"
    );
    $stmt->execute([$key, $value]);
}

/**
 * setting_bool()  —  reads a yes/no setting.
 * Your database stores yes/no settings as the TEXT 'true' or 'false'
 * (see the seed data in gotake_schema.sql), so we compare with that.
 * ('1' and 'yes' are accepted too, just in case.)
 */
function setting_bool(PDO $pdo, string $key, bool $default = false): bool
{
    $value = get_setting($pdo, $key, $default ? 'true' : 'false');
    return in_array(strtolower((string)$value), ['true', '1', 'yes', 'on'], true);
}

/* ============================================================
   5. LOGIN / ROLE CHECKS
   ============================================================ */

/**
 * require_role()  —  "only admins (or only students) may see this page".
 * If the logged-in user has the wrong role, send them to THEIR OWN
 * dashboard instead.
 */
function require_role(string $role): void
{
    $current = $_SESSION['role'] ?? '';
    if ($current !== $role) {
        header('Location: ' . ($current === 'admin' ? 'index.php' : 'student_dashboard.php'));
        exit;
    }
}

/**
 * current_user()  —  loads the full database row of the logged-in user.
 * Used by the profile pages.
 */
function current_user(PDO $pdo): array
{
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: [];
}

/* ============================================================
   6. SMALL DISPLAY HELPERS
   ============================================================ */

/**
 * initials()  —  "Abu Dzar Gifari" -> "AD"   (used for the round avatar)
 */
function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $first  = isset($parts[0]) ? substr($parts[0], 0, 1) : '';
    $second = isset($parts[1]) ? substr($parts[1], 0, 1) : '';
    return strtoupper($first . $second);
}

/**
 * status_badge()  —  prints the coloured pill for a loan status.
 * The database says "requested"; the design says "Pending", so we
 * translate it here. (Loan statuses in your database: requested, approved,
 * rejected, borrowed, returned. "overdue" is calculated by the view.) The CSS class (e.g. .badge.approved) gives the colour.
 */
function status_badge(string $status): string
{
    $class  = strtolower($status);
    $labels = ['requested' => 'Pending'];
    $label  = $labels[$class] ?? ucwords(str_replace('_', ' ', $class));
    return '<span class="badge ' . e($class) . '">' . e($label) . '</span>';
}

/**
 * format_date()  —  "2026-09-17" -> "17 Sep 2026"  (the format used in the design)
 */
function format_date($date, string $format = 'd M Y'): string
{
    if (empty($date)) return '—';
    return date($format, strtotime($date));
}

/* ============================================================
   7. FLASH MESSAGES  (a message that shows ONCE after a redirect)
   ============================================================ */

/**
 * flash_set()   saves a message in the session.
 * flash_render() prints it (and deletes it so it only shows once).
 * $type = 'success' | 'danger' | 'info'  (matches the .alert-* CSS classes)
 */
function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'msg' => $message];
}

function flash_render(): string
{
    if (empty($_SESSION['flash'])) return '';
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);   // show only once
    return '<div class="alert alert-' . e($flash['type']) . '">' . e($flash['msg']) . '</div>';
}

/* ============================================================
   8. PAGINATION  ( [1] [2] [3] buttons under tables )
   ============================================================ */

/**
 * render_pagination()
 * $page         = the page we are on now
 * $total_pages  = how many pages exist
 * $params       = other URL parameters to keep (search text, filters ...)
 */
function render_pagination(int $page, int $total_pages, array $params = []): string
{
    if ($total_pages <= 1) return '';

    $html = '<div class="pagination">';
    for ($i = 1; $i <= $total_pages; $i++) {
        $query = http_build_query(array_merge($params, ['page' => $i]));
        $html .= '<a href="?' . e($query) . '" class="' . ($i === $page ? 'active' : '') . '">' . $i . '</a>';
    }
    return $html . '</div>';
}

/* ============================================================
   9. JSON ANSWERS FOR THE api/ FILES
   ============================================================ */

/**
 * json_out()  —  sends a JSON answer to the browser and stops.
 * The JavaScript on the page reads {success: true/false, error: "..."}.
 */
function json_out(array $data): void
{
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * api_require_admin()  —  used at the top of admin-only api/ files.
 * Stops with an "Unauthorized" JSON answer if the caller is not a logged-in admin.
 */
function api_require_admin(): void
{
    if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
        json_out(['success' => false, 'error' => 'Unauthorized.']);
    }
}
