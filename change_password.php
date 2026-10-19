<?php
/**
 * change_password.php  —  CHANGE PASSWORD  (for BOTH students and admins)
 * ------------------------------------------------------------
 * WHAT THIS PAGE DOES
 *   The user knows their password and wants a new one:
 *   current password + new password + confirm.
 *   (If they FORGOT their password, they use forgot_password.php.)
 *
 * CHECKS
 *   - the current password is correct
 *   - the new password is at least 8 characters
 *   - "new" and "confirm" are identical
 * ------------------------------------------------------------
 */
$page_title = 'Change Password';
$nav_active = 'profile.php';
require_once 'includes/header.php';

$user   = current_user($pdo);
$is_admin = ($role === 'admin');
$errors = ['current' => '', 'new' => '', 'confirm' => '', 'general' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!verify_password($current, (string)$user['password_hash'])) $errors['current'] = 'Current password is incorrect.';
    if (strlen($new) < 8)                                           $errors['new']     = 'New password must be at least 8 characters.';
    if ($confirm !== $new)                                          $errors['confirm'] = 'Confirm Password is different.';

    if (!array_filter($errors)) {
        try {
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = now() WHERE id = ?");
            $stmt->execute([hash_new_password($new), $user['id']]);
            flash_set('success', 'Your password has been updated.');
            header('Location: profile.php');
            exit;
        } catch (Exception $e) {
            $errors['general'] = 'Could not update the password: ' . $e->getMessage();
        }
    }
}

$id_label = $is_admin ? ($user['staff_id'] ?: ($user['username'] ?? '')) : ($user['nim'] ?? '');
?>

<h1>Change Password</h1>
<p class="subtitle" style="margin-bottom:6px;">Update the password for your account</p>
<p class="breadcrumb" style="font-size:0.9rem;"><a href="profile.php" style="text-decoration:none;">My Profile</a> &nbsp;›&nbsp; <strong>Change Password</strong></p>

<?php if ($errors['general']): ?><div class="alert alert-danger"><?= e($errors['general']) ?></div><?php endif; ?>

<div class="split-layout">

    <div class="side-card">
        <div class="avatar-lg grey"><?= e($initials) ?></div>
        <div class="name"><?= e($user['full_name']) ?></div>
        <div class="id"><?= e($id_label) ?></div>
    </div>

    <form method="POST" novalidate autocomplete="off">
        <div class="form-group">
            <label for="current_password">Current password</label>
            <input type="password" id="current_password" name="current_password" class="form-control <?= $errors['current'] ? 'has-error' : '' ?>" autocomplete="current-password">
            <?php if ($errors['current']): ?><div class="field-error"><?= e($errors['current']) ?></div><?php endif; ?>
        </div>
        <div class="form-group">
            <label for="new_password">New password</label>
            <input type="password" id="new_password" name="new_password" class="form-control <?= $errors['new'] ? 'has-error' : '' ?>" autocomplete="new-password">
            <?php if ($errors['new']): ?><div class="field-error"><?= e($errors['new']) ?></div><?php endif; ?>
        </div>
        <div class="form-group">
            <label for="confirm_password">Confirm new password</label>
            <input type="password" id="confirm_password" name="confirm_password" class="form-control <?= $errors['confirm'] ? 'has-error' : '' ?>" autocomplete="new-password">
            <?php if ($errors['confirm']): ?><div class="field-error"><?= e($errors['confirm']) ?></div><?php endif; ?>
        </div>

        <div class="btn-row">
            <button type="submit" class="btn <?= $is_admin ? 'btn-blue' : 'btn-primary' ?>" style="flex:1; padding:12px;">Update Password</button>
            <a href="profile.php" class="btn btn-outline" style="padding:12px 38px;">Cancel</a>
        </div>
    </form>
</div>

<?php include 'includes/footer.php'; ?>
