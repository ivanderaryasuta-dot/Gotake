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

/* ============================================================
   10. PHOTOS  (item photos + pick-up / return evidence photos)  — NEW in v3
   ============================================================ */

/**
 * The sides that can be photographed, and which of them are REQUIRED.
 * Required sides are shown with a red * in the forms. To change the rule
 * (for example make "top" required too), edit PHOTO_REQUIRED here only.
 */
const PHOTO_SIDES    = ['front' => 'Front', 'back' => 'Back', 'left' => 'Left', 'right' => 'Right', 'top' => 'Top'];
const PHOTO_REQUIRED = ['front', 'back', 'left', 'right'];

/**
 * save_photo()  —  safely stores ONE uploaded photo and returns its path.
 *
 * $file     = the entry from $_FILES (for example $_FILES['photo'])
 * $subdir   = folder inside /uploads, e.g. 'loans/12'
 * $basename = start of the file name, e.g. 'pickup_5_front'
 *
 * Safety checks (never trust what the browser says):
 *   - the upload really succeeded and is smaller than 5 MB
 *   - the file CONTENT is a JPG, PNG or WEBP picture (checked with finfo,
 *     not just the file name ending)
 *   - the final file name is generated by us, so nobody can upload "x.php"
 * Throws an Exception with a readable message if anything is wrong.
 */
function save_photo(array $file, string $subdir, string $basename): string
{
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new Exception('Photo is bigger than the server limit. Raise upload_max_filesize and post_max_size in php.ini (or use a smaller photo).');
    }
    if ($error !== UPLOAD_ERR_OK) throw new Exception('Please choose a photo to upload.');
    if ($file['size'] > 5 * 1024 * 1024) throw new Exception('Photo must be smaller than 5 MB.');

    $mime    = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) throw new Exception('Only JPG, PNG or WEBP photos are allowed.');

    $subdir = preg_replace('/[^A-Za-z0-9_\-\/]/', '', $subdir);
    $dir    = __DIR__ . '/../uploads/' . $subdir;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new Exception('Could not create the uploads folder.');

    // time() in the name makes a replaced photo get a NEW address, so the browser never shows the old picture
    $name = preg_replace('/[^A-Za-z0-9_\-]/', '', $basename) . '_' . time() . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        throw new Exception('Could not save the photo. Is the uploads folder writable?');
    }
    return 'uploads/' . $subdir . '/' . $name;
}

/**
 * delete_photo_file()  —  removes a photo file from disk.
 * Only files inside /uploads can be deleted (protects the rest of the site).
 */
function delete_photo_file(?string $path): void
{
    if (!$path || strpos($path, 'uploads/') !== 0 || strpos($path, '..') !== false) return;
    $full = __DIR__ . '/../' . $path;
    if (is_file($full)) @unlink($full);
}

/**
 * photo_box()  —  one photo for display (read-only), or a blank box if missing.
 */
function photo_box(?string $path, string $alt = ''): string
{
    if ($path) {
        return '<a href="' . e($path) . '" target="_blank"><img src="' . e($path) . '" alt="' . e($alt) . '"></a>';
    }
    return '<span class="photo-empty">No photo</span>';
}

/**
 * item_photo_html()  —  the picture of an item in the catalog / borrow request.
 * Uses the admin-uploaded FRONT photo if there is one; otherwise falls back to
 * assets/img/items/<CODE>.png (the old blank-slot method).
 */
function item_photo_html(?string $front_path, string $code, string $name): string
{
    if ($front_path && is_file(__DIR__ . '/../' . $front_path)) {
        return '<img src="' . e($front_path) . '" alt="' . e($name) . '">';
    }
    return img_slot('items/' . $code, '', $name);
}

/* ------------------------------------------------------------
   WHERE THINGS ARE STORED  (no database changes needed)
   ------------------------------------------------------------
   Everything new in v3 lives in the  uploads/  folder:

     uploads/items/<item id>/<side>_<time>.jpg                     reference photos of an item
     uploads/items/<item id>/meta.json                             {"jurusan": "Informatics"}
     uploads/loans/<loan id>/<phase>_<item id>_<side>_<time>.jpg   pick-up / return photos
     uploads/loans/<loan id>/meta.json                             verification, fine paid, damage text

   Your database keeps what it already had: condition_after, the fine
   (returns.compensation_amount), the note (returns.notes) and notifications.
   ------------------------------------------------------------ */

/**
 * meta_read() / meta_write()  —  tiny JSON "notes" file kept next to the photos.
 * $dir is relative to /uploads, e.g. 'loans/12'.
 */
function meta_read(string $dir): array
{
    $file = __DIR__ . '/../uploads/' . $dir . '/meta.json';
    if (!is_file($file)) return [];
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function meta_write(string $dir, array $data): void
{
    $dir  = preg_replace('/[^A-Za-z0-9_\-\/]/', '', $dir);
    $path = __DIR__ . '/../uploads/' . $dir;
    if (!is_dir($path) && !mkdir($path, 0755, true)) throw new Exception('Could not create the uploads folder.');
    // LOCK_EX = nobody else can write at the same moment
    file_put_contents($path . '/meta.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/**
 * item_photos()  —  reference photos of an item:  ['front' => 'uploads/items/5/front_1730.jpg', ...]
 * If a side has several files (it should not), the newest one wins.
 */
function item_photos(int $item_id): array
{
    $found = [];
    foreach (glob(__DIR__ . '/../uploads/items/' . $item_id . '/*') ?: [] as $file) {
        if (!preg_match('/^(front|back|left|right|top)_(\d+)\.(jpg|png|webp)$/', basename($file), $m)) continue;
        if (!isset($found[$m[1]]) || (int)$m[2] > $found[$m[1]][0]) {
            $found[$m[1]] = [(int)$m[2], 'uploads/items/' . $item_id . '/' . basename($file)];
        }
    }
    return array_map(function ($x) { return $x[1]; }, $found);
}

/**
 * loan_photos()  —  pick-up / return photos of a loan, as $photos[item_id][phase][side] = path
 */
function loan_photos(int $loan_id): array
{
    $found = [];
    foreach (glob(__DIR__ . '/../uploads/loans/' . $loan_id . '/*') ?: [] as $file) {
        if (!preg_match('/^(pickup|return)_(\d+)_(front|back|left|right|top)_(\d+)\.(jpg|png|webp)$/', basename($file), $m)) continue;
        [, $phase, $item, $side, $time] = $m;
        if (!isset($found[$item][$phase][$side]) || (int)$time > $found[$item][$phase][$side][0]) {
            $found[$item][$phase][$side] = [(int)$time, 'uploads/loans/' . $loan_id . '/' . basename($file)];
        }
    }
    $result = [];
    foreach ($found as $item => $phases)
        foreach ($phases as $phase => $sides)
            foreach ($sides as $side => $x) $result[(int)$item][$phase][$side] = $x[1];
    return $result;
}

/**
 * remove_photo_files()  —  deletes every stored file of ONE photo slot.
 * $dir = 'items/5' or 'loans/12',  $prefix = 'front' or 'pickup_7_front'
 */
function remove_photo_files(string $dir, string $prefix): void
{
    $dir    = preg_replace('/[^A-Za-z0-9_\-\/]/', '', $dir);
    $prefix = preg_replace('/[^A-Za-z0-9_\-]/', '', $prefix);
    foreach (glob(__DIR__ . '/../uploads/' . $dir . '/' . $prefix . '_*') ?: [] as $file) {
        if (preg_match('/_\d+\.(jpg|png|webp)$/', $file)) @unlink($file);
    }
}

/**
 * item_jurusan()  —  the jurusan (department) an item belongs to ('' = shared by all)
 */
function item_jurusan(int $item_id): string
{
    return (string)(meta_read('items/' . $item_id)['jurusan'] ?? '');
}

/**
 * missing_photo_sides()  —  which REQUIRED photos are still missing for a loan?
 * Returns a list of readable messages, e.g. ["Epson Projector: Front, Left"].
 * An empty list means everything required is there.   $phase = 'pickup' or 'return'
 */
function missing_photo_sides(PDO $pdo, int $loan_id, string $phase): array
{
    $stmt = $pdo->prepare("SELECT li.item_id, i.name FROM loan_items li JOIN items i ON i.id = li.item_id WHERE li.loan_id = ? ORDER BY i.name");
    $stmt->execute([$loan_id]);
    $have = loan_photos($loan_id);

    $problems = [];
    foreach ($stmt->fetchAll() as $it) {
        $missing = [];
        foreach (PHOTO_REQUIRED as $side) {
            if (empty($have[$it['item_id']][$phase][$side])) $missing[] = PHOTO_SIDES[$side];
        }
        if ($missing) $problems[] = $it['name'] . ': ' . implode(', ', $missing);
    }
    return $problems;
}

/**
 * render_photo_compare()  —  table "pick-up photo | return photo" for each item.
 * Used by the admin loan detail page and the student loan detail page.
 *   $items  = rows with item_id, name, code, qty, condition_before, condition_after
 *   $photos = $photos[item_id][phase][side] = file path
 */
function render_photo_compare(array $items, array $photos): string
{
    $html = '';
    foreach ($items as $it) {
        $id    = $it['item_id'];
        $html .= '<div class="panel"><h3>' . e($it['name']) . ' <span class="muted" style="font-weight:500;">(' . e($it['code']) . ') x' . (int)$it['qty'] . '</span></h3>';
        $html .= '<div class="table-wrapper"><table style="min-width:420px;"><thead><tr><th>Side</th><th>At pick-up</th><th>At return</th></tr></thead><tbody>';
        foreach (PHOTO_SIDES as $side => $label) {
            $pick = $photos[$id]['pickup'][$side] ?? null;
            $ret  = $photos[$id]['return'][$side] ?? null;
            if (!$pick && !$ret) continue;       // skip sides nobody photographed
            $html .= '<tr><td>' . e($label) . '</td><td class="photo-cell">' . photo_box($pick, $label . ' at pick-up') . '</td>'
                   . '<td class="photo-cell">' . photo_box($ret, $label . ' at return') . '</td></tr>';
        }
        $html .= '</tbody></table></div></div>';
    }
    return $html;
}

/**
 * add_notification()  —  writes an in-app notification for a user.
 * The student sees it as a pop-up (includes/footer.php) the next time a page loads.
 * $type examples: picked_up, returned, damage_fine, fine_paid
 */
function add_notification(PDO $pdo, int $user_id, ?int $loan_id, string $type, string $message): void
{
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, loan_id, type, message) VALUES (?, ?, ?, ?)");
    $stmt->execute([$user_id, $loan_id, $type, $message]);
}

/**
 * format_rupiah()  —  25000 -> "Rp 25.000"
 */
function format_rupiah($amount): string
{
    return 'Rp ' . number_format((float)$amount, 0, ',', '.');
}
