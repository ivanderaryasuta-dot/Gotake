<?php
/**
 * reports.php  —  REPORTS   (REWRITTEN: the admin picks a date range first)
 * ------------------------------------------------------------
 * HOW IT WORKS NOW
 *   1. Opening the page shows ONLY a date-range form (from / to, any dates).
 *   2. After the admin presses "Show report", everything below is calculated
 *      for the loans whose BORROW DATE is inside that range:
 *        - 4 summary numbers
 *        - borrow trend per month, borrowing by category, requests by status,
 *          on-time vs late returns, damage reports and fines per month
 *        - top borrowed items
 *        - the LOAN LIST: who borrowed what, when, due date, return time, fine
 *          (a bulk loan with several items is ONE row)
 *   3. "Download Excel" gives the same loan list as an .xlsx file
 *      (api/export_report.php?from=...&to=...).
 *
 * The numbers come straight from the tables (loans, loan_items, returns ...),
 * not from the old v_report_* views, because those views cannot filter by date.
 * ------------------------------------------------------------
 */
$page_title = 'Reports';
require_once 'includes/header.php';
require_role('admin');
require_once 'includes/report_data.php';

// ---- 1. Which dates did the admin choose? ----
[$from, $to, $range_error] = report_parse_range($_GET['from'] ?? '', $_GET['to'] ?? '');
$has_range = ($from !== '' && $range_error === '');

if ($has_range) {
    // ---- 2. The loan list (the "new data") ----
    $loans = report_loans($pdo, $from, $to);

    // ---- 3. Summary numbers, worked out from that list ----
    $total_requests = count($loans);
    $handed_over    = 0;
    $on_time = 0; $late = 0;
    $total_fines = 0.0; $fines_paid = 0.0;
    $by_status = [];
    foreach ($loans as $l) {
        if ($l['handed_over_at']) $handed_over++;
        if ($l['returned_at']) { if ($l['was_late']) $late++; else $on_time++; }
        $total_fines += $l['fine'];
        if ($l['fine'] > 0 && $l['fine_paid']) $fines_paid += $l['fine'];
        $st = $l['status'];
        $by_status[$st] = ($by_status[$st] ?? 0) + 1;
    }
    $returns_total = $on_time + $late;
    $on_time_pct   = $returns_total > 0 ? (int)round(100 * $on_time / $returns_total) : 0;
    $late_text     = $returns_total > 0 ? (100 - $on_time_pct) . '%' : '—';

    // ---- 4. Items handed over in the range: top items + categories ----
    $stmt = $pdo->prepare("
        SELECT i.name, c.name AS category, COUNT(*) AS times
        FROM loan_items li
        JOIN loans l      ON l.id = li.loan_id
        JOIN items i      ON i.id = li.item_id
        JOIN categories c ON c.id = i.category_id
        WHERE l.borrow_date BETWEEN ? AND ? AND l.handed_over_at IS NOT NULL
        GROUP BY i.name, c.name
        ORDER BY times DESC, i.name
    ");
    $stmt->execute([$from, $to]);
    $item_rows = $stmt->fetchAll();

    $top_items = array_slice($item_rows, 0, 5);
    $most_borrowed = $top_items[0]['name'] ?? '—';
    $by_category = [];
    foreach ($item_rows as $r) $by_category[$r['category']] = ($by_category[$r['category']] ?? 0) + (int)$r['times'];
    arsort($by_category);

    // ---- 5. One bar per month of the range (borrow trend + damage/fines) ----
    $months = [];
    $cur = new DateTime(substr($from, 0, 7) . '-01');
    $end = new DateTime(substr($to, 0, 7) . '-01');
    while ($cur <= $end) {
        $months[$cur->format('Y-m')] = ['label' => $cur->format('M y'), 'count' => 0, 'damage' => 0, 'fines' => 0.0];
        $cur->modify('+1 month');
    }
    foreach ($loans as $l) {
        $ym = substr($l['borrow_date'], 0, 7);
        if (!isset($months[$ym])) continue;
        $months[$ym]['count']++;
        if ($l['returned_at'] && ($l['fine'] > 0 || $l['has_damage'])) {
            $months[$ym]['damage']++;
            $months[$ym]['fines'] += $l['fine'];
        }
    }
    $max_count  = max(1, max(array_column($months, 'count')));
    $max_damage = max(1, max(array_column($months, 'damage')));

    $status_chart = [
        'requested' => ['Pending',  'yellow'],
        'approved'  => ['Approved', 'blue'],
        'borrowed'  => ['Borrowed', 'blue'],
        'returned'  => ['Returned', 'green'],
        'rejected'  => ['Rejected', 'red'],
    ];

    $excel_url = 'api/export_report.php?' . http_build_query(['from' => $from, 'to' => $to]);
}
?>

<h1>Reports</h1>
<p class="subtitle">Choose a date range to see the borrowing report for that period</p>

<!-- ============ STEP 1: DATE RANGE (always shown) ============ -->
<form method="GET" class="panel" style="display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap; margin-bottom:22px;">
    <div class="form-group" style="margin:0;">
        <label for="from">From <span class="req">*</span></label>
        <input type="date" id="from" name="from" class="form-control" value="<?= e($from) ?>" required>
    </div>
    <div class="form-group" style="margin:0;">
        <label for="to">To <span class="req">*</span></label>
        <input type="date" id="to" name="to" class="form-control" value="<?= e($to) ?>" required>
    </div>
    <button type="submit" class="btn btn-primary" style="padding:11px 26px;">Show report</button>
    <?php if ($has_range): ?>
        <a href="reports.php" class="btn btn-outline" style="padding:11px 22px;">Clear</a>
    <?php endif; ?>
    <div class="field-hint" style="flex-basis:100%; margin:0;">Loans are included when their <strong>borrow date</strong> falls inside this range (both days included).</div>
</form>

<?php if ($range_error !== ''): ?>
    <div class="alert alert-danger"><?= e($range_error) ?></div>
<?php endif; ?>

<?php if (!$has_range): ?>
    <div class="panel"><p class="muted" style="margin:0; text-align:center; padding:18px;">
        No data is shown yet. Pick a start and end date above, then press <strong>Show report</strong>.
    </p></div>

<?php else: ?>

<p class="subtitle" style="margin-bottom:10px;">
    Showing <strong><?= e(format_date($from)) ?></strong> to <strong><?= e(format_date($to)) ?></strong>
</p>

<div class="stat-row" style="margin:10px 0 26px;">
    <div class="stat-card blue"><div class="stat-label">Loan requests</div><div class="stat-value"><?= $total_requests ?></div></div>
    <div class="stat-card blue"><div class="stat-label">Handed over</div><div class="stat-value"><?= $handed_over ?></div></div>
    <div class="stat-card blue"><div class="stat-label">Most borrowed item</div><div class="stat-value text"><?= e($most_borrowed) ?></div></div>
    <div class="stat-card blue"><div class="stat-label">Late returns</div><div class="stat-value"><?= e($late_text) ?></div></div>
    <div class="stat-card blue"><div class="stat-label">Fines (paid / total)</div><div class="stat-value text"><?= e(format_rupiah($fines_paid)) ?> / <?= e(format_rupiah($total_fines)) ?></div></div>
</div>

<!-- ============ THE LOAN LIST + EXCEL DOWNLOAD ============ -->
<div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:10px;">
    <h3 style="margin:0;">Who borrowed what (<?= $total_requests ?>)</h3>
    <a href="<?= e($excel_url) ?>" class="btn btn-primary" style="padding:10px 22px;">Download Excel</a>
</div>
<div class="table-wrapper">
    <table style="min-width:1100px;">
        <thead>
            <tr>
                <th>Loan</th><th>Student</th><th>Student ID</th><th>Items borrowed</th><th>Borrowed</th>
                <th>Due</th><th>Returned</th><th>Fine</th><th>Status</th><th>Detail</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($loans)): ?>
            <tr><td colspan="10" class="muted" style="text-align:center;">No loans in this date range.</td></tr>
        <?php else: foreach ($loans as $l): ?>
            <tr>
                <td>#<?= (int)$l['id'] ?><?php if ($l['item_count'] > 1): ?> <span class="badge borrowed" title="One request with several items">Bulk · <?= (int)$l['item_count'] ?></span><?php endif; ?></td>
                <td><?= e($l['full_name']) ?></td>
                <td><?= e($l['nim'] ?? '-') ?></td>
                <td style="white-space:normal; min-width:200px;"><?= e($l['items'] ?? '') ?></td>
                <td><?= e(format_date($l['borrow_date'])) ?></td>
                <td><?= e(format_date($l['return_date'])) ?></td>
                <td><?= $l['returned_at'] ? e(format_date($l['returned_at'], 'd M Y H:i')) . ($l['was_late'] ? ' (late)' : '') : '<span class="muted">Not returned</span>' ?></td>
                <td><?= $l['fine'] > 0 ? e(report_fine_text($l)) : '<span class="muted">No fine</span>' ?></td>
                <td><?= status_badge($l['display_status'] ?? $l['status']) ?></td>
                <td><a class="link-action" href="admin_loan_detail.php?id=<?= (int)$l['id'] ?>">Detail</a></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<!-- ============ TREND ============ -->
<h3 style="margin:26px 0 10px;">Borrow trend (loan requests per month)</h3>
<div class="chart-box" style="overflow-x:auto;">
    <div class="bar-chart" style="min-width:<?= max(300, count($months) * 56) ?>px;">
        <?php foreach ($months as $m):
            $ratio  = $m['count'] / $max_count;
            $height = round(10 + 65 * $ratio);
            $color  = $ratio >= 0.66 ? 'high' : ($ratio >= 0.33 ? 'mid' : 'low');
        ?>
            <div class="bar-col">
                <div class="bar-value"><?= (int)$m['count'] ?></div>
                <div class="bar <?= $color ?>" style="height:<?= $height ?>%;"></div>
                <div class="bar-label"><?= e($m['label']) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ============ DETAIL CHARTS ============ -->
<div class="chart-grid" style="margin-top:26px;">

    <div class="chart-box">
        <h3 style="margin-bottom:12px;">Borrowing by category</h3>
        <?php if (empty($by_category)): ?><p class="muted">No items handed over in this range.</p><?php endif; ?>
        <?php $cat_max = max(1, max($by_category ?: [1])); ?>
        <?php foreach ($by_category as $cname => $ctotal): ?>
            <div class="hbar-row">
                <span><?= e($cname) ?></span>
                <div class="hbar-track"><div class="hbar-fill" style="width:<?= round(100 * $ctotal / $cat_max) ?>%;"></div></div>
                <span class="hbar-value"><?= (int)$ctotal ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="chart-box">
        <h3 style="margin-bottom:12px;">Requests by status</h3>
        <?php $st_max = max(1, max($by_status ?: [1])); ?>
        <?php foreach ($status_chart as $key => [$label, $color_name]): $n = (int)($by_status[$key] ?? 0); ?>
            <div class="hbar-row">
                <span><?= e($label) ?></span>
                <div class="hbar-track"><div class="hbar-fill <?= e($color_name) ?>" style="width:<?= round(100 * $n / $st_max) ?>%;"></div></div>
                <span class="hbar-value"><?= $n ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="chart-box">
        <h3 style="margin-bottom:12px;">On-time vs late returns</h3>
        <?php if ($returns_total === 0): ?>
            <p class="muted">No returns in this range.</p>
        <?php else: ?>
        <div class="pie-wrap">
            <div class="pie" style="background: conic-gradient(#2DA43C 0 <?= $on_time_pct ?>%, #E02424 <?= $on_time_pct ?>% 100%);"></div>
            <div class="legend">
                <div><i style="background:#2DA43C"></i> On time: <?= $on_time ?> (<?= $on_time_pct ?>%)</div>
                <div><i style="background:#E02424"></i> Late: <?= $late ?> (<?= 100 - $on_time_pct ?>%)</div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="chart-box">
        <h3 style="margin-bottom:12px;">Damage reports &amp; fines per month</h3>
        <?php foreach ($months as $dm): ?>
            <div class="hbar-row" style="grid-template-columns:60px 1fr 130px;">
                <span><?= e($dm['label']) ?></span>
                <div class="hbar-track"><div class="hbar-fill red" style="width:<?= round(100 * $dm['damage'] / $max_damage) ?>%;"></div></div>
                <span class="hbar-value" style="text-align:right;"><?= (int)$dm['damage'] ?> · <?= e(format_rupiah($dm['fines'])) ?></span>
            </div>
        <?php endforeach; ?>
        <p class="field-hint"><a href="damage_reports.php">Open the Damage Reports →</a></p>
    </div>
</div>

<h3 style="margin:26px 0 10px;">Top borrowed items</h3>
<div class="plain-table-box">
    <table>
        <thead><tr><th>Rank</th><th>Item</th><th>Category</th><th>Times borrowed</th></tr></thead>
        <tbody>
            <?php if (empty($top_items)): ?>
                <tr><td colspan="4" class="muted" style="text-align:center;">No items handed over in this range.</td></tr>
            <?php else: foreach ($top_items as $rank => $it): ?>
                <tr>
                    <td><?= $rank + 1 ?></td>
                    <td><?= e($it['name']) ?></td>
                    <td><?= e($it['category']) ?></td>
                    <td><?= (int)$it['times'] ?></td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php endif; ?>

<?php include 'includes/footer.php'; ?>
