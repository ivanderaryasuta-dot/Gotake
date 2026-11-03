<?php
/**
 * api/loan_photos.php  —  EVIDENCE PHOTOS OF A LOAN   (NEW in v3)
 * ------------------------------------------------------------
 * Photos of every item of a loan, taken by the admin:
 *   phase "pickup" = when the item is handed over  (loan status must be 'approved')
 *   phase "return" = when the item comes back      (loan status must be 'borrowed')
 * Once the hand-over / return is done the photos are LOCKED, so the
 * evidence cannot be changed afterwards.
 *
 *   GET  ?action=list&loan_id=12&phase=pickup
 *        -> {"success":true,"status":"approved","items":[{item_id,name,code,qty,photos:{front:"uploads/.."}}]}
 *   POST action=upload loan_id item_id phase side photo(file)   (add or replace)
 *   POST action=delete loan_id item_id phase side
 *
 * NO DATABASE CHANGE: the photos are files in  uploads/loans/<loan id>/
 * The database is only used to read the loan status and its items.
 * ------------------------------------------------------------
 */
require_once '../config/db.php';
require_once '../includes/functions.php';
session_start();
api_require_admin();

$action  = $_POST['action'] ?? ($_GET['action'] ?? 'list');
$loan_id = (int)($_POST['loan_id'] ?? $_GET['loan_id'] ?? 0);
$phase   = $_POST['phase'] ?? ($_GET['phase'] ?? '');

if ($loan_id < 1)                              json_out(['success' => false, 'error' => 'Invalid loan.']);
if (!in_array($phase, ['pickup', 'return']))   json_out(['success' => false, 'error' => 'Invalid phase.']);

try {
    $stmt = $pdo->prepare("SELECT status FROM loans WHERE id = ?");
    $stmt->execute([$loan_id]);
    $status = $stmt->fetchColumn();
    if (!$status) json_out(['success' => false, 'error' => 'Loan not found.']);

    // ---- LIST: items of the loan + their photos for this phase ----
    if ($action === 'list') {
        $stmt = $pdo->prepare("
            SELECT li.item_id, li.qty, i.name, i.code
            FROM loan_items li JOIN items i ON i.id = li.item_id
            WHERE li.loan_id = ? ORDER BY i.name
        ");
        $stmt->execute([$loan_id]);
        $items = $stmt->fetchAll();

        $all = loan_photos($loan_id);
        foreach ($items as &$it) $it['photos'] = (object)($all[$it['item_id']][$phase] ?? []);
        unset($it);

        json_out(['success' => true, 'status' => $status, 'items' => $items]);
    }

    // ---- UPLOAD / DELETE: only while the phase is still open ----
    $open_status = ($phase === 'pickup') ? 'approved' : 'borrowed';
    if ($status !== $open_status) {
        json_out(['success' => false, 'error' => 'Photos are locked: this step is already finished (or not started yet).']);
    }

    $item_id = (int)($_POST['item_id'] ?? 0);
    $side    = $_POST['side'] ?? '';
    if (!isset(PHOTO_SIDES[$side])) json_out(['success' => false, 'error' => 'Invalid side.']);

    // The item must really belong to this loan
    $stmt = $pdo->prepare("SELECT 1 FROM loan_items WHERE loan_id = ? AND item_id = ?");
    $stmt->execute([$loan_id, $item_id]);
    if (!$stmt->fetch()) json_out(['success' => false, 'error' => 'That item is not part of this loan.']);

    $prefix = $phase . '_' . $item_id . '_' . $side;      // e.g. pickup_7_front

    if ($action === 'upload') {
        if (empty($_FILES['photo'])) json_out(['success' => false, 'error' => 'No photo received.']);
        $path = save_photo($_FILES['photo'], 'loans/' . $loan_id, $prefix);
        foreach (glob(__DIR__ . '/../uploads/loans/' . $loan_id . '/' . $prefix . '_*') ?: [] as $file) {
            if ('uploads/loans/' . $loan_id . '/' . basename($file) !== $path) @unlink($file);   // delete the replaced photo
        }
        json_out(['success' => true, 'file_path' => $path]);
    }

    if ($action === 'delete') {
        remove_photo_files('loans/' . $loan_id, $prefix);
        json_out(['success' => true]);
    }

    json_out(['success' => false, 'error' => 'Unknown action.']);
} catch (Exception $e) {
    json_out(['success' => false, 'error' => $e->getMessage()]);
}
