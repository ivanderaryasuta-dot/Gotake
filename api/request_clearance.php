<?php
/**
 * api/request_clearance.php  —  ADMIN ASKS THE STUDENT FOR THE SIGNED PERMISSION FORM
 * ------------------------------------------------------------
 * Receives {"loan_id": 12}. Allowed while the loan is 'requested' or 'approved' and it
 * contains at least one item marked "needs permission form" in Manage Items.
 * It saves the time of the request and sends the student a notification that says
 * WHERE the permission can be obtained (the text the admin wrote for each item).
 * ------------------------------------------------------------
 */
require_once '../config/db.php';
require_once '../includes/functions.php';
session_start();
api_require_admin();

$d       = json_decode(file_get_contents('php://input'), true) ?: [];
$loan_id = (int)($d['loan_id'] ?? 0);
if ($loan_id < 1) json_out(['success' => false, 'error' => 'Invalid loan.']);

try {
    $stmt = $pdo->prepare("SELECT user_id, status::text AS status FROM loans WHERE id = ?");
    $stmt->execute([$loan_id]);
    $loan = $stmt->fetch();
    if (!$loan) json_out(['success' => false, 'error' => 'Loan not found.']);
    if (!in_array($loan['status'], ['requested', 'approved'], true)) {
        json_out(['success' => false, 'error' => 'A permission form can only be requested before the items are handed over.']);
    }

    $items = loan_clearance_items($pdo, $loan_id);
    if (!$items) json_out(['success' => false, 'error' => 'None of the items in this loan needs a permission form.']);

    $state = loan_clearance_state($loan_id);
    if ($state['requested_at']) json_out(['success' => false, 'error' => 'The permission form was already requested.']);

    $meta = meta_read('loans/' . $loan_id);
    $meta['clearance_requested_at'] = date('c');
    $meta['clearance_requested_by'] = (int)$_SESSION['user_id'];
    meta_write('loans/' . $loan_id, $meta);

    $note = 'Loan #' . $loan_id . ' needs a signed permission form. Where to get it: ' . clearance_where_text($items)
          . '. Upload the signed form on your request page and bring it when you pick up the items.';
    try {
        add_notification($pdo, (int)$loan['user_id'], $loan_id, 'clearance_requested', $note);
    } catch (Exception $e) {
        // If your notifications table only accepts a fixed list of types the student just sees the request on the loan page.
        json_out(['success' => true, 'warning' => 'Requested, but the notification could not be saved: ' . $e->getMessage()]);
    }
    json_out(['success' => true]);
} catch (Exception $e) {
    json_out(['success' => false, 'error' => $e->getMessage()]);
}
