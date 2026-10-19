<?php
/**
 * student_activity.php  —  MY ACTIVITY  (NEW PAGE, "My Activity" in PBL.pdf)
 * ------------------------------------------------------------
 * WHAT THIS PAGE SHOWS
 *   Personal statistics of the student:
 *     - Total borrowed in the last 6 months ("semester")
 *     - On-time return percentage
 *     - Favourite category (the category borrowed most often)
 *     - A bar chart of the number of loans per month (last 6 months)
 *
 * HOW THE CHART WORKS
 *   No chart library is needed. PHP counts loans per month, then prints
 *   one <div class="bar"> per month whose HEIGHT is set in percent.
 *   The CSS colours it: green = busiest months, yellow = medium, red = quiet.
 *
 * HOW "BORROWED" AND "LATE" ARE DECIDED (from your database design)
 *   - A loan counts as borrowed once the items were handed over
 *     (loans.handed_over_at is filled in). Same rule as the admin reports.
 *   - Late or not is stored in the `returns` table (returns.was_late).
 * ------------------------------------------------------------
 */
$page_title = 'My Activity';
require_once 'includes/header.php';
require_role('student');

$uid = $_SESSION['user_id'];

// ---- 1. Build the list of the last 6 months:  ['2026-04' => ['label' => 'Apr', 'count' => 0], ...]
$base   = strtotime(date('Y-m-01'));                 // first day of the current month
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $ts = strtotime("-$i months", $base);
    $months[date('Y-m', $ts)] = ['label' => date('M', $ts), 'count' => 0];
}
$since = date('Y-m-01', strtotime('-5 months', $base));   // the oldest month we show

// ---- 2. Count loans per month for this student
$stmt = $pdo->prepare("
    SELECT to_char(handed_over_at, 'YYYY-MM') AS ym, COUNT(*) AS total
    FROM loans
    WHERE user_id = ? AND handed_over_at IS NOT NULL AND handed_over_at >= ?
    GROUP BY 1
");
$stmt->execute([$uid, $since]);
foreach ($stmt->fetchAll() as $row) {
    if (isset($months[$row['ym']])) $months[$row['ym']]['count'] = (int)$row['total'];
}

$total_borrowed = array_sum(array_column($months, 'count'));
$max_count      = max(1, max(array_column($months, 'count')));   // avoid dividing by zero

// ---- 3. On-time return rate
$stmt = $pdo->prepare("
    SELECT COUNT(*) FILTER (WHERE NOT r.was_late) AS on_time,
           COUNT(*)                               AS total
    FROM returns r
    JOIN loans l ON l.id = r.loan_id
    WHERE l.user_id = ?
");
$stmt->execute([$uid]);
$r = $stmt->fetch();
$on_time_text = ((int)$r['total'] > 0) ? round(100 * $r['on_time'] / $r['total']) . '%' : '—';

// ---- 4. Favourite category (most borrowed)
$stmt = $pdo->prepare("
    SELECT c.name, COUNT(*) AS times
    FROM loans l
    JOIN loan_items li ON li.loan_id = l.id
    JOIN items i       ON i.id = li.item_id
    JOIN categories c  ON c.id = i.category_id
    WHERE l.user_id = ? AND l.handed_over_at IS NOT NULL
    GROUP BY c.name
    ORDER BY times DESC
    LIMIT 1
");
$stmt->execute([$uid]);
$favorite = $stmt->fetchColumn() ?: '—';
?>

<section class="hero">
    <h1>My Activity</h1>
    <p class="subtitle">Your personal borrowing statistics</p>

    <div class="stat-row">
        <div class="stat-card">
            <div class="stat-label">Total borrowed (semester)</div>
            <div class="stat-value"><?= (int)$total_borrowed ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">On-time returns</div>
            <div class="stat-value"><?= e($on_time_text) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Favorite category</div>
            <div class="stat-value text"><?= e($favorite) ?></div>
        </div>
    </div>
</section>

<h2 class="section-title" style="margin-top:0;">Borrowing activity — last 6 months</h2>

<div class="chart-box">
    <div class="bar-chart">
        <?php foreach ($months as $m):
            $ratio = $m['count'] / $max_count;                       // 0.0 ... 1.0
            $height = round(10 + 65 * $ratio);                       // bar height in %, never 0
            $color = $ratio >= 0.66 ? 'high' : ($ratio >= 0.33 ? 'mid' : 'low');
        ?>
            <div class="bar-col">
                <div class="bar-value"><?= (int)$m['count'] ?></div>
                <div class="bar <?= $color ?>" style="height:<?= $height ?>%;" title="<?= e($m['label']) ?>: <?= (int)$m['count'] ?> loans"></div>
                <div class="bar-label"><?= e($m['label']) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
