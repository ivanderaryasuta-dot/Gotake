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
