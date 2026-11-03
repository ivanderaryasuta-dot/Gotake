<?php
/**
 * reports.php  —  REPORTS  (NEW PAGE, "Reports" in PBL.pdf)
 * ------------------------------------------------------------
 * WHAT THIS PAGE SHOWS
 *   - 4 numbers: transactions this month, most borrowed item,
 *     average loan length, overdue rate
 *   - A bar chart: number of loans per month (last 6 months, all students)
 *   - "Top borrowed items": the 5 most borrowed items
 *   - A button that downloads everything as a CSV file (opens in Excel)
 *
 * DETAIL CHARTS (NEW in v3), all calculated from tables you already have
 *   - Click a month bar        -> the loans of that month are listed below the charts
 *   - Borrowing by category    -> horizontal bars
 *   - Request status           -> how many requests are pending / approved / rejected / ...
 *   - On-time vs late returns  -> pie chart (returns.was_late)
 *   - Damage reports and fines per month (returns.compensation_amount, loan_items.condition_after)
 *
 * WHERE THE NUMBERS COME FROM
 *   Your database already has "views" (saved queries) made for this page:
 *     v_report_summary   -> the 3 numbers (transactions, avg return days, overdue rate)
 *     v_report_monthly   -> loans per month (counted when items are HANDED OVER)
 *     v_report_top_items -> items ranked by how often they were borrowed
 *   So this page only READS them; the maths lives in the database.
 * ------------------------------------------------------------
 */
$page_title = 'Reports';
require_once 'includes/header.php';
require_role('admin');

// ---- 1. The 3 summary numbers come straight from the database VIEW v_report_summary
//         (your schema already calculates them, so we just read them).
//           transactions_this_month = loans handed over this month
//           avg_return_days         = average days between hand-over and return
//           overdue_rate_pct        = % of returns that were late (returns.was_late)
$summary = $pdo->query("SELECT * FROM v_report_summary")->fetch() ?: [];

$transactions = (int)($summary['transactions_this_month'] ?? 0);
$avg_text     = isset($summary['avg_return_days'])  ? number_format((float)$summary['avg_return_days'], 1) . ' days' : '—';
$overdue_text = isset($summary['overdue_rate_pct']) ? number_format((float)$summary['overdue_rate_pct'], 1) . '%'   : '—';

// ---- 2. Top borrowed items: VIEW v_report_top_items (already ranked) ----
$top_items = $pdo->query("
    SELECT name, category, times_borrowed AS times
    FROM v_report_top_items
    ORDER BY rank, name
    LIMIT 5
")->fetchAll();
$most_borrowed = $top_items[0]['name'] ?? '—';

// ---- 3. Loans per month, last 6 months: VIEW v_report_monthly ----
$base   = strtotime(date('Y-m-01'));
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $ts = strtotime("-$i months", $base);
    $months[date('Y-m', $ts)] = ['label' => date('M', $ts), 'count' => 0];
}
$stmt = $pdo->prepare("
    SELECT to_char(month, 'YYYY-MM') AS ym, transactions
    FROM v_report_monthly
    WHERE month >= ?
");
$stmt->execute([date('Y-m-01', strtotime('-5 months', $base))]);
foreach ($stmt->fetchAll() as $row) {
    if (isset($months[$row['ym']])) $months[$row['ym']]['count'] = (int)$row['transactions'];
}
$max_count = max(1, max(array_column($months, 'count')));

// ================= DETAIL CHARTS (v3) =================

// ---- A. Borrowing by category (items handed over) ----
$by_category = $pdo->query("
    SELECT c.name, COUNT(*) AS total
    FROM loan_items li
    JOIN loans l      ON l.id = li.loan_id AND l.handed_over_at IS NOT NULL
    JOIN items i      ON i.id = li.item_id
    JOIN categories c ON c.id = i.category_id
    GROUP BY c.name ORDER BY total DESC
")->fetchAll();

// ---- B. Requests by status (status is an enum, so we show it as text) ----
$status_rows = $pdo->query("SELECT status::text AS status, COUNT(*) AS total FROM loans GROUP BY 1")->fetchAll(PDO::FETCH_KEY_PAIR);
$status_chart = [
    'requested' => ['Pending',  'yellow'],
    'approved'  => ['Approved', 'blue'],
    'borrowed'  => ['Borrowed', 'blue'],
    'returned'  => ['Returned', 'green'],
    'rejected'  => ['Rejected', 'red'],
];

// ---- C. On-time vs late returns (pie chart) ----
$r = $pdo->query("SELECT COUNT(*) FILTER (WHERE NOT was_late) AS on_time, COUNT(*) FILTER (WHERE was_late) AS late FROM returns")->fetch();
$on_time = (int)$r['on_time'];
$late    = (int)$r['late'];
$returns_total = $on_time + $late;
$on_time_pct   = $returns_total > 0 ? round(100 * $on_time / $returns_total) : 0;

// ---- D. Damage reports and fines per month (last 6 months) ----
$damage_months = [];
foreach ($months as $ym => $m) $damage_months[$ym] = ['label' => $m['label'], 'count' => 0, 'fines' => 0.0];
$stmt = $pdo->prepare("
    SELECT to_char(r.returned_at, 'YYYY-MM') AS ym, COUNT(*) AS total, COALESCE(SUM(r.compensation_amount), 0) AS fines
    FROM returns r
    WHERE r.returned_at >= ?
      AND (r.compensation_amount > 0
           OR EXISTS (SELECT 1 FROM loan_items li
                       WHERE li.loan_id = r.loan_id AND li.condition_after IS NOT NULL AND li.condition_after <> 'good'))
    GROUP BY 1
");
$stmt->execute([date('Y-m-01', strtotime('-5 months', $base))]);
foreach ($stmt->fetchAll() as $row) {
    if (isset($damage_months[$row['ym']])) {
        $damage_months[$row['ym']]['count'] = (int)$row['total'];
        $damage_months[$row['ym']]['fines'] = (float)$row['fines'];
    }
}
$max_damage = max(1, max(array_column($damage_months, 'count')));

// ---- E. Month detail: ?month=2026-09 lists the loans handed over in that month ----
$sel_month = $_GET['month'] ?? '';
$month_loans = [];
if (preg_match('/^\d{4}-\d{2}$/', $sel_month)) {
    $stmt = $pdo->prepare("
        SELECT v.id, v.full_name, v.nim, v.items, v.borrow_date, v.return_date, v.display_status
        FROM v_loan_overview v
        JOIN loans l ON l.id = v.id
        WHERE to_char(l.handed_over_at, 'YYYY-MM') = ?
        ORDER BY l.handed_over_at DESC
    ");
    $stmt->execute([$sel_month]);
    $month_loans = $stmt->fetchAll();
} else {
    $sel_month = '';
}
?>

<h1>Reports</h1>
<p class="subtitle">Borrowing trends and item usage across campus</p>

<div class="stat-row" style="margin:10px 0 26px;">
    <div class="stat-card blue"><div class="stat-label">Transactions this month</div><div class="stat-value"><?= $transactions ?></div></div>
    <div class="stat-card blue"><div class="stat-label">Most borrowed item</div><div class="stat-value text"><?= e($most_borrowed) ?></div></div>
    <div class="stat-card blue"><div class="stat-label">Avg. return time</div><div class="stat-value"><?= e($avg_text) ?></div></div>
    <div class="stat-card blue"><div class="stat-label">Overdue rate</div><div class="stat-value"><?= e($overdue_text) ?></div></div>
</div>

<h3 style="margin-bottom:10px;">Borrow trend (last 6 months)</h3>
<div class="chart-box">
    <div class="bar-chart">
        <?php foreach ($months as $ym => $m):
            $ratio  = $m['count'] / $max_count;
            $height = round(10 + 65 * $ratio);
            $color  = $ratio >= 0.66 ? 'high' : ($ratio >= 0.33 ? 'mid' : 'low');
        ?>
            <!-- Each bar is a link: click it to see the loans of that month (listed under the charts) -->
            <a class="bar-col <?= $sel_month === $ym ? 'selected' : '' ?>" href="?month=<?= e($ym) ?>#monthDetail" title="Show the loans of <?= e($m['label']) ?>">
                <div class="bar-value"><?= (int)$m['count'] ?></div>
                <div class="bar <?= $color ?>" style="height:<?= $height ?>%;"></div>
                <div class="bar-label"><?= e($m['label']) ?></div>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<p class="field-hint">Click a month to see its loans.</p>

<?php if ($sel_month !== ''): ?>
<div id="monthDetail" style="margin-top:18px;">
    <h3 style="margin-bottom:10px;">Loans handed over in <?= e(date('F Y', strtotime($sel_month . '-01'))) ?> (<?= count($month_loans) ?>)</h3>
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Student</th><th>Student ID</th><th>Items</th><th>Borrow date</th><th>Return date</th><th>Status</th><th>Detail</th></tr></thead>
            <tbody>
            <?php if (empty($month_loans)): ?>
                <tr><td colspan="7" class="muted" style="text-align:center;">No loans in this month.</td></tr>
            <?php else: foreach ($month_loans as $ml): ?>
                <tr>
                    <td><?= e($ml['full_name']) ?></td>
                    <td><?= e($ml['nim'] ?? '-') ?></td>
                    <td><?= e($ml['items'] ?? '') ?></td>
                    <td><?= e(format_date($ml['borrow_date'])) ?></td>
                    <td><?= e(format_date($ml['return_date'])) ?></td>
                    <td><?= status_badge($ml['display_status']) ?></td>
                    <td><a class="link-action" href="admin_loan_detail.php?id=<?= (int)$ml['id'] ?>">Detail</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ============ DETAIL CHARTS ============ -->
<div class="chart-grid" style="margin-top:26px;">

    <div class="chart-box">
        <h3 style="margin-bottom:12px;">Borrowing by category</h3>
        <?php if (empty($by_category)): ?><p class="muted">No data yet.</p><?php endif; ?>
        <?php $cat_max = max(1, max(array_column($by_category, 'total') ?: [1])); ?>
        <?php foreach ($by_category as $c): ?>
            <div class="hbar-row">
                <span><?= e($c['name']) ?></span>
                <div class="hbar-track"><div class="hbar-fill" style="width:<?= round(100 * $c['total'] / $cat_max) ?>%;"></div></div>
                <span class="hbar-value"><?= (int)$c['total'] ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="chart-box">
        <h3 style="margin-bottom:12px;">Requests by status</h3>
        <?php $st_max = max(1, max(array_map('intval', $status_rows ?: [1]))); ?>
        <?php foreach ($status_chart as $key => [$label, $color_name]): $n = (int)($status_rows[$key] ?? 0); ?>
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
            <p class="muted">No returns yet.</p>
        <?php else: ?>
        <div class="pie-wrap">
            <!-- conic-gradient draws the pie: green from 0% to X%, red from X% to 100% -->
            <div class="pie" style="background: conic-gradient(#2DA43C 0 <?= $on_time_pct ?>%, #E02424 <?= $on_time_pct ?>% 100%);"></div>
            <div class="legend">
                <div><i style="background:#2DA43C"></i> On time: <?= $on_time ?> (<?= $on_time_pct ?>%)</div>
                <div><i style="background:#E02424"></i> Late: <?= $late ?> (<?= 100 - $on_time_pct ?>%)</div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="chart-box">
        <h3 style="margin-bottom:12px;">Damage reports &amp; fines (last 6 months)</h3>
        <?php foreach ($damage_months as $dm): ?>
            <div class="hbar-row" style="grid-template-columns:50px 1fr 120px;">
                <span><?= e($dm['label']) ?></span>
                <div class="hbar-track"><div class="hbar-fill red" style="width:<?= round(100 * $dm['count'] / $max_damage) ?>%;"></div></div>
                <span class="hbar-value" style="text-align:right;"><?= (int)$dm['count'] ?> · <?= e(format_rupiah($dm['fines'])) ?></span>
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
                <tr><td colspan="4" class="muted" style="text-align:center;">No data yet.</td></tr>
            <?php else: ?>
                <?php foreach ($top_items as $rank => $it): ?>
                <tr>
                    <td><?= $rank + 1 ?></td>
                    <td><?= e($it['name']) ?></td>
                    <td><?= e($it['category']) ?></td>
                    <td><?= (int)$it['times'] ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div style="margin-top:22px;">
    <!-- A normal link: the browser downloads whatever api/export_report.php sends -->
    <a href="api/export_report.php" class="btn btn-outline" style="padding:11px 22px;">Export report (CSV)</a>
</div>

<?php include 'includes/footer.php'; ?>
