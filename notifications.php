<?php
/**
 * notifications.php  —  MY NOTIFICATIONS  (students)   NEW
 * ------------------------------------------------------------
 * The full history of the pop-up notifications, kept on its own page.
 * Same information as the pop-up (see includes/header.php), PLUS:
 *
 *   - grouped by DAY  (Today / Yesterday / 09 Nov 2026 ...), newest first
 *   - FILTER by the state of the loan:  All, Ready for pick-up, Picked up,
 *                                       Returned, Rejected, Damage fine, Fine paid
 *   - FILTER by one DAY (date picker)
 *   - every notification of a loan shows the same details as My Borrowings
 *     (items, borrow date, return date, collateral, status, Detail link);
 *     returned loans also show when they were returned and the fine.
 *
 * Opening this page marks the notifications as read (so the pop-up stops
 * showing them); they stay on this page.
 *
 * DATABASE: reads  notifications + v_loan_overview + loans + returns.
 *   Needs a notifications.created_at column. If your table has none, run
 *   database/add_notification_date.sql once (see that file).
 * ------------------------------------------------------------
 */
$page_title  = 'Notifications';
$hide_popup  = true;                     // this page IS the list, so no pop-up on top of it
require_once 'includes/header.php';
require_role('student');

$types = [
    'all'         => 'All',
    'approved'    => 'Ready for pick-up',
    'picked_up'   => 'Picked up',
    'returned'    => 'Returned',
    'rejected'    => 'Rejected',
    'damage_fine' => 'Damage fine',
    'fine_paid'   => 'Fine paid',
    'clearance_requested' => 'Permission form',
];
$titles = [
    'approved'    => 'Ready for pick-up!',
    'rejected'    => 'Request rejected',
    'picked_up'   => 'Item picked up',
    'returned'    => 'Item returned',
    'damage_fine' => 'Damage fine',
    'fine_paid'   => 'Fine paid',
    'clearance_requested' => 'Permission form needed',
];

$type = $_GET['type'] ?? 'all';
if (!isset($types[$type])) $type = 'all';

$day = $_GET['day'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) $day = '';

$sql = "
    SELECT n.*,
           l.handed_over_at,
           v.items, v.borrow_date, v.return_date, v.collateral, v.display_status,
           (SELECT string_agg(DISTINCT i.location, ', ')
              FROM loan_items li JOIN items i ON i.id = li.item_id WHERE li.loan_id = n.loan_id) AS location,
           r.returned_at, r.was_late, r.compensation_amount
    FROM notifications n
    LEFT JOIN loans l          ON l.id = n.loan_id
    LEFT JOIN v_loan_overview v ON v.id = n.loan_id
    LEFT JOIN returns r        ON r.loan_id = n.loan_id
    WHERE n.user_id = ?";
$args = [$_SESSION['user_id']];
if ($type !== 'all') { $sql .= " AND n.type = ?"; $args[] = $type; }
$sql .= " ORDER BY n.id DESC LIMIT 300";

$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$rows = $stmt->fetchAll();

// ---- When did it happen?  created_at if the table has it, otherwise the best date we know ----
function notif_time(array $n): ?int
{
    foreach (['created_at'] as $k) if (!empty($n[$k])) return strtotime($n[$k]);
    if ($n['type'] === 'returned' || $n['type'] === 'damage_fine' || $n['type'] === 'fine_paid') {
        if (!empty($n['returned_at'])) return strtotime($n['returned_at']);
    }
    if ($n['type'] === 'picked_up' && !empty($n['handed_over_at'])) return strtotime($n['handed_over_at']);
    return !empty($n['borrow_date']) ? strtotime($n['borrow_date']) : null;
}

// ---- Day filter + grouping by day ----
$groups = [];
$new_ids = [];
foreach ($rows as $n) {
    $ts  = notif_time($n);
    $key = $ts ? date('Y-m-d', $ts) : 'unknown';
    if ($day !== '' && $key !== $day) continue;
    $n['_ts'] = $ts;
    $groups[$key][] = $n;
    if (empty($n['is_read']) || $n['is_read'] === 'f') $new_ids[] = (int)$n['id'];
}
krsort($groups);                          // newest day first

function day_label(string $key): string
{
    if ($key === 'unknown') return 'Earlier';
    if ($key === date('Y-m-d'))                         return 'Today';
    if ($key === date('Y-m-d', strtotime('yesterday'))) return 'Yesterday';
    return date('d M Y', strtotime($key));
}

$total = 0;
foreach ($groups as $g) $total += count($g);

// ---- Opening the page = seen: mark them as read (they stay in the list) ----
if ($new_ids) {
    try {
        $in = implode(',', array_fill(0, count($new_ids), '?'));
        $upd = $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = ? AND id IN ($in)");
        $upd->execute(array_merge([$_SESSION['user_id']], $new_ids));
    } catch (Exception $e) { /* never break the page for this */ }
}

function notif_link(string $type, string $day): string
{
    $q = [];
    if ($type !== 'all') $q['type'] = $type;
    if ($day !== '')     $q['day']  = $day;
    return 'notifications.php' . ($q ? '?' . http_build_query($q) : '');
}
?>

<section class="hero" style="padding-bottom:50px;">
    <h1>Notifications</h1>
    <p class="subtitle" style="margin-bottom:0;">Everything about your requests, day by day</p>
</section>

<!-- Filter by the state of the loan -->
<div class="tabs">
    <?php foreach ($types as $key => $label): ?>
        <a class="tab <?= $type === $key ? 'active' : '' ?>" href="<?= e(notif_link($key, $day)) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<!-- Filter by one day -->
<form method="GET" class="notif-filters">
    <?php if ($type !== 'all'): ?><input type="hidden" name="type" value="<?= e($type) ?>"><?php endif; ?>
    <label for="day" style="font-weight:700; font-size:0.85rem;">Day</label>
    <input type="date" id="day" name="day" class="pill-input" value="<?= e($day) ?>" max="<?= e(date('Y-m-d')) ?>" onchange="this.form.submit()">
    <?php if ($day !== ''): ?>
        <a href="<?= e(notif_link($type, '')) ?>" class="tab" style="background:#fff;">All days</a>
    <?php endif; ?>
    <span class="muted" style="font-size:0.8rem;"><?= (int)$total ?> notification<?= $total === 1 ? '' : 's' ?></span>
</form>

<?php if (!$groups): ?>
    <div class="panel"><p class="muted" style="margin:0; text-align:center; padding:16px;">
        No notifications<?= ($type !== 'all' || $day !== '') ? ' for this filter' : ' yet' ?>.
        <a href="student_borrowings.php">Go to My Borrowings</a>.
    </p></div>
<?php endif; ?>

<?php foreach ($groups as $key => $list): ?>
    <div class="notif-day"><?= e(day_label($key)) ?>
        <?php if ($key !== 'unknown' && day_label($key) !== date('d M Y', strtotime($key))): ?>
            <small><?= e(date('d M Y', strtotime($key))) ?></small>
        <?php endif; ?>
    </div>

    <?php foreach ($list as $n):
        $is_new   = in_array((int)$n['id'], $new_ids, true);
        $fine     = !empty($n['returned_at']) ? (float)$n['compensation_amount'] : 0.0;
        $future   = !empty($n['borrow_date']) && strtotime($n['borrow_date']) > strtotime('today');
    ?>
    <div class="notif-card <?= e($n['type']) ?> <?= $is_new ? 'unread' : '' ?>">
        <div class="notif-head">
            <strong><?= e($titles[$n['type']] ?? 'Notification') ?><?php if ($is_new): ?><span class="notif-new">NEW</span><?php endif; ?></strong>
            <span class="notif-time"><?= $n['_ts'] ? e(date('H:i', $n['_ts'])) : '' ?></span>
        </div>

        <!-- Same text as the pop-up -->
        <p class="notif-msg">
            <?php if ($n['type'] === 'approved'): ?>
                <?= e($n['items']) ?><br>
                Pick up at: <?= e($n['location']) ?><br>
                <?= $future ? 'Available from: ' : 'Pick up now. Borrow date: ' ?><?= e(format_date($n['borrow_date'])) ?><br>
                Return by: <?= e(format_date($n['return_date'])) ?><br>
                Bring your <?= e($n['collateral']) ?>; the staff will hold it until you return the item.
            <?php else: ?>
                <?= e($n['message']) ?>
            <?php endif; ?>
        </p>

        <?php if (!empty($n['loan_id']) && !empty($n['items'])): ?>
        <!-- Same details as My Borrowings -->
        <div class="notif-detail table-wrapper">
            <table>
                <thead>
                    <tr><th>Item</th><th>Borrow date</th><th>Return date</th><th>Collateral</th><th>Status</th><th>Detail</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?= e($n['items']) ?></td>
                        <td><?= e(format_date($n['borrow_date'])) ?></td>
                        <td><?= e(format_date($n['return_date'])) ?></td>
                        <td><?= e($n['collateral']) ?></td>
                        <td><?= status_badge($n['display_status']) ?></td>
                        <td><a class="link-action" href="student_loan_detail.php?id=<?= (int)$n['loan_id'] ?>">Detail</a></td>
                    </tr>
                </tbody>
            </table>

            <?php if (!empty($n['returned_at'])): ?>
                <p class="muted notif-returned">
                    Returned <?= e(format_date($n['returned_at'], 'd M Y H:i')) ?> — <?= $n['was_late'] ? 'late' : 'on time' ?>
                    <?php if ($fine > 0): ?> · Damage fine <?= e(format_rupiah($fine)) ?><?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
<?php endforeach; ?>

<?php include 'includes/footer.php'; ?>
