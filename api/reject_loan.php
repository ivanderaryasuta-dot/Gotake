<?php
/**
 * api/reject_loan.php  —  REJECT A LOAN REQUEST (with a reason)
 * ------------------------------------------------------------
 * Receives {"id": 12, "reason": "Item unavailable"}.
 * Calls the PostgreSQL function reject_loan(), which updates the status,
 * saves the reason and creates a notification for the student.
 * Logic is the same as your previous version.
 * ------------------------------------------------------------
 */
require_once '../config/db.php';
require_once '../includes/functions.php';
session_start();

api_require_admin();

$data     = json_decode(file_get_contents('php://input'), true);
$loan_id  = $data['id'] ?? null;
$reason   = trim($data['reason'] ?? '');
$admin_id = $_SESSION['user_id'];

if (!$loan_id)       json_out(['success' => false, 'error' => 'Invalid Loan ID.']);
if ($reason === '')  json_out(['success' => false, 'error' => 'A rejection reason is required.']);

try {
    $stmt = $pdo->prepare("SELECT reject_loan(?, ?, ?)");
    $stmt->execute([$loan_id, $admin_id, $reason]);
    json_out(['success' => true]);
} catch (Exception $e) {
    $msg = $e->getMessage();
    if (strpos($msg, 'not in requested status') !== false) {
        $msg = "This loan has already been processed by another admin.";
    }
    json_out(['success' => false, 'error' => $msg]);
}
