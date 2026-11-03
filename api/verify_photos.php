<?php
/**
 * api/verify_photos.php  —  ADMIN VERIFIES THE PHOTOS OF A LOAN   (NEW in v3)
 * ------------------------------------------------------------
 * "Verifikasi foto item untuk laporan": after comparing the pick-up and
 * return photos on admin_loan_detail.php, the admin confirms them. Who and
 * when are saved in uploads/loans/<id>/meta.json (NO database change) and
 * shown in the damage report.
 * Receives {"loan_id": 12}. A loan with no photos at all cannot be verified.
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
    if (empty(loan_photos($loan_id))) json_out(['success' => false, 'error' => 'This loan has no photos to verify.']);

    $meta = meta_read('loans/' . $loan_id);
    $meta['verified_by']   = (int)$_SESSION['user_id'];
    $meta['verified_name'] = $_SESSION['full_name'] ?? '';
    $meta['verified_at']   = date('c');                    // ISO date + time, e.g. 2026-11-03T10:15:00+07:00
    meta_write('loans/' . $loan_id, $meta);

    json_out(['success' => true]);
} catch (Exception $e) {
    json_out(['success' => false, 'error' => $e->getMessage()]);
}
