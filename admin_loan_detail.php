<?php
/**
 * admin_loan_detail.php  —  ONE LOAN IN FULL   (NEW in v3)
 * ------------------------------------------------------------
 * Opened from the "Detail" link in Manage Requests, the admin dashboard and
 * the Damage Reports list.
 *
 * WHAT THE ADMIN SEES
 *   1. The request: student, dates, purpose, collateral, status, who approved it
 *   2. The items with their condition before / after
 *   3. PHOTOS: pick-up photo and return photo side by side, per item and per side
 *   4. The DAMAGE REPORT (only after the return): description, fine (denda), paid or not
 *   5. PHOTO VERIFICATION: the admin confirms the photos are correct (for the report)
 *
 * WHAT THE ADMIN CAN DO HERE
 *   - "Verify photos"          -> api/verify_photos.php
 *   - "Mark fine as paid"      -> api/mark_fine_paid.php
 * ------------------------------------------------------------
 */
$page_title = 'Loan Detail';
$nav_active = 'manage_requests.php';
require_once 'includes/header.php';
require_role('admin');

$loan_id = (int)($_GET['id'] ?? 0);

// ---- The loan, with the people involved and the return record (if any) ----
$stmt = $pdo->prepare("
    SELECT l.id, l.borrow_date, l.return_date, l.purpose, l.collateral, l.status, l.rejection_reason,
           l.handed_over_at, l.reviewed_at,
           u.full_name, u.nim, u.email, u.phone,
           rv.full_name AS reviewer,
           (SELECT display_status FROM v_loan_overview v WHERE v.id = l.id) AS display_status,
           r.returned_at, r.was_late, r.compensation_amount, r.notes AS return_notes
    FROM loans l
    JOIN users u         ON u.id  = l.user_id
    LEFT JOIN users rv   ON rv.id = l.reviewed_by
    LEFT JOIN returns r  ON r.loan_id = l.id
    WHERE l.id = ?
");
$stmt->execute([$loan_id]);
$loan = $stmt->fetch();

if (!$loan) {
    flash_set('danger', 'That request does not exist.');
    header('Location: manage_requests.php');
    exit;
}

// ---- Items of the loan ----
$stmt = $pdo->prepare("
    SELECT li.item_id, li.qty, li.condition_before, li.condition_after, i.name, i.code, i.location
    FROM loan_items li JOIN items i ON i.id = li.item_id
    WHERE li.loan_id = ? ORDER BY i.name
");
$stmt->execute([$loan_id]);
$items = $stmt->fetchAll();

// ---- Photos, grouped as $photos[item_id][phase][side] = path (read from the uploads folder) ----
$photos = loan_photos($loan_id);
$photo_total = 0;
foreach ($photos as $phases) foreach ($phases as $sides) $photo_total += count($sides);

// ---- Extra information kept next to the photos (uploads/loans/<id>/meta.json) ----
$meta          = meta_read('loans/' . $loan_id);
$verified_at   = $meta['verified_at']   ?? null;     // set by api/verify_photos.php
$verifier      = $meta['verified_name'] ?? '-';
$fine_paid_at  = $meta['fine_paid_at']  ?? null;     // set by api/mark_fine_paid.php
$damage_text   = $meta['damage_description'] ?? '';  // full text; the short copy is returns.notes
if ($damage_text === '') $damage_text = (string)($loan['return_notes'] ?? '');

$returned   = !empty($loan['returned_at']);
$has_fine   = $returned && (float)$loan['compensation_amount'] > 0;
$has_damage = false;
foreach ($items as $it) if (!empty($it['condition_after']) && $it['condition_after'] !== 'good') $has_damage = true;

function nice_condition(?string $c): string { return $c ? ucfirst(str_replace('_', ' ', $c)) : '—'; }
?>

<h1>Loan #<?= (int)$loan['id'] ?></h1>
<p class="subtitle" style="margin-bottom:6px;">Request details, photos and damage report</p>
<p class="breadcrumb" style="font-size:0.95rem;">
    <a href="manage_requests.php" style="text-decoration:none;">Manage Requests</a> &nbsp;›&nbsp; <strong>Loan #<?= (int)$loan['id'] ?></strong>
</p>

<?= flash_render() ?>
<div class="alert" id="detailMsg" style="display:none;"></div>

<!-- ============ 1. THE REQUEST ============ -->
<div class="panel">
    <h3>Request <?= status_badge($loan['display_status'] ?: $loan['status']) ?></h3>

    <div class="grid-two-col" style="gap:0 24px;">
        <div>
            <div class="readonly-label">Student</div>
            <div class="readonly-field"><?= e($loan['full_name']) ?> (<?= e($loan['nim'] ?? '-') ?>)</div>
            <div class="readonly-label">Contact</div>
            <div class="readonly-field"><?= e($loan['email']) ?><?= $loan['phone'] ? ' · ' . e($loan['phone']) : '' ?></div>
            <div class="readonly-label">Purpose</div>
            <div class="readonly-field" style="white-space:normal;"><?= e($loan['purpose']) ?></div>
        </div>
        <div>
            <div class="readonly-label">Borrow date → planned return date</div>
            <div class="readonly-field"><?= e(format_date($loan['borrow_date'])) ?> → <?= e(format_date($loan['return_date'])) ?></div>
            <div class="readonly-label">Collateral held</div>
            <div class="readonly-field"><?= e($loan['collateral']) ?></div>
            <div class="readonly-label">Timeline</div>
            <div class="readonly-field" style="font-weight:600; line-height:1.6;">
                <?= $loan['reviewed_at'] ? 'Reviewed ' . e(format_date($loan['reviewed_at'], 'd M Y H:i')) . ' by ' . e($loan['reviewer'] ?? '-') : 'Not reviewed yet' ?><br>
                <?= $loan['handed_over_at'] ? 'Picked up ' . e(format_date($loan['handed_over_at'], 'd M Y H:i')) : 'Not picked up yet' ?><br>
                <?= $returned ? 'Returned ' . e(format_date($loan['returned_at'], 'd M Y H:i')) . ($loan['was_late'] ? ' (late)' : ' (on time)') : 'Not returned yet' ?>
            </div>
        </div>
    </div>

    <?php if ($loan['status'] === 'rejected'): ?>
        <div class="alert alert-danger" style="margin:8px 0 0;">Rejected: <?= e($loan['rejection_reason']) ?></div>
    <?php endif; ?>
</div>

<!-- ============ 2. ITEMS ============ -->
<h2 class="section-title" style="margin-top:0;">Items</h2>
<div class="table-wrapper">
    <table style="min-width:560px;">
        <thead><tr><th>Item</th><th>Qty</th><th>Location</th><th>Condition before</th><th>Condition after return</th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
            <tr>
                <td><?= e($it['name']) ?> (<?= e($it['code']) ?>)</td>
                <td><?= (int)$it['qty'] ?></td>
                <td><?= e($it['location']) ?></td>
                <td><?= e(nice_condition($it['condition_before'])) ?></td>
                <td><?= e(nice_condition($it['condition_after'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ============ 3. PHOTOS ============ -->
<h2 class="section-title">Photos: pick-up vs return</h2>
<?php if ($photo_total === 0): ?>
    <p class="muted">No photos yet. Pick-up photos are added when the items are handed over; return photos when they come back.</p>
<?php else: ?>
    <?= render_photo_compare($items, $photos) ?>
<?php endif; ?>

<!-- ============ 4. DAMAGE REPORT ============ -->
<?php if ($returned && ($has_damage || $has_fine)): ?>
<h2 class="section-title">Damage report</h2>
<div class="panel">
    <div class="readonly-label">Damage description</div>
    <div class="readonly-field" style="white-space:normal;"><?= e($damage_text ?: '—') ?></div>

    <div class="readonly-label">Damage fine (denda kerusakan)</div>
    <div class="readonly-field">
        <?= $has_fine ? e(format_rupiah($loan['compensation_amount'])) : 'No fine' ?>
        <?php if ($has_fine): ?>
            &nbsp; <?= $fine_paid_at
                ? '<span class="badge approved">Paid ' . e(format_date($fine_paid_at)) . '</span>'
                : '<span class="badge rejected">Unpaid</span>' ?>
        <?php endif; ?>
    </div>

    <?php if (!empty($meta['return_notes'])): ?>
        <div class="readonly-label">Notes</div>
        <div class="readonly-field" style="white-space:normal;"><?= e($meta['return_notes']) ?></div>
    <?php endif; ?>

    <?php if ($has_fine && !$fine_paid_at): ?>
        <button class="btn btn-primary" onclick="markFinePaid()">Mark fine as paid</button>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ============ 5. PHOTO VERIFICATION ============ -->
<?php if ($photo_total > 0): ?>
<div class="panel">
    <h3>Photo verification</h3>
    <?php if ($verified_at): ?>
        <p><span class="badge approved">Verified</span>
           by <?= e($verifier) ?> on <?= e(format_date($verified_at, 'd M Y H:i')) ?></p>
    <?php else: ?>
        <p class="muted" style="margin-bottom:12px;">
            Compare the photos above. If they are correct and usable as evidence for the report, confirm them.
        </p>
        <button class="btn btn-blue" onclick="verifyPhotos()">Verify photos</button>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
// Both buttons call a small api/ file, then reload this page to show the new state.
var LOAN_ID = <?= (int)$loan['id'] ?>;

function verifyPhotos() {
    if (!confirm('Confirm that these photos are correct?')) return;
    postJSON('api/verify_photos.php', { loan_id: LOAN_ID }).then(function (data) {
        if (data.success) location.reload(); else alert('Error: ' + data.error);
    }).catch(function (err) { alert('Network error: ' + err); });
}

function markFinePaid() {
    if (!confirm('Mark this fine as paid? The student will be notified.')) return;
    postJSON('api/mark_fine_paid.php', { loan_id: LOAN_ID }).then(function (data) {
        if (data.success) location.reload(); else alert('Error: ' + data.error);
    }).catch(function (err) { alert('Network error: ' + err); });
}
</script>

<?php include 'includes/footer.php'; ?>
