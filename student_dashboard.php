<?php
/**
 * student_dashboard.php  —  STUDENT HOME PAGE  ("Dashboard" in PBL.pdf)
 * ------------------------------------------------------------
 * WHAT THIS PAGE SHOWS
 *   - A blue banner with a welcome message
 *   - 3 yellow stat cards: active borrowings, pending requests,
 *     items returned this semester
 *   - Two shortcut buttons (browse catalog / my borrowings)
 *   - A "Recent activity" table with the student's latest 5 requests
 *
 * WHERE THE DATA COMES FROM
 *   The database VIEW  v_loan_overview  (same as your previous version).
 *   A view is a saved query that already joins loans + users + items,
 *   and adds a calculated column  display_status  (it turns a late
 *   loan into "overdue").
 * ------------------------------------------------------------
 */
$page_title = 'Dashboard';
require_once 'includes/header.php';   // layout + session + $pdo
require_role('student');              // admins are sent to their own dashboard

// Get ALL loans of the logged-in student, newest first.
// The "?" is replaced by the user id (prepared statement = safe from SQL injection).
$stmt = $pdo->prepare("SELECT * FROM v_loan_overview WHERE user_id = ? ORDER BY id DESC");
$stmt->execute([$_SESSION['user_id']]);
$loans = $stmt->fetchAll();

// ---- Count the numbers for the 3 stat cards ----
$active = 0;      // approved or currently borrowed
$pending = 0;     // waiting for admin approval
$returned = 0;    // returned in the last 6 months ("this semester")

$six_months_ago = strtotime('-6 months');
foreach ($loans as $loan) {
    if (in_array($loan['status'], ['approved', 'borrowed'])) $active++;
    if ($loan['status'] === 'requested') $pending++;
    if ($loan['status'] === 'returned' && strtotime($loan['borrow_date']) >= $six_months_ago) $returned++;
}

// Only the first 5 loans go in the "Recent activity" table
$recent = array_slice($loans, 0, 5);
?>

<!-- ============ BLUE HERO BANNER ============ -->
<section class="hero">
    <h1>Dashboard</h1>
    <p class="subtitle">Your borrowing overview at a glance</p>
    <p class="welcome">Welcome back, <?= e($_SESSION['full_name']) ?></p>

    <div class="stat-row">
        <div class="stat-card">
            <div class="stat-label">Active borrowings</div>
            <div class="stat-value"><?= $active ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Pending requests</div>
            <div class="stat-value"><?= $pending ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Returned this semester</div>
            <div class="stat-value"><?= $returned ?></div>
        </div>
    </div>

    <div class="hero-actions">
        <a href="student_catalog.php" class="btn btn-blue">Browse item catalog</a>
        <a href="student_borrowings.php" class="btn btn-primary">View my borrowings</a>
    </div>
</section>

<!-- ============ RECENT ACTIVITY TABLE ============ -->
<h2 class="section-title" style="margin-top:0;">Recent activity</h2>

<div class="table-wrapper">
    <table>
        <thead>
            <tr><th>Item</th><th>Borrow date</th><th>Return date</th><th>Status</th></tr>
        </thead>
        <tbody>
            <?php if (empty($recent)): ?>
                <tr><td colspan="4" style="text-align:center; padding:30px;" class="muted">
                    You have no borrowing history yet. <a href="student_catalog.php">Browse the catalog</a>.
                </td></tr>
            <?php else: ?>
                <?php foreach ($recent as $loan): ?>
                <tr>
                    <td><?= e($loan['items'] ?? 'No items') ?></td>
                    <td><?= e(format_date($loan['borrow_date'])) ?></td>
                    <td><?= e(format_date($loan['return_date'])) ?></td>
                    <td><?= status_badge($loan['display_status']) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include 'includes/footer.php'; ?>
