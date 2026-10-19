<?php
/**
 * forgot_password.php  —  RESET A FORGOTTEN PASSWORD
 * ------------------------------------------------------------
 * WHAT THIS PAGE DOES  (the "Change Password" screens in PBL.pdf)
 *   1. The user types their e-mail and presses "Send Code".
 *   2. We create a 6-digit code and e-mail it to them.
 *   3. The user types the code + a new password + confirmation
 *      and presses "RESET PASSWORD".
 *   4. If the code is right we save the new password.
 *
 *   NEW PAGE: your previous version linked "Forgot password?" to nothing.
 *
 * ONE FORM, TWO BUTTONS
 *   The "Send Code" and "RESET PASSWORD" buttons both submit the same
 *   form. Each button has name="action" and a different value, so PHP
 *   can tell which one was pressed ($_POST['action']).
 *
 * WHERE THE CODE IS KEPT
 *   In the visitor's $_SESSION (server memory), together with an
 *   expiry time (10 minutes) and a counter of wrong attempts (max 5).
 *   We store only a HASH of the code, not the code itself.
 * ------------------------------------------------------------
 */
$page_title = 'Change Password';
require_once 'includes/auth_header.php';

/* ============================================================
   !!  DEVELOPMENT SWITCH  !!
   Your computer probably cannot send e-mails (no mail server).
   While this is TRUE the code is also shown on the screen, so you can
   test the page. Set it to FALSE before putting the site online!
   ============================================================ */
$SHOW_CODE_ON_SCREEN = true;

$errors  = ['email' => '', 'code' => '', 'password' => '', 'confirm' => '', 'general' => ''];
$info    = '';                       // blue/green message
$dev_code = '';                      // only used when $SHOW_CODE_ON_SCREEN is true
$email   = trim($_POST['email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* ------------------------------------------------------------
       BUTTON 1: "Send Code"
       ------------------------------------------------------------ */
    if ($action === 'send_code') {

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter your email!';
        } else {
            // Is there a user with this e-mail?
            $stmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND is_active = TRUE");
            $stmt->execute([$email]);
            $user_exists = (bool)$stmt->fetch();

            if ($user_exists) {
                $code = (string)random_int(100000, 999999);        // e.g. "482913"

                // Remember (hashed) code, e-mail, expiry and attempts in the session
                $_SESSION['reset'] = [
                    'email'   => strtolower($email),
                    'hash'    => password_hash($code, PASSWORD_DEFAULT),
                    'expires' => time() + 600,                     // 10 minutes
                    'tries'   => 0,
                ];

                // Try to e-mail it. The "@" hides the warning if no mail server exists.
                @mail($email, 'GoTake password reset code',
                      "Your GoTake verification code is: $code\nIt is valid for 10 minutes.");

                if ($SHOW_CODE_ON_SCREEN) $dev_code = $code;
            }

            // Same message whether or not the e-mail exists, so nobody can use this
            // page to find out which e-mails are registered.
            $info = 'If this email is registered, a verification code has been sent.';
        }
    }

    /* ------------------------------------------------------------
       BUTTON 2: "RESET PASSWORD"
       ------------------------------------------------------------ */
    if ($action === 'reset') {
        $code     = trim($_POST['code'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm']  ?? '';
        $reset    = $_SESSION['reset'] ?? null;

        if ($email === '') $errors['email'] = 'Please enter your email!';

        // Check the code
        if ($email !== '') {
            if (!$reset || $reset['email'] !== strtolower($email) || time() > $reset['expires']) {
                $errors['code'] = 'Wrong verify code! (Request a new code with "Send Code")';
            } elseif ($reset['tries'] >= 5) {
                $errors['code'] = 'Too many wrong attempts. Please request a new code.';
            } elseif (!password_verify($code, $reset['hash'])) {
                $_SESSION['reset']['tries']++;
                $errors['code'] = 'Wrong verify code!';
            }
        }

        // Check the new password
        if (strlen($password) < 8) $errors['password'] = 'Password must be at least 8 characters';
        if ($confirm !== $password) $errors['confirm'] = 'Confirm Password is different';

        // Everything OK -> save
        if (!array_filter($errors)) {
            try {
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = now() WHERE LOWER(email) = LOWER(?)");
                $stmt->execute([hash_new_password($password), $email]);
                unset($_SESSION['reset']);                        // the code can only be used once
                flash_set('success', 'Password changed! You can now log in with your new password.');
                header('Location: login.php');
                exit;
            } catch (Exception $e) {
                $errors['general'] = 'Could not change the password: ' . $e->getMessage();
            }
        }
    }
}

// If a code was already sent for this e-mail, the button says "Resend Code"
$code_sent = isset($_SESSION['reset']) && $email !== '' && $_SESSION['reset']['email'] === strtolower($email);
?>

<h1>Change Password</h1>
<p class="subtitle">We will e-mail you a code to confirm it's you.</p>

<?php if ($errors['general']): ?><div class="alert alert-danger"><?= e($errors['general']) ?></div><?php endif; ?>
<?php if ($info): ?><div class="alert alert-success"><?= e($info) ?></div><?php endif; ?>

<?php if ($dev_code): ?>
    <!-- Only visible while $SHOW_CODE_ON_SCREEN = true (testing) -->
    <div class="dev-code"><strong>Testing mode:</strong> your code is <strong><?= e($dev_code) ?></strong>
        (this box disappears when you turn off $SHOW_CODE_ON_SCREEN)</div>
<?php endif; ?>

<form method="POST" novalidate>

    <!-- Hidden default button: makes the ENTER key press "RESET PASSWORD"
         instead of "Send Code" (browsers click the first submit button). -->
    <button type="submit" name="action" value="reset" tabindex="-1" aria-hidden="true"
            style="position:absolute; left:-9999px;">Reset</button>

    <div class="form-group">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" class="form-control <?= $errors['email'] ? 'has-error' : '' ?>"
               placeholder="Enter your email" value="<?= e($email) ?>">
        <?php if ($errors['email']): ?><div class="field-error"><?= e($errors['email']) ?></div><?php endif; ?>
    </div>

    <!-- Small yellow "Send Code" pill (becomes "Resend Code" after the first send) -->
    <div class="code-row">
        <button type="submit" name="action" value="send_code" class="btn-pill" formnovalidate>
            <?= $code_sent ? 'Resend Code' : 'Send Code' ?>
        </button>
    </div>

    <div class="form-group">
        <label for="code">Verification Code</label>
        <input type="text" id="code" name="code" class="form-control <?= $errors['code'] ? 'has-error' : '' ?>"
               placeholder="Enter verify code" inputmode="numeric" autocomplete="one-time-code">
        <?php if ($errors['code']): ?><div class="field-error"><?= e($errors['code']) ?></div><?php endif; ?>
    </div>

    <div class="form-group">
        <label for="password">New Password</label>
        <input type="password" id="password" name="password" class="form-control <?= $errors['password'] ? 'has-error' : '' ?>"
               placeholder="Enter your new password" autocomplete="new-password">
        <?php if ($errors['password']): ?><div class="field-error"><?= e($errors['password']) ?></div><?php endif; ?>
    </div>

    <div class="form-group">
        <label for="confirm">Confirm Password</label>
        <input type="password" id="confirm" name="confirm" class="form-control <?= $errors['confirm'] ? 'has-error' : '' ?>"
               placeholder="Enter your confirm password" autocomplete="new-password">
        <?php if ($errors['confirm']): ?><div class="field-error"><?= e($errors['confirm']) ?></div><?php endif; ?>
    </div>

    <button type="submit" name="action" value="reset" class="btn btn-primary btn-block" style="margin-top:10px;">RESET PASSWORD</button>
</form>

<div class="auth-foot">
    <a href="login.php">Back to log in</a>
    <hr>
</div>

<?php include 'includes/auth_footer.php'; ?>
