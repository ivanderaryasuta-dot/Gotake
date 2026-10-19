<?php
/**
 * api/process_return.php  —  RECORD THAT THE STUDENT RETURNED THE ITEMS
 * ------------------------------------------------------------
 * Receives the "Process return" form (loan_id, condition_after,
 * compensation_amount, notes) and:
 *   1. saves the item condition in loan_items
 *   2. calls the PostgreSQL function return_loan(), which increases the
 *      stock again, checks if it was late and writes the return record.
 * Both steps are inside ONE TRANSACTION: either both succeed or none does.
 *
 * CHANGED: only admins may call it, and the admin id now comes from the
 * login session (before it was hard-coded as 1).
 * ------------------------------------------------------------
 */
require_once '../config/db.php';
require_once '../includes/functions.php';
session_start();

api_require_admin();

$loan_id         = $_POST['loan_id'] ?? null;
$condition_after = $_POST['condition_after'] ?? 'good';
$compensation    = (int)($_POST['compensation_amount'] ?? 0);
$notes           = $_POST['notes'] ?? '';
$admin_id        = $_SESSION['user_id'];

if (!$loan_id) json_out(['success' => false, 'error' => 'Invalid Loan ID']);

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("UPDATE loan_items SET condition_after = ? WHERE loan_id = ?");
    $stmt->execute([$condition_after, $loan_id]);

    $stmt = $pdo->prepare("SELECT return_loan(?, ?, ?, ?)");
    $stmt->execute([$loan_id, $admin_id, $compensation, $notes]);

    $pdo->commit();
    json_out(['success' => true]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_out(['success' => false, 'error' => $e->getMessage()]);
}
