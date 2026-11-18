<?php
/**
 * student_loan_detail.php  —  ONE OF MY LOANS IN FULL   (NEW in v3)
 * ------------------------------------------------------------
 * Opened from "Detail" in My Borrowings. READ-ONLY: the student sees
 *   - the request (dates, purpose, status, rejection reason if rejected)
 *   - the items and their condition after the return
 *   - the photos taken at PICK-UP and at RETURN (so there is no argument
 *     about what the item looked like)
 *   - the damage report and the FINE (denda), and whether it is paid
 *
 * A student can only open THEIR OWN loans (l.user_id = the logged-in user).
 * ------------------------------------------------------------
 */
$page_title = 'My Borrowing Detail';
$nav_active = 'student_borrowings.php';
require_once 'includes/header.php';
require_role('student');

$loan_id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT l.id, l.borrow_date, l.return_date, l.purpose, l.collateral, l.status, l.rejection_reason,
           l.handed_over_at,
           (SELECT display_status FROM v_loan_overview v WHERE v.id = l.id) AS display_status,
           r.returned_at, r.was_late, r.compensation_amount, r.notes AS return_notes
    FROM loans l
    LEFT JOIN returns r ON r.loan_id = l.id
    WHERE l.id = ? AND l.user_id = ?
");
$stmt->execute([$loan_id, $_SESSION['user_id']]);
$loan = $stmt->fetch();

if (!$loan) {                                   // not found, or somebody else's loan
    flash_set('danger', 'That request was not found.');
    header('Location: student_borrowings.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT li.item_id, li.qty, li.condition_after, i.name, i.code, i.location
    FROM loan_items li JOIN items i ON i.id = li.item_id
    WHERE li.loan_id = ? ORDER BY i.name
");
$stmt->execute([$loan_id]);
$items = $stmt->fetchAll();

$photos = loan_photos($loan_id);                // from the uploads folder
$photo_total = 0;
foreach ($photos as $phases) foreach ($phases as $sides) $photo_total += count($sides);

$meta         = meta_read('loans/' . $loan_id);
$damage_text  = $meta['damage_description'] ?? (string)($loan['return_notes'] ?? '');
$fine         = !empty($loan['returned_at']) ? (float)$loan['compensation_amount'] : 0.0;
$fine_paid_at = $meta['fine_paid_at'] ?? null;
$has_damage   = false;
foreach ($items as $it) if (!empty($it['condition_after']) && $it['condition_after'] !== 'good') $has_damage = true;
?>

<h1>Borrowing Detail</h1>
<p class="subtitle" style="margin-bottom:6px;">Your request, the photos and any damage report</p>
<p class="breadcrumb" style="font-size:0.95rem;">
    <a href="student_borrowings.php" style="text-decoration:none;">My Borrowings</a> &nbsp;›&nbsp; <strong>Request #<?= (int)$loan['id'] ?></strong>
</p>

<div class="panel">
    <h3>Request <?= status_badge($loan['display_status'] ?: $loan['status']) ?></h3>

    <div class="readonly-label">Items</div>
    <div class="readonly-field">
        <?php foreach ($items as $it): ?>
            <?= e($it['name']) ?> x<?= (int)$it['qty'] ?> — <?= e($it['location']) ?><br>
        <?php endforeach; ?>
    </div>

    <div class="grid-two-col" style="gap:0 24px;">
        <div>
            <div class="readonly-label">Borrow date</div>
            <div class="readonly-field"><?= e(format_date($loan['borrow_date'])) ?></div>
        </div>
        <div>
            <div class="readonly-label">Return by</div>
            <div class="readonly-field"><?= e(format_date($loan['return_date'])) ?></div>
        </div>
    </div>

    <div class="readonly-label">Purpose</div>
    <div class="readonly-field" style="white-space:normal;"><?= e($loan['purpose']) ?></div>

    <div class="readonly-label">Collateral held by the staff</div>
    <div class="readonly-field"><?= e($loan['collateral']) ?></div>

    <?php if (!empty($loan['returned_at'])): ?>
        <div class="readonly-label">Returned</div>
        <div class="readonly-field"><?= e(format_date($loan['returned_at'], 'd M Y H:i')) ?> — <?= $loan['was_late'] ? 'late' : 'on time' ?></div>
    <?php endif; ?>

    <?php if ($loan['status'] === 'rejected'): ?>
        <div class="alert alert-danger" style="margin:8px 0 0;">Your request was rejected: <?= e($loan['rejection_reason']) ?></div>
    <?php endif; ?>
</div>

<!-- ============ PERMISSION FORM (high-clearance items) ============ -->
<?php
$clr_items = loan_clearance_items($pdo, $loan_id);
if ($clr_items && $loan['status'] !== 'rejected'):
    $clr      = loan_clearance_state($loan_id);
    $clr_open = in_array($loan['status'], ['requested', 'approved'], true);   // before pick-up
?>
<div class="panel clearance-box">
    <h3>Signed permission form</h3>
    <p class="muted" style="margin-bottom:10px;">Some of the items you asked for need higher permission before you can pick them up.</p>

    <div class="readonly-label">Where to get the permission</div>
    <div class="readonly-field" style="white-space:normal; line-height:1.6;">
        <?php foreach ($clr_items as $ci): ?>
            <strong><?= e($ci['name']) ?></strong> — <?= e($ci['contact'] !== '' ? $ci['contact'] : 'ask the facilities admin') ?><br>
        <?php endforeach; ?>
    </div>

    <?php if ($clr['file']): ?>
        <div class="readonly-label">Your form</div>
        <div class="readonly-field" style="white-space:normal;">
            <span class="badge approved">Uploaded</span>
            <?= e(format_date($clr['uploaded_at'], 'd M Y H:i')) ?> —
            <a class="link-action" href="api/clearance_file.php?loan_id=<?= (int)$loan['id'] ?>" target="_blank"><?= e($clr['original'] ?: 'Open the form') ?></a>
        </div>
    <?php endif; ?>

    <?php if (!$clr['requested_at']): ?>
        <p class="muted" style="margin:0;">The admin will ask you for the form after reviewing your request, and you will get a notification.</p>
    <?php elseif ($clr_open): ?>
        <div class="alert alert-info" id="clearanceMsg" style="display:none;"></div>
        <div class="form-group">
            <label for="clearanceFile"><?= $clr['file'] ? 'Replace the form' : 'Upload the signed form' ?> <span class="req">*</span></label>
            <input type="file" id="clearanceFile" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx">
            <div class="field-hint">PDF, photo (JPG / PNG / WEBP) or Word file, up to 10 MB. Bring the original form when you pick up the items.</div>
        </div>
        <button type="button" class="btn btn-primary" onclick="uploadClearance()"><?= $clr['file'] ? 'Replace form' : 'Upload form' ?></button>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($fine > 0 || $has_damage): ?>
<h2 class="section-title" style="margin-top:0;">Damage report</h2>
<div class="panel">
    <div class="readonly-label">What was found</div>
    <div class="readonly-field" style="white-space:normal;"><?= e($damage_text ?: '—') ?></div>

    <div class="readonly-label">Damage fine</div>
    <div class="readonly-field">
        <?= $fine > 0 ? e(format_rupiah($fine)) : 'No fine' ?>
        <?php if ($fine > 0): ?>
            &nbsp; <?= $fine_paid_at
                ? '<span class="badge approved">Paid ' . e(format_date($fine_paid_at)) . '</span>'
                : '<span class="badge rejected">Unpaid — please settle it with the facilities admin</span>' ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($photo_total > 0): ?>
<h2 class="section-title" style="margin-top:0;">Photos: pick-up vs return</h2>
<?= render_photo_compare($items, $photos) ?>
<?php endif; ?>

<script>
// Sends the chosen form to api/upload_clearance.php, then reloads to show the new state
function uploadClearance() {
    var input = document.getElementById('clearanceFile');
    var box   = document.getElementById('clearanceMsg');
    if (!input.files.length) { alert('Please choose the signed form file first.'); return; }

    var fd = new FormData();
    fd.append('loan_id', <?= (int)$loan['id'] ?>);
    fd.append('form', input.files[0]);

    fetch('api/upload_clearance.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) { location.reload(); return; }
            box.className = 'alert alert-danger'; box.style.display = 'block'; box.textContent = data.error;
        })
        .catch(function () { alert('Network error. Please try again.'); });
}
</script>

<?php include 'includes/footer.php'; ?>
