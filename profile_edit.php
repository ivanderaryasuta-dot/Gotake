<?php
/**
 * profile_edit.php  —  EDIT PROFILE  (for BOTH students and admins)
 * ------------------------------------------------------------
 * WHAT THIS PAGE DOES
 *   Lets the user change the details that are allowed to change.
 *     - STUDENT : program/major, class, semester, e-mail, phone
 *     - ADMIN   : full name, department, e-mail, phone
 *   The NIM / staff ID is shown but cannot be edited, because it is the
 *   login name and other records point to this user.
 *
 * "CHANGE PHOTO"
 *   The button is a placeholder for now (profile pictures are not
 *   stored yet). The avatar shows the user's initials instead.
 * ------------------------------------------------------------
 */
$page_title = 'Edit Profile';
$nav_active = 'profile.php';
require_once 'includes/header.php';

$user     = current_user($pdo);
$is_admin = ($role === 'admin');
$error    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        // In your database users.email is NOT NULL (and unique), so it can never be empty.
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Please enter a valid email address.');

        // E-mail must not belong to somebody else
        $dup = $pdo->prepare("SELECT 1 FROM users WHERE LOWER(email) = LOWER(?) AND id <> ?");
        $dup->execute([$email, $user['id']]);
        if ($dup->fetch()) throw new Exception('That email is already used by another account.');

        if ($is_admin) {
            $name = trim($_POST['full_name'] ?? '');
            if ($name === '') throw new Exception('Name cannot be empty.');

            $stmt = $pdo->prepare("UPDATE users SET full_name = ?, department = ?, email = ?, phone = ?, updated_at = now() WHERE id = ?");
            $stmt->execute([$name, trim($_POST['department'] ?? ''), $email, $phone, $user['id']]);

            $_SESSION['full_name'] = $name;       // so the avatar/name update immediately
        } else {
            $semester = ($_POST['semester'] ?? '') !== '' ? (int)$_POST['semester'] : null;

            $stmt = $pdo->prepare("UPDATE users SET program = ?, class_name = ?, semester = ?, email = ?, phone = ?, updated_at = now() WHERE id = ?");
            $stmt->execute([trim($_POST['program'] ?? ''), trim($_POST['class_name'] ?? ''), $semester, $email, $phone, $user['id']]);
        }

        flash_set('success', 'Profile updated.');
        header('Location: profile.php');
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
        // Show what the user just typed instead of the old database values
        $user = array_merge($user, $_POST);
    }
}

$id_label = $is_admin ? ($user['staff_id'] ?: ($user['username'] ?? '')) : ($user['nim'] ?? '');
?>

<h1><?= $is_admin ? 'My Profile' : 'Edit Profile' ?></h1>
<p class="subtitle" style="margin-bottom:6px;"><?= $is_admin ? 'Update your staff account details' : 'Update your personal account details' ?></p>
<p class="breadcrumb" style="font-size:0.9rem;"><a href="profile.php" style="text-decoration:none;">My Profile</a> &nbsp;›&nbsp; <strong>Edit Profile</strong></p>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="split-layout">

    <!-- LEFT: avatar card -->
    <div class="side-card">
        <div class="avatar-lg"><?= e($initials) ?></div>
        <div class="name"><?= e($user['full_name']) ?></div>
        <div class="id"><?= e($id_label) ?></div>
        <button type="button" class="btn btn-outline btn-sm" disabled title="Profile photos are not stored yet">Change photo</button>
    </div>

    <!-- RIGHT: the form -->
    <form method="POST" novalidate>

        <?php if ($is_admin): ?>
            <div class="form-group">
                <label for="full_name">Full name</label>
                <input type="text" id="full_name" name="full_name" class="form-control" value="<?= e($user['full_name'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>Staff ID</label>
                <input type="text" class="form-control" value="<?= e($id_label) ?>" disabled>
            </div>
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" class="form-control" value="<?= e($user['email'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label for="phone">Phone number</label>
                <input type="text" id="phone" name="phone" class="form-control" value="<?= e($user['phone'] ?? '') ?>" placeholder="+62 ...">
            </div>
            <div class="form-group">
                <label for="department">Department</label>
                <input type="text" id="department" name="department" class="form-control" value="<?= e($user['department'] ?? '') ?>">
            </div>
        <?php else: ?>
            <div class="form-group">
                <label for="program">Program / major</label>
                <input type="text" id="program" name="program" class="form-control" value="<?= e($user['program'] ?? '') ?>" placeholder="e.g. Computer Science">
            </div>
            <div class="grid-two-col" style="gap:16px;">
                <div class="form-group">
                    <label for="class_name">Class</label>
                    <input type="text" id="class_name" name="class_name" class="form-control" value="<?= e($user['class_name'] ?? '') ?>" placeholder="e.g. CS - 1H">
                </div>
                <div class="form-group">
                    <label for="semester">Semester</label>
                    <input type="number" id="semester" name="semester" class="form-control" min="1" max="14" value="<?= e($user['semester'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" class="form-control" value="<?= e($user['email'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label for="phone">Phone number</label>
                <input type="text" id="phone" name="phone" class="form-control" value="<?= e($user['phone'] ?? '') ?>" placeholder="+62 ...">
            </div>
        <?php endif; ?>

        <div class="btn-row" style="margin-top:6px;">
            <button type="submit" class="btn <?= $is_admin ? 'btn-blue' : 'btn-primary' ?>" style="flex:1; padding:12px;">Save Changes</button>
            <a href="profile.php" class="btn btn-outline" style="padding:12px 38px;">Cancel</a>
        </div>
    </form>
</div>

<?php include 'includes/footer.php'; ?>
