<?php
/**
 * student_new_request.php  —  BORROW REQUEST  ("Borrow Request" in PBL.pdf)
 * ------------------------------------------------------------
 * WHAT THIS PAGE DOES
 *   The student reaches this page by pressing "Request to borrow" on a
 *   catalog card (the link contains ?item_id=NUMBER). They choose
 *   dates, a quantity, write a reason, agree to the collateral policy
 *   and press "Submit Request".
 *
 * WHAT CHANGED FROM YOUR PREVIOUS VERSION
 *   - New design: the chosen item is shown on the left, the form on the right
 *   - The item is chosen in the catalog instead of a dropdown list
 *     (the save logic below is the SAME as before, it still works
 *     with a list of items so you could allow several items later)
 *   - After saving, the student is sent to "My Borrowings"
 *   - The collateral section can be switched off in admin Settings
 *
 * HOW SAVING WORKS  (unchanged idea)
 *   1. Validate the form
 *   2. Start a DATABASE TRANSACTION (all-or-nothing)
 *   3. INSERT the loan (status = 'requested')
 *   4. Check that enough stock exists on those dates
 *      (database function item_available_on())
 *   5. INSERT the loan item, then COMMIT.
 *      If anything fails -> ROLLBACK, nothing is saved, show the error.
 * ------------------------------------------------------------
 */
$page_title = 'Borrow Request';
$nav_active = 'student_catalog.php';       // keep "Item Catalog" highlighted in the sidebar
require_once 'includes/header.php';
require_role('student');

// ------------------------------------------------------------
// 1. WHICH ITEM? (from the URL  ?item_id=5  or from the hidden field on POST)
// ------------------------------------------------------------
$item_id = (int)($_POST['item_id'] ?? $_GET['item_id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT i.id, i.code, i.name, i.available_qty, i.total_qty, i.location, c.name AS category
    FROM items i
    JOIN categories c ON c.id = i.category_id
    WHERE i.id = ? AND i.is_active = TRUE
");
$stmt->execute([$item_id]);
$item = $stmt->fetch();

// Unknown item -> back to the catalog
if (!$item) {
    flash_set('danger', 'Please choose an item from the catalog first.');
    header('Location: student_catalog.php');
    exit;
}

// Settings from the admin Settings page
$max_days           = (int)get_setting($pdo, 'max_loan_days', 3);
$require_collateral = setting_bool($pdo, 'require_collateral', true);   // stored as 'true'/'false'
$who                = $item['location'] ? $item['location'] . ' admin' : 'facilities admin';

$error = '';

// ------------------------------------------------------------
// 2. THE FORM WAS SUBMITTED
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $borrow_date = $_POST['borrow_date'] ?? '';
        $return_date = $_POST['return_date'] ?? '';
        $purpose     = trim($_POST['purpose'] ?? '');
        $qty         = (int)($_POST['qty'] ?? 1);
        // If collateral is switched off in Settings we still store 'KTM': in your database
        // loans.collateral is an ENUM('KTM','KTP') that cannot be empty, and the rule
        // chk_collateral requires collateral_agreed = TRUE (the INSERT below always sets it).
        $collateral  = $require_collateral ? ($_POST['collateral'] ?? 'KTM') : 'KTM';
        $agreed      = !empty($_POST['agree']);

        // A list of items (this page sends one, but the code below handles many)
        $selected_items = [['id' => $item['id'], 'qty' => $qty]];

        // ---- Validation: throw an Exception = jump to the "catch" below ----
        if (empty($borrow_date) || empty($return_date)) throw new Exception("Please select both borrow and return dates.");
        if (empty($purpose))                             throw new Exception("Please provide a purpose for borrowing.");
        if ($qty < 1)                                    throw new Exception("Quantity must be at least 1.");
        if (strtotime($return_date) < strtotime($borrow_date)) throw new Exception("Return date cannot be before borrow date.");
        if ($require_collateral && !$agreed)             throw new Exception("You must agree to have your ID card held as collateral.");
        if (!in_array($collateral, ['KTM', 'KTP']))      throw new Exception("Invalid collateral type.");

        // ---- Save everything or nothing ----
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO loans (user_id, borrow_date, return_date, purpose, collateral, collateral_agreed, status)
            VALUES (?, ?, ?, ?, ?, TRUE, 'requested')
            RETURNING id
        ");
        $stmt->execute([$_SESSION['user_id'], $borrow_date, $return_date, $purpose, $collateral]);
        $loan_id = $stmt->fetchColumn();

        foreach ($selected_items as $sel) {
            // How many are really free on THESE dates? (the database function knows about other loans)
            $check = $pdo->prepare("
                SELECT i.name, i.available_qty,
                       LEAST(i.available_qty, item_available_on(i.id, ?, ?)) AS effective_available
                FROM items i WHERE i.id = ?
            ");
            $check->execute([$borrow_date, $return_date, $sel['id']]);
            $info = $check->fetch();

            if ((int)$info['effective_available'] < $sel['qty']) {
                throw new Exception(
                    "Not enough stock for \"" . $info['name'] . "\". " .
                    "Physically in storage: " . $info['available_qty'] . ". " .
                    "Available on your requested dates: " . $info['effective_available'] . ". " .
                    "You requested: " . $sel['qty'] . "."
                );
            }

            $stmt = $pdo->prepare("INSERT INTO loan_items (loan_id, item_id, qty) VALUES (?, ?, ?)");
            $stmt->execute([$loan_id, $sel['id'], $sel['qty']]);
        }

        $pdo->commit();   // success: make it permanent

        // "PRG" pattern (Post -> Redirect -> Get): redirecting after a successful POST means
        // pressing F5 will NOT submit the request a second time.
        flash_set('info', 'Your request for ' . $item['name'] . ' was submitted. Waiting for approval from the ' . $who . '.');
        header("Location: student_borrowings.php");
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();   // undo any half-saved data

        // The database trigger complains in technical English; translate it.
        if (strpos($e->getMessage(), 'Loan duration exceeds maximum') !== false) {
            $error = "Your requested duration is too long (maximum $max_days days). Please shorten your return date.";
        } else {
            $error = $e->getMessage();
        }
    }
}

$is_available = (int)$item['available_qty'] > 0;
?>

<h1>Borrow Request</h1>
<p class="subtitle" style="margin-bottom:6px;">Complete the request for your selected item</p>
<p class="breadcrumb">
    <a href="student_catalog.php" style="text-decoration:none;">Item Catalog</a> &nbsp;&gt;&nbsp; <strong>Borrow request</strong>
</p>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<div class="split-layout" style="grid-template-columns: 370px 1fr;">

    <!-- ============ LEFT: THE SELECTED ITEM ============ -->
    <article class="item-card" style="border:1.5px solid var(--line);">
        <div class="item-photo" style="height:160px;">
            <!-- Photo slot: assets/img/items/<ITEM CODE>.png -->
            <?= img_slot('items/' . $item['code'], '', $item['name']) ?>
        </div>
        <div class="item-body" style="min-height:230px;">
            <div class="item-category"><?= e($item['category']) ?></div>
            <div class="item-name" style="font-size:1.3rem;"><?= e($item['name']) ?></div>
            <div class="item-meta" style="font-size:0.85rem;">Storage location: <?= e($item['location'] ?: 'not set') ?></div>
            <div style="margin-top:8px;">
                <?php if ($is_available): ?>
                    <span class="badge available">Available — Stock <?= (int)$item['available_qty'] ?>/<?= (int)$item['total_qty'] ?></span>
                <?php else: ?>
                    <span class="badge unavailable">Not Available — Stock 0/<?= (int)$item['total_qty'] ?></span>
                <?php endif; ?>
            </div>
        </div>
    </article>

    <!-- ============ RIGHT: THE FORM ============ -->
    <form method="POST" id="loanForm" novalidate>
        <!-- Hidden field: tells PHP which item this form is for -->
        <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">

        <h2 style="font-size:1.6rem;">Borrowing details</h2>
        <p class="subtitle" style="font-size:0.85rem; font-weight:600; margin-bottom:14px;">
            Fill in the form below an admin will verify it before you can pick up the item
        </p>

        <div class="grid-two-col" style="gap:16px;">
            <div class="form-group">
                <label for="borrowDate">Borrow date</label>
                <input type="date" name="borrow_date" id="borrowDate" class="form-control"
                       min="<?= date('Y-m-d') ?>" value="<?= e($_POST['borrow_date'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label for="returnDate">Return date <small>(maximum <?= $max_days ?> days)</small></label>
                <input type="date" name="return_date" id="returnDate" class="form-control"
                       min="<?= date('Y-m-d') ?>" value="<?= e($_POST['return_date'] ?? '') ?>" required>
            </div>
        </div>

        <div class="form-group">
            <label for="qty">Quantity</label>
            <input type="number" name="qty" id="qty" class="form-control" min="1"
                   max="<?= max(1, (int)$item['available_qty']) ?>" value="<?= e($_POST['qty'] ?? 1) ?>" style="max-width:140px;" required>
        </div>

        <div class="form-group">
            <label for="purpose">Purpose / reason</label>
            <textarea name="purpose" id="purpose" class="form-control" rows="3" required
                      placeholder="Give your reason..."><?= e($_POST['purpose'] ?? '') ?></textarea>
        </div>

        <?php if ($require_collateral): ?>
            <!-- Collateral section (can be turned off in admin Settings) -->
            <div class="form-group">
                <label for="collateral">Collateral type</label>
                <select name="collateral" id="collateral" class="form-control" style="max-width:260px;">
                    <option value="KTM" <?= (($_POST['collateral'] ?? '') === 'KTM') ? 'selected' : '' ?>>KTM (Student Card)</option>
                    <option value="KTP" <?= (($_POST['collateral'] ?? '') === 'KTP') ? 'selected' : '' ?>>KTP (National ID)</option>
                </select>
            </div>

            <div class="readonly-field" style="font-weight:600;">
                Your active Student ID Card KTM/KTP will be held temporarily by the
                <?= e($item['location'] ?: 'facilities') ?> staff as collateral for the loan period.
            </div>

            <label class="check-row" style="margin-bottom:18px; font-weight:600;">
                <input type="checkbox" name="agree" <?= !empty($_POST['agree']) ? 'checked' : '' ?>>
                I agree to have my Student ID Card (KTM) held as collateral
            </label>
        <?php endif; ?>

        <div class="btn-row" style="margin-top:6px;">
            <button type="submit" class="btn btn-primary" style="flex:1; padding:12px;" <?= $is_available ? '' : 'disabled' ?>>Submit Request</button>
            <a href="student_catalog.php" class="btn btn-outline" style="padding:12px 30px;">Cancel</a>
        </div>
    </form>
</div>

<script>
// ---- Small helpers so the student can't pick impossible dates ----
var MAX_DAYS   = <?= $max_days ?>;                       // value from the admin Settings page
var borrowEl   = document.getElementById('borrowDate');
var returnEl   = document.getElementById('returnDate');

// Format a Date object as YYYY-MM-DD (what <input type="date"> expects)
function toISO(d) { return d.toISOString().split('T')[0]; }

borrowEl.addEventListener('change', function () {
    if (!this.value) return;

    var start = new Date(this.value);

    // The return date cannot be before the borrow date ...
    returnEl.min = this.value;

    // ... and cannot be later than borrow date + MAX_DAYS
    var latest = new Date(start);
    latest.setDate(latest.getDate() + MAX_DAYS);
    returnEl.max = toISO(latest);

    // Convenience: if the return date is empty, suggest "next day"
    if (!returnEl.value) {
        var next = new Date(start);
        next.setDate(next.getDate() + 1);
        returnEl.value = toISO(next);
    }
});
</script>

<?php include 'includes/footer.php'; ?>
