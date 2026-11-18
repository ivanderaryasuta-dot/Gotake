<?php
/**
 * student_new_request.php  —  BORROW REQUEST  (now supports SEVERAL ITEMS = a "bulk" loan)
 * ------------------------------------------------------------
 * WHAT CHANGED
 *   The student keeps a small "request list" (stored in the session). From the catalog:
 *     - "Request to borrow"  adds the item and opens this page
 *     - "+ List"             adds the item and stays in the catalog, so more can be added
 *   This page shows every item of the list (each with its own quantity), ONE set of
 *   dates / purpose / collateral, and one "Submit Request" button.
 *
 *   Saving creates ONE loan (table loans) with one row per item (table loan_items).
 *   Your database already worked like that, so NO database change is needed. The admin
 *   sees it as a single request marked "Bulk · N items".
 *
 * HOW SAVING WORKS
 *   1. Validate the form
 *   2. Start a DATABASE TRANSACTION (all-or-nothing)
 *   3. INSERT the loan (status = 'requested')
 *   4. For EVERY item: check the stock on those dates (item_available_on()), INSERT the loan item
 *   5. COMMIT. If anything fails -> ROLLBACK, nothing is saved, the error is shown.
 * ------------------------------------------------------------
 */
$page_title = 'Borrow Request';
$nav_active = 'student_catalog.php';       // keep "Item Catalog" highlighted in the sidebar
require_once 'includes/header.php';
require_role('student');

if (!isset($_SESSION['borrow_cart']) || !is_array($_SESSION['borrow_cart'])) $_SESSION['borrow_cart'] = [];

// ------------------------------------------------------------
// 1. CHANGE THE REQUEST LIST  (?item_id=5 adds an item, ?remove=5 takes it out)
// ------------------------------------------------------------
if (isset($_GET['item_id']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $add = (int)$_GET['item_id'];
    $chk = $pdo->prepare("SELECT 1 FROM items WHERE id = ? AND is_active = TRUE");
    $chk->execute([$add]);
    if ($chk->fetchColumn()) {
        if (!isset($_SESSION['borrow_cart'][$add])) $_SESSION['borrow_cart'][$add] = 1;
    } else {
        flash_set('danger', 'That item is not available.');
    }
    header('Location: student_new_request.php');      // clean address, so F5 does not add it again
    exit;
}
if (isset($_GET['remove'])) {
    unset($_SESSION['borrow_cart'][(int)$_GET['remove']]);
    header('Location: student_new_request.php');
    exit;
}

// ------------------------------------------------------------
// 2. LOAD THE ITEMS OF THE LIST  (inactive / deleted items drop out automatically)
// ------------------------------------------------------------
$cart_items = [];
if ($_SESSION['borrow_cart']) {
    $ids = array_map('intval', array_keys($_SESSION['borrow_cart']));
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT i.id, i.code, i.name, i.available_qty, i.total_qty, i.location, c.name AS category
        FROM items i
        JOIN categories c ON c.id = i.category_id
        WHERE i.id IN ($in) AND i.is_active = TRUE
        ORDER BY c.name, i.name
    ");
    $stmt->execute($ids);
    $cart_items = $stmt->fetchAll();
    // forget the ones that no longer exist
    $found = array_map('intval', array_column($cart_items, 'id'));
    foreach ($ids as $id) if (!in_array($id, $found, true)) unset($_SESSION['borrow_cart'][$id]);
}

// Empty list -> back to the catalog
if (!$cart_items) {
    flash_set('danger', 'Please choose an item from the catalog first.');
    header('Location: student_catalog.php');
    exit;
}

$is_bulk = count($cart_items) > 1;

// Settings from the admin Settings page
$max_days           = (int)get_setting($pdo, 'max_loan_days', 3);
$require_collateral = setting_bool($pdo, 'require_collateral', true);   // stored as 'true'/'false'
$first_location     = $cart_items[0]['location'] ?? '';
$who                = $first_location ? $first_location . ' admin' : 'facilities admin';

$error = '';

// ------------------------------------------------------------
// 3. THE FORM WAS SUBMITTED
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $borrow_date = $_POST['borrow_date'] ?? '';
        $return_date = $_POST['return_date'] ?? '';
        $purpose     = trim($_POST['purpose'] ?? '');
        // If collateral is switched off in Settings we still store 'KTM': in your database
        // loans.collateral is an ENUM('KTM','KTP') that cannot be empty, and the rule
        // chk_collateral requires collateral_agreed = TRUE (the INSERT below always sets it).
        $collateral  = $require_collateral ? ($_POST['collateral'] ?? 'KTM') : 'KTM';
        $agreed      = !empty($_POST['agree']);

        // The list of items with the quantity typed for each one
        $selected_items = [];
        foreach ($cart_items as $ci) {
            $q = (int)($_POST['qty'][$ci['id']] ?? 1);
            if ($q < 1) throw new Exception('Quantity of "' . $ci['name'] . '" must be at least 1.');
            $selected_items[] = ['id' => (int)$ci['id'], 'qty' => $q, 'name' => $ci['name']];
        }

        // ---- Validation: throw an Exception = jump to the "catch" below ----
        if (empty($borrow_date) || empty($return_date)) throw new Exception("Please select both borrow and return dates.");
        if (empty($purpose))                             throw new Exception("Please provide a purpose for borrowing.");
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
        $_SESSION['borrow_cart'] = [];   // the list is now a real request: start with an empty list next time

        // "PRG" pattern (Post -> Redirect -> Get): pressing F5 will NOT submit the request a second time.
        $names = implode(', ', array_map(function ($s) { return $s['name'] . ' x' . $s['qty']; }, $selected_items));
        flash_set('info', 'Your ' . (count($selected_items) > 1 ? 'bulk request (' . count($selected_items) . ' items)' : 'request')
            . ' for ' . $names . ' was submitted. Waiting for approval from the ' . $who . '.');
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

$any_unavailable = false;
foreach ($cart_items as $ci) if ((int)$ci['available_qty'] < 1) $any_unavailable = true;
?>

<h1>Borrow Request</h1>
<p class="subtitle" style="margin-bottom:6px;">
    <?= $is_bulk ? 'Complete the request for your ' . count($cart_items) . ' selected items (one bulk request)' : 'Complete the request for your selected item' ?>
</p>
<p class="breadcrumb">
    <a href="student_catalog.php" style="text-decoration:none;">Item Catalog</a> &nbsp;&gt;&nbsp; <strong>Borrow request</strong>
</p>

<?= flash_render() ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<!-- One <form> around both columns, so the quantity boxes on the left are sent together with the details on the right -->
<form method="POST" id="loanForm" class="split-layout split-request" novalidate>

    <!-- ============ LEFT: THE SELECTED ITEM(S) ============ -->
    <div class="request-items">
        <?php foreach ($cart_items as $it):
            $photos    = item_photos((int)$it['id']);
            $available = (int)$it['available_qty'] > 0;
            $qty_value = (int)($_POST['qty'][$it['id']] ?? ($_SESSION['borrow_cart'][$it['id']] ?? 1));
        ?>
        <article class="item-card" style="border:1.5px solid var(--line); margin-bottom:14px;">
            <div class="item-photo" style="height:<?= $is_bulk ? '120' : '160' ?>px;">
                <?= item_gallery_html($photos, $it['code'], $it['name']) ?>
            </div>
            <div class="item-body" style="min-height:0;">
                <div class="item-category"><?= e($it['category']) ?></div>
                <div class="item-name" style="font-size:1.2rem;"><?= e($it['name']) ?></div>
                <div class="item-meta" style="font-size:0.85rem;">Storage location: <?= e($it['location'] ?: 'not set') ?></div>
                <?php if (item_clearance((int)$it['id'])['required']): ?>
                    <div class="alert alert-info" style="margin:8px 0 0; font-size:0.8rem;">
                        This item needs a <strong>signed permission form</strong>. After you submit, the admin will tell you where to get it,
                        and you must upload it before you can pick the item up.
                    </div>
                <?php endif; ?>
                <div style="margin-top:8px;">
                    <?php if ($available): ?>
                        <span class="badge available">Available — Stock <?= (int)$it['available_qty'] ?>/<?= (int)$it['total_qty'] ?></span>
                    <?php else: ?>
                        <span class="badge unavailable">Not Available — Stock 0/<?= (int)$it['total_qty'] ?></span>
                    <?php endif; ?>
                </div>

                <div class="form-group" style="margin:12px 0 0;">
                    <label for="qty<?= (int)$it['id'] ?>">Quantity <span class="req">*</span></label>
                    <input type="number" name="qty[<?= (int)$it['id'] ?>]" id="qty<?= (int)$it['id'] ?>" class="form-control" min="1"
                           max="<?= max(1, (int)$it['available_qty']) ?>" value="<?= max(1, $qty_value) ?>" style="max-width:140px;" required>
                </div>

                <p style="margin:10px 0 0;"><a class="link-action danger" href="?remove=<?= (int)$it['id'] ?>">Remove from request</a></p>
            </div>
        </article>
        <?php endforeach; ?>

        <a href="student_catalog.php" class="btn btn-outline" style="padding:10px 18px; display:inline-block;">+ Add another item</a>
    </div>

    <!-- ============ RIGHT: THE FORM ============ -->
    <div class="request-details">
        <h2 style="font-size:1.6rem;">Borrowing details</h2>
        <p class="subtitle" style="font-size:0.85rem; font-weight:600; margin-bottom:14px;">
            Fill in the form below an admin will verify it before you can pick up <?= $is_bulk ? 'the items' : 'the item' ?>
        </p>

        <?php if ($is_bulk): ?>
            <div class="alert alert-info" style="margin-bottom:14px;">
                <strong>Bulk request:</strong> these <?= count($cart_items) ?> items are sent as ONE request, so the admin sees that you are borrowing them together.
            </div>
        <?php endif; ?>

        <p class="req-note"><span class="req">*</span> Required</p>

        <div class="grid-two-col" style="gap:16px;">
            <div class="form-group">
                <label for="borrowDate">Borrow date <span class="req">*</span></label>
                <input type="date" name="borrow_date" id="borrowDate" class="form-control"
                       min="<?= date('Y-m-d') ?>" value="<?= e($_POST['borrow_date'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label for="returnDate">Return date <span class="req">*</span> <small>(maximum <?= $max_days ?> days)</small></label>
                <input type="date" name="return_date" id="returnDate" class="form-control"
                       min="<?= date('Y-m-d') ?>" value="<?= e($_POST['return_date'] ?? '') ?>" required>
            </div>
        </div>

        <div class="form-group">
            <label for="purpose">Purpose / reason <span class="req">*</span></label>
            <textarea name="purpose" id="purpose" class="form-control" rows="3" required
                      placeholder="Give your reason..."><?= e($_POST['purpose'] ?? '') ?></textarea>
        </div>

        <?php if ($require_collateral): ?>
            <!-- Collateral section (can be turned off in admin Settings) -->
            <div class="form-group">
                <label for="collateral">Collateral type <span class="req">*</span></label>
                <select name="collateral" id="collateral" class="form-control" style="max-width:260px;">
                    <option value="KTM" <?= (($_POST['collateral'] ?? '') === 'KTM') ? 'selected' : '' ?>>KTM (Student Card)</option>
                    <option value="KTP" <?= (($_POST['collateral'] ?? '') === 'KTP') ? 'selected' : '' ?>>KTP (National ID)</option>
                </select>
            </div>

            <div class="readonly-field" style="font-weight:600;">
                Your active Student ID Card KTM/KTP will be held temporarily by the
                <?= e($first_location ?: 'facilities') ?> staff as collateral for the loan period.
            </div>

            <label class="check-row" style="margin-bottom:18px; font-weight:600;">
                <input type="checkbox" name="agree" <?= !empty($_POST['agree']) ? 'checked' : '' ?>>
                I agree to have my Student ID Card (KTM) held as collateral <span class="req">*</span>
            </label>
        <?php endif; ?>

        <div class="btn-row" style="margin-top:6px;">
            <button type="submit" class="btn btn-primary" style="flex:1; padding:12px;" <?= $any_unavailable ? 'disabled' : '' ?>>Submit Request</button>
            <a href="student_catalog.php" class="btn btn-outline" style="padding:12px 30px;">Cancel</a>
        </div>
        <?php if ($any_unavailable): ?>
            <p class="field-hint">One of the items is out of stock. Remove it from the request to continue.</p>
        <?php endif; ?>
    </div>
</form>

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
