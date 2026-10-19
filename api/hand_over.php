<?php
/**
 * api/hand_over.php  —  HAND THE ITEMS TO THE STUDENT
 * ------------------------------------------------------------
 * Receives {"id": 12}. Moves an 'approved' loan to 'borrowed' and
 * DECREASES the physical stock (done inside the PostgreSQL function
 * hand_over_loan()).
 *
 * BEFORE calling it we double-check ("pre-flight check"):
 *   Step 1  the loan exists and is 'approved'
 *   Step 2  for every item: is that quantity PHYSICALLY in storage right now?
 *   Step 3  if all is fine -> call hand_over_loan()
 *
 * CHANGED FROM YOUR PREVIOUS VERSION (a bug):
 *   The old check also asked item_available_on() about the loan dates.
 *   But an APPROVED loan is already counted by that function, so the loan
 *   was counting against itself. Example: 8 projectors, student asks for 5
 *   -> function says 8 - 5 = 3 free -> "not enough stock" (wrong!).
 *   The dates were already checked when the loan was approved
 *   (approve_loan() re-checks them), so here only the physical stock matters.
 * ------------------------------------------------------------
 */
require_once '../config/db.php';
require_once '../includes/functions.php';
session_start();

api_require_admin();

$data    = json_decode(file_get_contents('php://input'), true);
$loan_id = $data['id'] ?? null;

if (!$loan_id) json_out(['success' => false, 'error' => 'Invalid Loan ID.']);

try {
    // ---- Step 1 ----
    $stmt = $pdo->prepare("SELECT id, borrow_date, return_date, status FROM loans WHERE id = ?");
    $stmt->execute([$loan_id]);
    $loan = $stmt->fetch();

    if (!$loan) json_out(['success' => false, 'error' => 'Loan not found.']);
    if ($loan['status'] !== 'approved') {
        json_out(['success' => false, 'error' => "This loan is not in 'approved' status. It may have already been handed over or rejected."]);
    }

    // ---- Step 2: is every item physically in storage? ----
    $items = $pdo->prepare("
        SELECT li.qty, i.name, i.code, i.available_qty
        FROM loan_items li
        JOIN items i ON i.id = li.item_id
        WHERE li.loan_id = ?
    ");
    $items->execute([$loan_id]);

    $problems = [];
    foreach ($items->fetchAll() as $row) {
        if ((int)$row['available_qty'] < (int)$row['qty']) {
            $problems[] = sprintf(
                '%s [%s] — Physically in storage: %d. Requested: %d.',
                $row['name'], $row['code'], $row['available_qty'], $row['qty']
            );
        }
    }
    if (!empty($problems)) {
        json_out([
            'success' => false,
            'error'   => "Cannot hand over — not enough items in storage:\n\n" . implode("\n", $problems)
        ]);
    }

    // ---- Step 3: do it ----
    $stmt = $pdo->prepare("SELECT hand_over_loan(?)");
    $stmt->execute([$loan_id]);
    json_out(['success' => true]);

} catch (Exception $e) {
    $msg = $e->getMessage();
    if (strpos($msg, 'chk_item_qty') !== false || strpos($msg, 'check constraint') !== false) {
        $msg = "Cannot hand over: Physical stock changed between check and hand-over. Please refresh and try again.";
    } elseif (strpos($msg, 'not in approved status') !== false) {
        $msg = "This loan is not in 'approved' status.";
    }
    json_out(['success' => false, 'error' => $msg]);
}
