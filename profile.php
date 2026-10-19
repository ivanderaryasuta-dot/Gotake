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
 * (Your old menu already linked to profile.php but the file did not exist.)
 * ------------------------------------------------------------
 */
$page_title = 'My Profile';
require_once 'includes/header.php';

$user     = current_user($pdo);
$is_admin = ($role === 'admin');

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
<p class="subtitle"><?= $is_admin ? 'Your account information' : 'Your personal account information' ?></p>

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

        <div class="readonly-label"><?= $is_admin ? 'Name' : 'Full name' ?></div>
        <div class="readonly-field"><?= e($user['full_name']) ?></div>

        <?php if ($is_admin): ?>
            <div class="readonly-label">Staff ID</div>
            <div class="readonly-field"><?= e($user['staff_id'] ?: ($user['username'] ?? '-')) ?></div>
        <?php else: ?>
            <div class="readonly-label">Student ID (NIM)</div>
            <div class="readonly-field"><?= e($user['nim'] ?? '-') ?></div>
        <?php endif; ?>

        <div class="readonly-label">Email</div>
        <div class="readonly-field"><?= e($user['email'] ?? '') ?: '—' ?></div>

        <?php if ($is_admin): ?>
            <div class="readonly-label">Department</div>
            <div class="readonly-field" style="margin-bottom:0;"><?= e($user['department'] ?? '') ?: '—' ?></div>
        <?php else: ?>
            <div class="readonly-label">Student ID Card (KTM) status</div>
            <div class="readonly-field" style="margin-bottom:0;"><?= e($ktm_text) ?></div>
        <?php endif; ?>
    </div>

    <div class="btn-row" style="margin-top:0;">
        <a href="profile_edit.php" class="btn <?= $is_admin ? 'btn-blue' : 'btn-primary' ?>" style="padding:12px 38px;">Edit Profile</a>
        <a href="change_password.php" class="btn <?= $is_admin ? 'btn-blue' : 'btn-primary' ?>" style="padding:12px 38px;">Change Password</a>
        <a href="logout.php" class="btn btn-outline" style="padding:12px 38px;">Log Out</a>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
