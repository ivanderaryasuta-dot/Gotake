<?php
/**
 * login.php  —  SIGN IN PAGE
 * ------------------------------------------------------------
 * WHAT THIS PAGE DOES
 *   One login form for BOTH kinds of users:
 *     - Students type their NIM (student ID)
 *     - Admins type their username
 *   (Same idea as your previous version.)
 *
 * HOW A LOGIN WORKS
 *   1. The visitor submits the form  ->  PHP receives it in $_POST.
 *   2. We look for an admin with that username; if none, a student with that NIM.
 *   3. We check the password.
 *   4. If everything is OK we store the user's info in $_SESSION
 *      (PHP's memory for this visitor) and redirect to their dashboard.
 *
 * WHAT IS NEW
 *   - New design (PBL.pdf) with red error messages under each field
 *   - "Remember me" keeps you logged in longer
 *   - Passwords may now be stored as secure hashes (old plain-text
 *     passwords still work, and are upgraded automatically on login)
 * ------------------------------------------------------------
 */
$page_title = 'Login';
require_once 'includes/auth_header.php';   // session + database + top of the page

// Already logged in? No need to see the login form -> go to the dashboard.
if (isset($_SESSION['user_id'])) {
    header('Location: ' . (($_SESSION['role'] ?? '') === 'admin' ? 'index.php' : 'student_dashboard.php'));
    exit;
}

// Messages shown under the fields (empty = no error)
$errors     = ['identifier' => '', 'password' => '', 'general' => ''];
$identifier = '';   // keeps what the user typed so they don't have to retype it

// ------------------------------------------------------------
// The form was submitted (the browser sent a POST request)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');   // NIM (student) or username (admin)
    $password   = $_POST['password'] ?? '';

    // Step 1: empty fields
    if ($identifier === '') $errors['identifier'] = '*Please enter your NIM or username';
    if ($password === '')   $errors['password']   = '*Please enter your password';

    if ($errors['identifier'] === '' && $errors['password'] === '') {

        // Step 2a: look for an ADMIN with this username
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND role = 'admin'");
        $stmt->execute([$identifier]);
        $user = $stmt->fetch();

        // Step 2b: no admin found -> look for a STUDENT with this NIM.
        // CAST(... AS TEXT) makes the comparison safe even if the NIM column is a number.
        if (!$user) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE CAST(nim AS TEXT) = ? AND role = 'student'");
            $stmt->execute([$identifier]);
            $user = $stmt->fetch();
        }

        // Step 3: decide what to say
        if (!$user) {
            $errors['identifier'] = '*Username not found';
        } elseif (!verify_password($password, (string)$user['password_hash'])) {
            $errors['password'] = '*Incorrect password';
        } elseif (!$user['is_active']) {
            $errors['general'] = 'Your account has been suspended. Please contact the facilities admin.';
        } else {
            // ---- LOGIN SUCCESSFUL ----

            // A fresh session id after login prevents "session fixation" attacks.
            session_regenerate_id(true);

            // Remember who this visitor is (read by every other page)
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role']      = $user['role'];

            // "Remember me": make the session cookie last 30 days instead of
            // "until the browser closes".
            // (For it to survive on the server too, also raise session.gc_maxlifetime in php.ini.)
            if (!empty($_POST['remember'])) {
                setcookie(session_name(), session_id(), [
                    'expires'  => time() + 60 * 60 * 24 * 30,
                    'path'     => '/',
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }

            // If this account still has an OLD plain-text password, quietly replace it
            // with a secure hash now that we know the password is correct.
            if (!is_hashed((string)$user['password_hash'])) {
                try {
                    $upgrade = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                    $upgrade->execute([hash_new_password($password), $user['id']]);
                } catch (Exception $e) {
                    // If the upgrade fails for any reason, just skip it; the login still works.
                }
            }

            // Send the user to the right dashboard for their role
            header('Location: ' . ($user['role'] === 'admin' ? 'index.php' : 'student_dashboard.php'));
            exit;
        }
    }
}
?>

<h1>Sign in to your account</h1>
<p class="subtitle">Welcome back. Please log in to continue.</p>

<?= flash_render() /* e.g. "Account created! Please log in." after registering */ ?>

<?php if ($errors['general']): ?>
    <div class="alert alert-danger"><?= e($errors['general']) ?></div>
<?php endif; ?>

<form method="POST" novalidate>

    <div class="form-group">
        <label for="identifier">NIM / Username</label>
        <input type="text" id="identifier" name="identifier"
               class="form-control <?= $errors['identifier'] ? 'has-error' : '' ?>"
               placeholder="Enter your student ID (admins: username)"
               value="<?= e($identifier) ?>" autocomplete="username">
        <?php if ($errors['identifier']): ?><div class="field-error"><?= e($errors['identifier']) ?></div><?php endif; ?>
    </div>

    <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password"
               class="form-control <?= $errors['password'] ? 'has-error' : '' ?>"
               placeholder="Enter your password" autocomplete="current-password">
        <?php if ($errors['password']): ?><div class="field-error"><?= e($errors['password']) ?></div><?php endif; ?>
    </div>

    <div class="auth-row">
        <label class="check-row"><input type="checkbox" name="remember"> Remember me</label>
        <a href="forgot_password.php">Forgot password?</a>
    </div>

    <button type="submit" class="btn btn-primary btn-block">LOG IN</button>
</form>

<div class="auth-foot">
    Don't have an account? <a href="register.php">Sign up</a>
    <hr>
</div>

<?php include 'includes/auth_footer.php'; ?>
