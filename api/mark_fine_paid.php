<?php
/**
 * api/mark_fine_paid.php  —  THE DAMAGE FINE WAS PAID   (NEW in v3)
 * ------------------------------------------------------------
 * Receives {"loan_id": 12}. Saves the payment time in
 * uploads/loans/<id>/meta.json (NO database change) and tells the student
 * ("fine_paid" notification -> pop-up). The payment itself happens outside
 * the website (cash / transfer at the facilities office).
 * The fine amount is read from returns.compensation_amount (your table).
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
    $stmt = $pdo->prepare("
        SELECT r.compensation_amount, l.user_id
        FROM returns r JOIN loans l ON l.id = r.loan_id
        WHERE r.loan_id = ?
    ");
    $stmt->execute([$loan_id]);
    $row = $stmt->fetch();

    if (!$row)                                   json_out(['success' => false, 'error' => 'No return record found for this loan.']);
    if ((float)$row['compensation_amount'] <= 0) json_out(['success' => false, 'error' => 'This loan has no fine.']);

    $meta = meta_read('loans/' . $loan_id);
    if (!empty($meta['fine_paid_at'])) json_out(['success' => false, 'error' => 'This fine is already marked as paid.']);

    $meta['fine_paid_at'] = date('c');
    $meta['fine_paid_by'] = (int)$_SESSION['user_id'];
    meta_write('loans/' . $loan_id, $meta);

    add_notification($pdo, (int)$row['user_id'], $loan_id, 'fine_paid',
        'Your damage fine of ' . format_rupiah($row['compensation_amount']) . ' was marked as paid. Thank you.');

    json_out(['success' => true]);
} catch (Exception $e) {
    json_out(['success' => false, 'error' => $e->getMessage()]);
}
