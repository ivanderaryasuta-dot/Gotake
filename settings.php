<?php
/**
 * settings.php  —  SYSTEM SETTINGS  (NEW PAGE, "Settings" in PBL.pdf)
 * ------------------------------------------------------------
 * WHAT THIS PAGE DOES
 *   System-wide options for the whole loan platform, saved in the
 *   `settings` table (rows of key + value). Same table your previous
 *   version already used for  max_loan_days.
 *
 *     site_name           name shown in the browser tab
 *     site logo           uploaded file -> assets/img/logo.(png|svg|...)
 *     require_collateral  'true' = students must agree to leave KTM/KTP
 *     max_loan_days       longest allowed loan (a database trigger enforces it)
 *     notify_admin_new_req   e-mail admins about new requests   (saved only)
 *     notify_admin_overdue   e-mail about overdue returns       (saved only)
 *   (Yes/No settings are stored as the text 'true' / 'false', like in your seed data.)
 *
 * NOTE: the two notification switches are SAVED but no e-mails are
 * sent yet (that needs a mail server + scheduled job).
 *
 * LOGO UPLOAD
 *   The file is stored as assets/img/logo.<ext>. Because every page
 *   shows the logo with img_slot('logo'), it appears everywhere at once.
 *   You can also just copy your logo file there by hand.
 * ------------------------------------------------------------
 */
$page_title = 'Settings';
require_once 'includes/header.php';
require_role('admin');

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $site_name = trim($_POST['site_name'] ?? '');
        $max_days  = (int)($_POST['max_loan_days'] ?? 0);

        if ($site_name === '')            throw new Exception('Site name cannot be empty.');
        if ($max_days < 1 || $max_days > 60) throw new Exception('Maximum loan duration must be between 1 and 60 days.');

        // ---- Logo upload (optional) ----
        if (!empty($_FILES['logo']['name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'svg', 'jpg', 'jpeg', 'webp'])) throw new Exception('Logo must be .png, .svg, .jpg or .webp');
            if ($_FILES['logo']['size'] > 2 * 1024 * 1024)               throw new Exception('Logo must be smaller than 2 MB.');

            // Remove any older logo.* so the new one is the one that is found
            foreach (['png', 'jpg', 'jpeg', 'webp', 'svg', 'gif'] as $old_ext) {
                $old = __DIR__ . '/assets/img/logo.' . $old_ext;
                if (is_file($old)) unlink($old);
            }
            if (!move_uploaded_file($_FILES['logo']['tmp_name'], __DIR__ . '/assets/img/logo.' . $ext)) {
                throw new Exception('Could not save the logo. Is the assets/img folder writable?');
            }
        }

        // ---- Save the rest into the settings table (checkbox: ticked = sent, unticked = missing) ----
        set_setting($pdo, 'site_name',          $site_name);
        set_setting($pdo, 'max_loan_days',      (string)$max_days);
        set_setting($pdo, 'require_collateral',    !empty($_POST['require_collateral'])    ? 'true' : 'false');
        set_setting($pdo, 'notify_admin_new_req',  !empty($_POST['notify_admin_new_req'])  ? 'true' : 'false');
        set_setting($pdo, 'notify_admin_overdue',  !empty($_POST['notify_admin_overdue'])  ? 'true' : 'false');

        $success = 'Settings saved.';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Read the current values (after a save too, so the form shows what was stored)
$cfg = [
    'site_name'            => get_setting($pdo, 'site_name', 'GoTake - Campus Loan System'),
    'require_collateral'   => setting_bool($pdo, 'require_collateral', true),
    'max_loan_days'        => (int)get_setting($pdo, 'max_loan_days', 3),
    'notify_admin_new_req' => setting_bool($pdo, 'notify_admin_new_req', true),
    'notify_admin_overdue' => setting_bool($pdo, 'notify_admin_overdue', false),
];
?>

<h1>Settings</h1>
<p class="subtitle">System-wide preferences for the loan platform</p>

<?php if ($error):   ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

<!-- enctype="multipart/form-data" is REQUIRED for a form that uploads a file -->
<form method="POST" enctype="multipart/form-data" class="page-narrow">

    <div class="panel">
        <h3>General</h3>

        <div class="form-group">
            <label for="site_name">Site name</label>
            <input type="text" id="site_name" name="site_name" class="form-control" value="<?= e($cfg['site_name']) ?>" required>
        </div>

        <div class="form-group" style="margin-bottom:0;">
            <label>Site logo</label>
            <label class="upload-box">
                Click to upload logo (.svg, .png)
                <input type="file" name="logo" accept=".svg,.png,.jpg,.jpeg,.webp">
            </label>
        </div>
    </div>

    <div class="panel">
        <h3>Collateral policy</h3>

        <div class="switch-row">
            <span>Require KTM or KTP as collateral</span>
            <label class="switch">
                <input type="checkbox" name="require_collateral" <?= $cfg['require_collateral'] ? 'checked' : '' ?>>
                <span class="slider"></span>
            </label>
        </div>

        <div class="form-group" style="margin:14px 0 0;">
            <label for="max_loan_days">Maximum loan duration (days)</label>
            <input type="number" id="max_loan_days" name="max_loan_days" class="form-control" min="1" max="60" value="<?= (int)$cfg['max_loan_days'] ?>">
        </div>
    </div>

    <div class="panel">
        <h3>Notifications</h3>

        <div class="switch-row">
            <span>Email alert on new borrow request</span>
            <label class="switch">
                <input type="checkbox" name="notify_admin_new_req" <?= $cfg['notify_admin_new_req'] ? 'checked' : '' ?>>
                <span class="slider"></span>
            </label>
        </div>
        <div class="switch-row">
            <span>Email alert on overdue return</span>
            <label class="switch">
                <input type="checkbox" name="notify_admin_overdue" <?= $cfg['notify_admin_overdue'] ? 'checked' : '' ?>>
                <span class="slider"></span>
            </label>
        </div>
    </div>

    <div class="btn-row" style="margin-top:0;">
        <button type="submit" class="btn btn-primary" style="padding:12px 38px;">Save Changes</button>
        <a href="index.php" class="btn btn-outline" style="padding:12px 38px;">Cancel</a>
    </div>
</form>

<?php include 'includes/footer.php'; ?>
