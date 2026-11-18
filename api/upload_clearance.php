<?php
/**
 * api/upload_clearance.php  —  STUDENT UPLOADS THE SIGNED PERMISSION FORM
 * ------------------------------------------------------------
 * POST (multipart)  loan_id, form (the file).
 * Rules: it must be the student's OWN loan, the admin must have asked for the form,
 * and the items must not be handed over yet. A new upload replaces the old one.
 * ------------------------------------------------------------
 */
require_once '../config/db.php';
require_once '../includes/functions.php';
session_start();

if (($_SESSION['role'] ?? '') !== 'student') json_out(['success' => false, 'error' => 'Unauthorized.']);

$loan_id = (int)($_POST['loan_id'] ?? 0);
if ($loan_id < 1) json_out(['success' => false, 'error' => 'Invalid loan.']);

try {
    $stmt = $pdo->prepare("SELECT status::text AS status FROM loans WHERE id = ? AND user_id = ?");
    $stmt->execute([$loan_id, $_SESSION['user_id']]);
    $status = $stmt->fetchColumn();
    if (!$status) json_out(['success' => false, 'error' => 'Loan not found.']);
    if (!in_array($status, ['requested', 'approved'], true)) {
        json_out(['success' => false, 'error' => 'The form can only be uploaded before the items are picked up.']);
    }
    if (!loan_clearance_items($pdo, $loan_id)) json_out(['success' => false, 'error' => 'This loan does not need a permission form.']);
    if (!loan_clearance_state($loan_id)['requested_at']) {
        json_out(['success' => false, 'error' => 'The admin has not asked for the form yet. You will be notified when it is time.']);
    }
    if (empty($_FILES['form'])) json_out(['success' => false, 'error' => 'No file received.']);

    $saved = save_clearance_file($_FILES['form'], $loan_id);

    $meta = meta_read('loans/' . $loan_id);
    $meta['clearance_file']        = $saved['file'];
    $meta['clearance_original']    = $saved['original'];
    $meta['clearance_uploaded_at'] = date('c');
    meta_write('loans/' . $loan_id, $meta);

    json_out(['success' => true]);
} catch (Exception $e) {
    json_out(['success' => false, 'error' => $e->getMessage()]);
}
