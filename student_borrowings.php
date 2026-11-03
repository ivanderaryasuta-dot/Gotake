<?php
/**
 * student_borrowings.php  —  MY BORROWINGS  (NEW PAGE, "My Borrowings" in PBL.pdf)
 * ------------------------------------------------------------
 * WHAT THIS PAGE SHOWS
 *   The full history of the student's requests, with their status:
 *   Pending -> Approved -> (Borrowed) -> Returned, or Rejected.
 *
 *   A blue message bar appears at the top:
 *     - right after sending a request (a "flash" message from
 *       student_new_request.php), or
 *     - whenever the student still has a pending request.
 *
 * WHERE THE DATA COMES FROM
 *   View  v_loan_overview  (same as the dashboard).
 * ------------------------------------------------------------
 */
$page_title = 'My Borrowings';
require_once 'includes/header.php';
require_role('student');

$stmt = $pdo->prepare("SELECT * FROM v_loan_overview WHERE user_id = ? ORDER BY id DESC");
$stmt->execute([$_SESSION['user_id']]);
$loans = $stmt->fetchAll();

// Is there a flash message (e.g. "Your request ... was submitted")?  If not,
// look for the newest pending request and remind the student about it.
$banner = flash_render();
if ($banner === '') {
    foreach ($loans as $loan) {
        if ($loan['status'] === 'requested') {
            $banner = '<div class="alert alert-info">Your request for ' . e($loan['items'] ?? 'an item')
                    . ' was submitted. Waiting for approval from the facilities admin.</div>';
            break;   // only the newest one
        }
    }
}
?>

<section class="hero" style="padding-bottom:50px;">
    <h1>My Borrowings</h1>
    <p class="subtitle" style="margin-bottom:0;">Track the status and history of your requests</p>
</section>

<?= $banner /* already safe HTML built above */ ?>

<div class="table-wrapper">
    <table>
        <thead>
            <tr><th>Item</th><th>Borrow date</th><th>Return date</th><th>Collateral</th><th>Status</th><th>Detail</th></tr>
        </thead>
        <tbody>
            <?php if (empty($loans)): ?>
                <tr><td colspan="6" style="text-align:center; padding:30px;" class="muted">
                    You have no borrowing history yet. <a href="student_catalog.php">Browse the catalog</a>.
                </td></tr>
            <?php else: ?>
                <?php foreach ($loans as $loan): ?>
                <tr>
                    <td><?= e($loan['items'] ?? 'No items') ?></td>
                    <td><?= e(format_date($loan['borrow_date'])) ?></td>
                    <td><?= e(format_date($loan['return_date'])) ?></td>
                    <td><?= e($loan['collateral']) ?></td>
                    <td><?= status_badge($loan['display_status']) ?></td>
                    <!-- Detail: photos at pick-up and return, damage report and fine (read-only) -->
                    <td><a class="link-action" href="student_loan_detail.php?id=<?= (int)$loan['id'] ?>">Detail</a></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include 'includes/footer.php'; ?>
