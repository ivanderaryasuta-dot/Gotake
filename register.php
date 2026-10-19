<?php
/**
 * register.php  —  CREATE AN ACCOUNT  (students)
 * ------------------------------------------------------------
 * WHAT THIS PAGE DOES
 *   Lets a new student sign up with: name, NIM, e-mail, password.
 *   (Admin accounts are NOT created here. An admin creates them from
 *   "User Data" > "Add user".)
 *
 *   NEW PAGE: your previous version had a "Sign up" link in the design
 *   but no page behind it.
 *
 * STEPS
 *   1. Check every field (empty? e-mail valid? passwords equal? terms ticked?)
 *   2. Check that the NIM and e-mail are not already used
 *   3. Save the new user with a hashed password
 *   4. Redirect to the login page with a success message
 * ------------------------------------------------------------
 */
$page_title = 'Create account';
require_once 'includes/auth_header.php';

// Logged-in users do not need this page
if (isset($_SESSION['user_id'])) {
    header('Location: ' . (($_SESSION['role'] ?? '') === 'admin' ? 'index.php' : 'student_dashboard.php'));
    exit;
}

// One error message slot per field
$errors = ['name' => '', 'nim' => '', 'email' => '', 'password' => '', 'confirm' => '', 'agree' => '', 'general' => ''];
$old    = ['name' => '', 'nim' => '', 'email' => ''];   // keep what the user typed (never the password)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['name']  = trim($_POST['name']  ?? '');
    $old['nim']   = trim($_POST['nim']   ?? '');
    $old['email'] = trim($_POST['email'] ?? '');
    $password     = $_POST['password'] ?? '';
    $confirm      = $_POST['confirm']  ?? '';
    $agree        = !empty($_POST['agree']);

    // ---- 1. VALIDATION ----
    if ($old['name'] === '')                                   $errors['name'] = '*Please enter your name';
    if (!preg_match('/^[0-9]{5,20}$/', $old['nim']))           $errors['nim']  = '*NIM must be 5-20 digits';
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL))     $errors['email'] = '*Please enter a valid email';
    if (strlen($password) < 8)                                 $errors['password'] = '*Password must be at least 8 characters';
    if ($confirm !== $password)                                $errors['confirm']  = '*Confirm Password is different';
    if (!$agree)                                               $errors['agree'] = '*You must agree to continue';

    // ---- 2. DUPLICATES (only if the basic checks passed) ----
    if (!array_filter($errors)) {
        try {
            $check = $pdo->prepare("SELECT 1 FROM users WHERE CAST(nim AS TEXT) = ?");
            $check->execute([$old['nim']]);
            if ($check->fetch()) $errors['nim'] = '*This NIM is already registered';

            $check = $pdo->prepare("SELECT 1 FROM users WHERE LOWER(email) = LOWER(?)");
            $check->execute([$old['email']]);
            if ($check->fetch()) $errors['email'] = '*This email is already registered';
        } catch (Exception $e) {
            $errors['general'] = 'Database error. Did you run database/update_schema.sql? (' . $e->getMessage() . ')';
        }
    }

    // ---- 3. SAVE ----
    if (!array_filter($errors)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO users (full_name, nim, email, password_hash, role, is_active)
                VALUES (?, ?, ?, ?, 'student', TRUE)
            ");
            $stmt->execute([$old['name'], $old['nim'], $old['email'], hash_new_password($password)]);

            // ---- 4. DONE: show a message on the login page ----
            flash_set('success', 'Account created! You can now log in with your NIM.');
            header('Location: login.php');
            exit;
        } catch (Exception $e) {
            $errors['general'] = 'Could not create the account: ' . $e->getMessage();
        }
    }
}
?>

<h1>Create an account</h1>
<p class="subtitle">Fill in your campus details to get started.</p>

<?php if ($errors['general']): ?>
    <div class="alert alert-danger"><?= e($errors['general']) ?></div>
<?php endif; ?>

<form method="POST" novalidate>

    <div class="form-group">
        <label for="name">Name</label>
        <input type="text" id="name" name="name" class="form-control <?= $errors['name'] ? 'has-error' : '' ?>"
               placeholder="Enter your Name" value="<?= e($old['name']) ?>" autocomplete="name">
        <?php if ($errors['name']): ?><div class="field-error"><?= e($errors['name']) ?></div><?php endif; ?>
    </div>

    <div class="form-group">
        <label for="nim">NIM</label>
        <input type="text" id="nim" name="nim" class="form-control <?= $errors['nim'] ? 'has-error' : '' ?>"
               placeholder="Enter your student ID" value="<?= e($old['nim']) ?>" inputmode="numeric">
        <?php if ($errors['nim']): ?><div class="field-error"><?= e($errors['nim']) ?></div><?php endif; ?>
    </div>

    <div class="form-group">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" class="form-control <?= $errors['email'] ? 'has-error' : '' ?>"
               placeholder="Enter your email" value="<?= e($old['email']) ?>" autocomplete="email">
        <?php if ($errors['email']): ?><div class="field-error"><?= e($errors['email']) ?></div><?php endif; ?>
    </div>

    <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" class="form-control <?= $errors['password'] ? 'has-error' : '' ?>"
               placeholder="At least 8 characters" autocomplete="new-password">
        <?php if ($errors['password']): ?><div class="field-error"><?= e($errors['password']) ?></div><?php endif; ?>
    </div>

    <div class="form-group">
        <label for="confirm">Confirm password</label>
        <input type="password" id="confirm" name="confirm" class="form-control <?= $errors['confirm'] ? 'has-error' : '' ?>"
               placeholder="Type the password again" autocomplete="new-password">
        <?php if ($errors['confirm']): ?><div class="field-error"><?= e($errors['confirm']) ?></div><?php endif; ?>
    </div>

    <div class="form-group">
        <label class="check-row" style="text-transform:none; font-weight:500;">
            <input type="checkbox" name="agree"> I agree to the Terms of Service and Collateral Policy
        </label>
        <?php if ($errors['agree']): ?><div class="field-error"><?= e($errors['agree']) ?></div><?php endif; ?>
    </div>

    <button type="submit" class="btn btn-primary btn-block">Create New account</button>
</form>

<div class="auth-foot">
    Already have an account? <a href="login.php">Log in</a>
    <hr>
</div>

<?php include 'includes/auth_footer.php'; ?>
