<?php
/**
 * api/process_return.php  —  RECORD THAT THE STUDENT RETURNED THE ITEMS
 * ------------------------------------------------------------
 * REWRITTEN in v3. Receives JSON from the "Process return" pop-up in
 * manage_requests.php:
 *
 *   { "loan_id": 12,
 *     "conditions": { "5": "good", "7": "damaged" },   // item id -> condition
 *     "damage_description": "Lens cracked",
 *     "fine": 150000,
 *     "notes": "..." }
 *
 * RULES (checked here on the server, not only in the browser):
 *   1. every item needs its REQUIRED return photos (front/back/left/right)
 *   2. every item needs a condition: good / minor_damage / damaged
 *   3. if ANY item is not "good", a damage description is required
 *   4. the fine (denda kerusakan) is a whole number of Rupiah, 0 or more
 *
 * WHAT IT SAVES  (NO database change: only columns your database already has)
 *   - loan_items.condition_after       per item
 *   - the database function return_loan(): stock +, returns row (late?),
 *       returns.compensation_amount = the fine,
 *       returns.notes (max 255 characters) = "Damage: <description> | Notes: <notes>"
 *   - the FULL damage description (up to 500 characters) in uploads/loans/<id>/meta.json
 *   - notifications for the student ("returned" and, if there is a fine, "damage_fine")
 * ------------------------------------------------------------
 */
require_once '../config/db.php';
require_once '../includes/functions.php';
session_start();

api_require_admin();

$d           = json_decode(file_get_contents('php://input'), true) ?: [];
$loan_id     = (int)($d['loan_id'] ?? 0);
$conditions  = is_array($d['conditions'] ?? null) ? $d['conditions'] : [];
$description = trim($d['damage_description'] ?? '');
$fine        = (int)($d['fine'] ?? 0);
$notes       = trim($d['notes'] ?? '');
$admin_id    = $_SESSION['user_id'];

if ($loan_id < 1) json_out(['success' => false, 'error' => 'Invalid Loan ID']);
if ($fine < 0)    json_out(['success' => false, 'error' => 'The fine cannot be negative.']);

try {
    // The loan must be out right now
    $stmt = $pdo->prepare("SELECT user_id, status FROM loans WHERE id = ?");
    $stmt->execute([$loan_id]);
    $loan = $stmt->fetch();
    if (!$loan)                        json_out(['success' => false, 'error' => 'Loan not found.']);
    if ($loan['status'] !== 'borrowed') json_out(['success' => false, 'error' => 'This loan is not currently borrowed.']);

    // Rule 1: required return photos
    $missing = missing_photo_sides($pdo, $loan_id, 'return');
    if (!empty($missing)) {
        json_out(['success' => false, 'error' => "Required return photos (*) are missing:\n" . implode("\n", $missing)]);
    }

    // Rule 2 + 3: a valid condition for EVERY item of the loan
    $stmt = $pdo->prepare("SELECT li.item_id, i.name FROM loan_items li JOIN items i ON i.id = li.item_id WHERE li.loan_id = ?");
    $stmt->execute([$loan_id]);
    $loan_items = $stmt->fetchAll();

    $any_damage = false;
    $damaged_names = [];
    foreach ($loan_items as $li) {
        $cond = $conditions[$li['item_id']] ?? '';
        if (!in_array($cond, ['good', 'minor_damage', 'damaged'], true)) {
            json_out(['success' => false, 'error' => 'Please choose the condition of "' . $li['name'] . '".']);
        }
        if ($cond !== 'good') { $any_damage = true; $damaged_names[] = $li['name']; }
    }
    if ($any_damage && $description === '') {
        json_out(['success' => false, 'error' => 'Please describe the damage (required when an item is not in good condition).']);
    }
    if (!$any_damage) $description = '';   // nothing damaged -> no description

    // ---- Save everything or nothing ----
    $pdo->beginTransaction();

    $upd = $pdo->prepare("UPDATE loan_items SET condition_after = ? WHERE loan_id = ? AND item_id = ?");
    foreach ($loan_items as $li) {
        $upd->execute([$conditions[$li['item_id']], $loan_id, $li['item_id']]);
    }

    // returns.notes is only 255 characters, so we keep one short line there:  Damage: ... | Notes: ...
    $short = [];
    if ($description !== '') $short[] = 'Damage: ' . $description;
    if ($notes !== '')       $short[] = 'Notes: ' . $notes;

    // Your database function: increases stock, decides "was late", creates the returns row
    $stmt = $pdo->prepare("SELECT return_loan(?, ?, ?, ?)");
    $stmt->execute([$loan_id, $admin_id, $fine, mb_substr(implode(' | ', $short), 0, 255)]);

    // Notifications -> pop-ups for the student
    add_notification($pdo, (int)$loan['user_id'], $loan_id, 'returned', 'Your items were returned. Thank you!');
    if ($fine > 0) {
        add_notification($pdo, (int)$loan['user_id'], $loan_id, 'damage_fine',
            'Damage fine: ' . format_rupiah($fine) . ' for ' . implode(', ', $damaged_names)
            . ($description !== '' ? ' (' . $description . ')' : '') . '. Please settle it with the facilities admin.');
    }

    $pdo->commit();

    // The full text goes next to the photos (done after the commit; the data above is already safe)
    try {
        $meta = meta_read('loans/' . $loan_id);
        $meta['damage_description'] = mb_substr($description, 0, 500);
        $meta['return_notes']       = $notes;
        meta_write('loans/' . $loan_id, $meta);
    } catch (Exception $e) { /* the short copy in returns.notes still exists */ }

    json_out(['success' => true]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_out(['success' => false, 'error' => $e->getMessage()]);
}
