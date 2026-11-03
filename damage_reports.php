<?php
/**
 * damage_reports.php  —  DAMAGE REPORTS  ("Laporan barang rusak", NEW in v3)
 * ------------------------------------------------------------
 * WHAT THIS PAGE SHOWS
 *   Every returned loan where an item came back damaged OR a fine (denda) was set.
 *   Three numbers on top: number of reports, total fines, fines still unpaid.
 *   Tabs: All | Unpaid fines | Paid fines | No fine.
 *   "Detail" opens admin_loan_detail.php with the pick-up vs return photos,
 *   the description and the buttons to verify photos / mark the fine as paid.
 *
 * WHERE THE DATA COMES FROM (filled by api/process_return.php when an item is returned)
 *   loan_items.condition_after       good / minor_damage / damaged        (your database)
 *   returns.compensation_amount      the fine in Rupiah                   (your database)
 *   returns.notes                    short damage note                    (your database)
 *   uploads/loans/<id>/meta.json     fine paid? photos verified?          (files, no DB change)
 * ------------------------------------------------------------
 */
$page_title = 'Damage Reports';
require_once 'includes/header.php';
require_role('admin');

// What counts as a "damage report": something not in good condition, OR a fine was set.
// Only columns that already exist in your database are used.
$all = $pdo->query("
    SELECT l.id AS loan_id, u.full_name, u.nim, r.returned_at, r.compensation_amount, r.notes,
           (SELECT string_agg(i.name || ' (' || replace(li.condition_after::text, '_', ' ') || ')', ', ')
              FROM loan_items li JOIN items i ON i.id = li.item_id
             WHERE li.loan_id = l.id AND li.condition_after IS NOT NULL AND li.condition_after <> 'good') AS damaged_items
    FROM returns r
    JOIN loans l ON l.id = r.loan_id
    JOIN users u ON u.id = l.user_id
    WHERE r.compensation_amount > 0
       OR EXISTS (SELECT 1 FROM loan_items li
                   WHERE li.loan_id = l.id AND li.condition_after IS NOT NULL AND li.condition_after <> 'good')
    ORDER BY r.returned_at DESC
")->fetchAll();

// "Fine paid" and "photos verified" are kept in uploads/loans/<id>/meta.json, so we add them here.
$stats = ['total' => 0, 'fines' => 0.0, 'unpaid' => 0.0];
foreach ($all as &$row) {
    $meta                 = meta_read('loans/' . $row['loan_id']);
    $row['fine']          = (float)$row['compensation_amount'];
    $row['fine_paid']     = !empty($meta['fine_paid_at']);
    $row['verified']      = !empty($meta['verified_at']);
    $stats['total']++;
    $stats['fines']      += $row['fine'];
    if ($row['fine'] > 0 && !$row['fine_paid']) $stats['unpaid'] += $row['fine'];
}
unset($row);

// ---- Tab filter (done in PHP because "paid" lives in the meta files) ----
$tab  = $_GET['tab'] ?? 'all';
$tabs = [
    'all'    => 'All',
    'unpaid' => 'Unpaid fines',
    'paid'   => 'Paid fines',
    'nofine' => 'No fine',
];
if (!isset($tabs[$tab])) $tab = 'all';

$filtered = array_values(array_filter($all, function ($r) use ($tab) {
    if ($tab === 'unpaid') return $r['fine'] > 0 && !$r['fine_paid'];
    if ($tab === 'paid')   return $r['fine'] > 0 && $r['fine_paid'];
    if ($tab === 'nofine') return $r['fine'] <= 0;
    return true;
}));

// ---- Pagination (also in PHP) ----
$per_page    = 8;
$total_pages = max(1, (int)ceil(count($filtered) / $per_page));
$page        = min(max(1, (int)($_GET['page'] ?? 1)), $total_pages);
$rows        = array_slice($filtered, ($page - 1) * $per_page, $per_page);
?>

<h1>Damage Reports</h1>
<p class="subtitle">Items returned damaged, with fines and photo evidence</p>

<div class="stat-row" style="margin:6px 0 24px;">
    <div class="stat-card blue"><div class="stat-label">Damage reports</div><div class="stat-value"><?= (int)$stats['total'] ?></div></div>
    <div class="stat-card blue"><div class="stat-label">Total fines</div><div class="stat-value text"><?= e(format_rupiah($stats['fines'])) ?></div></div>
    <div class="stat-card blue"><div class="stat-label">Unpaid fines</div><div class="stat-value text"><?= e(format_rupiah($stats['unpaid'])) ?></div></div>
</div>

<div class="tabs">
    <?php foreach ($tabs as $key => $label): ?>
        <a class="tab <?= $tab === $key ? 'active' : '' ?>" href="?tab=<?= e($key) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<div class="table-wrapper">
    <table>
        <thead>
            <tr><th>Student</th><th>Damaged items</th><th>Returned</th><th>Fine</th><th>Fine status</th><th>Photos</th><th>Action</th></tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="7" style="text-align:center;" class="muted">No damage reports here.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $r): $fine = $r['fine']; ?>
                <tr>
                    <td><?= e($r['full_name']) ?> (<?= e($r['nim'] ?? '-') ?>)</td>
                    <td><?= e($r['damaged_items'] ?: 'Fine only') ?></td>
                    <td><?= e(format_date($r['returned_at'])) ?></td>
                    <td><?= $fine > 0 ? e(format_rupiah($fine)) : '—' ?></td>
                    <td>
                        <?php if ($fine <= 0): ?>—
                        <?php elseif ($r['fine_paid']): ?><span class="badge approved">Paid</span>
                        <?php else: ?><span class="badge rejected">Unpaid</span><?php endif; ?>
                    </td>
                    <td><?= $r['verified'] ? '<span class="badge approved">Verified</span>' : '<span class="badge pending">Not verified</span>' ?></td>
                    <td><a class="link-action" href="admin_loan_detail.php?id=<?= (int)$r['loan_id'] ?>">Detail</a></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?= render_pagination($page, $total_pages, ['tab' => $tab]) ?>

<?php include 'includes/footer.php'; ?>
