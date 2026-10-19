<?php
/**
 * account_settings.php  —  ACCOUNT SETTINGS  (NEW PAGE, student menu in PBL.pdf)
 * ------------------------------------------------------------
 * WHAT THIS PAGE DOES
 *   Two things in one form:
 *     1. Change password  (current password + new password)
 *     2. Notification preferences (two on/off switches)
 *
 * NOTE ABOUT E-MAILS
 *   The switches are SAVED in the database (users.notify_on_approved and
 *   users.notify_before_due). Actually sending those e-mails needs a
 *   mail server + a scheduled job, which is not part of this project yet.
 *   When you build that, read these two columns to know who wants e-mails.
 * ------------------------------------------------------------
 */
$page_title = 'Account Settings';
require_once 'includes/header.php';
require_role('student');

$user    = current_user($pdo);
$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $current  = $_POST['current_password'] ?? '';
        $new      = $_POST['new_password'] ?? '';

        // ---- 1. Password: only changed if the "New password" box was filled in ----
        if ($new !== '') {
            if (!verify_password($current, (string)$user['password_hash'])) throw new Exception('Current password is incorrect.');
            if (strlen($new) < 8)                                           throw new Exception('New password must be at least 8 characters.');
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = now() WHERE id = ?");
            $stmt->execute([hash_new_password($new), $user['id']]);
        }

        // ---- 2. Notification switches (a checked box is sent; an unchecked one is NOT sent) ----
        $stmt = $pdo->prepare("UPDATE users SET notify_on_approved = ?, notify_before_due = ?, updated_at = now() WHERE id = ?");
        $stmt->bindValue(1, !empty($_POST['notify_on_approved']) ? 'true' : 'false');
        $stmt->bindValue(2, !empty($_POST['notify_before_due'])  ? 'true' : 'false');
        $stmt->bindValue(3, $user['id']);
        $stmt->execute();

        $success = 'Your changes have been saved.';
        $user    = current_user($pdo);   // reload so the page shows the new values
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>

<h1>Account Settings</h1>
<p class="subtitle">Manage your password and notification preferences</p>

<?php if ($error):   ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

<form method="POST" class="page-narrow" autocomplete="off">

    <div class="panel">
        <h3>Change password</h3>
        <div class="form-group">
            <label for="current_password" style="text-transform:none; font-weight:500;">Current password</label>
            <input type="password" id="current_password" name="current_password" class="form-control" placeholder="Current password" autocomplete="current-password">
        </div>
        <div class="form-group" style="margin-bottom:0;">
            <label for="new_password" style="text-transform:none; font-weight:500;">New password</label>
            <input type="password" id="new_password" name="new_password" class="form-control" placeholder="Leave empty to keep your password" autocomplete="new-password">
        </div>
    </div>

    <div class="panel">
        <h3>Notifications</h3>

        <!-- A switch = a checkbox drawn with CSS (.switch / .slider in style.css) -->
        <div class="switch-row">
            <span>Email me when my request is approved</span>
            <label class="switch">
                <input type="checkbox" name="notify_on_approved" <?= ($user['notify_on_approved'] ?? true) ? 'checked' : '' ?>>
                <span class="slider"></span>
            </label>
        </div>
        <div class="switch-row">
            <span>Email me a reminder before the due date</span>
            <label class="switch">
                <input type="checkbox" name="notify_before_due" <?= ($user['notify_before_due'] ?? true) ? 'checked' : '' ?>>
                <span class="slider"></span>
            </label>
        </div>
    </div>

    <div class="btn-row" style="margin-top:0;">
        <button type="submit" class="btn btn-primary" style="padding:12px 38px;">Save Changes</button>
        <a href="student_dashboard.php" class="btn btn-outline" style="padding:12px 38px;">Cancel</a>
    </div>
</form>

<?php include 'includes/footer.php'; ?>
