<?php
/**
 * profile.php  —  MY PROFILE  (for BOTH students and admins)
 * ------------------------------------------------------------
 * WHAT THIS PAGE SHOWS
 *   Read-only account information. The page decides what to show from
 *   the logged-in user's role:
 *     - STUDENT: name, program + semester, NIM, e-mail, KTM status
 *     - ADMIN  : name, staff id (= username), e-mail, department
 *   Buttons lead to profile_edit.php and change_password.php.
 *
 *   STUDENTS also see a "Notifications" box with two on/off switches
 *   (this used to be on a separate "Account Settings" page, which was
 *   removed: password changes already live behind "Change Password").
 *   The switches are saved in users.notify_on_approved and
 *   users.notify_before_due. NOTE: actually SENDING those e-mails needs a
 *   mail server + scheduled job, which is not built yet.
 *
 * (Your old menu already linked to profile.php but the file did not exist.)
 * ------------------------------------------------------------
 */
$page_title = 'My Profile';
require_once 'includes/header.php';

$is_admin = ($role === 'admin');

// ---- Notification switches were submitted (students only) ----
// A checked box is sent in $_POST; an unchecked box is NOT sent at all,
// so "empty()" tells us whether the switch is off.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_admin) {
    try {
        $stmt = $pdo->prepare("UPDATE users SET notify_on_approved = ?, notify_before_due = ?, updated_at = now() WHERE id = ?");
        $stmt->bindValue(1, !empty($_POST['notify_on_approved']) ? 'true' : 'false');
        $stmt->bindValue(2, !empty($_POST['notify_before_due'])  ? 'true' : 'false');
        $stmt->bindValue(3, $_SESSION['user_id']);
        $stmt->execute();
        flash_set('success', 'Notification preferences saved.');
    } catch (Exception $e) {
        flash_set('danger', 'Could not save: ' . $e->getMessage());
    }
    header('Location: profile.php');   // redirect so pressing F5 does not re-submit the form
    exit;
}

$user = current_user($pdo);

// ---- Text under the name, and the KTM status line (students only) ----
$sub_line = '';
$ktm_text = 'Not set';
if (!$is_admin) {
    $parts = array_filter([
        $user['program'] ?? '',
        !empty($user['semester']) ? 'Semester ' . $user['semester'] : '',
    ]);
    $sub_line = implode(' - ', $parts);

    if (!empty($user['ktm_valid_until'])) {
        $valid_until = strtotime($user['ktm_valid_until']);
        $ktm_text = ($valid_until >= time())
            ? 'Active — valid until ' . date('Y', $valid_until)
            : 'Expired on ' . date('d M Y', $valid_until);
    }
}
?>

<h1>My Profile</h1>
<p class="subtitle">Your account information</p>

<?= flash_render() ?>

<div class="page-narrow">

    <!-- Big round avatar with initials + name -->
    <div class="profile-head">
        <div class="avatar-lg"><?= e($initials) ?></div>
        <div>
            <div class="name"><?= e($user['full_name']) ?></div>
            <?php if ($sub_line): ?><div class="sub"><?= e($sub_line) ?></div><?php endif; ?>
        </div>
    </div>

    <div class="panel">
        <h3>Account Information</h3>

        <!-- SAME fields in the SAME order for students and admins; only the last block differs -->
        <div class="readonly-label">Full name</div>
        <div class="readonly-field"><?= e($user['full_name']) ?></div>

        <div class="readonly-label"><?= $is_admin ? 'Staff ID' : 'Student ID (NIM)' ?></div>
        <div class="readonly-field"><?= e($is_admin ? ($user['staff_id'] ?: ($user['username'] ?? '-')) : ($user['nim'] ?? '-')) ?></div>

        <div class="readonly-label">Email</div>
        <div class="readonly-field"><?= e($user['email'] ?? '') ?: '—' ?></div>

        <div class="readonly-label">Phone number</div>
        <div class="readonly-field"><?= e($user['phone'] ?? '') ?: '—' ?></div>

        <?php if ($is_admin): ?>
            <div class="readonly-label">Department</div>
            <div class="readonly-field" style="margin-bottom:0;"><?= e($user['department'] ?? '') ?: '—' ?></div>
        <?php else: ?>
            <div class="readonly-label">Program / major (jurusan) and class</div>
            <div class="readonly-field"><?= e(trim(($user['program'] ?? '') . ' ' . ($user['class_name'] ?? ''))) ?: '—' ?></div>

            <div class="readonly-label">Student ID Card (KTM) status</div>
            <div class="readonly-field" style="margin-bottom:0;"><?= e($ktm_text) ?></div>
        <?php endif; ?>
    </div>

    <?php if (!$is_admin): ?>
    <!-- ============ NOTIFICATIONS (students) ============ -->
    <form method="POST" class="panel">
        <h3>Notifications</h3>

        <!-- A switch is a checkbox drawn with CSS (.switch / .slider in style.css) -->
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

        <button type="submit" class="btn btn-primary" style="margin-top:14px; padding:10px 28px;">Save notifications</button>
    </form>
    <?php endif; ?>

    <div class="btn-row" style="margin-top:0;">
        <a href="profile_edit.php" class="btn btn-primary" style="padding:12px 38px;">Edit Profile</a>
        <a href="change_password.php" class="btn btn-primary" style="padding:12px 38px;">Change Password</a>
        <a href="logout.php" class="btn btn-outline" style="padding:12px 38px;">Log Out</a>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
