<?php
/**
 * api/save_item.php  —  ADD OR EDIT AN ITEM   (NEW)
 * ------------------------------------------------------------
 * Receives JSON from manage_items.php:
 *   {id, code, name, category_id, total_qty, location}
 *   - id empty  -> INSERT a new item
 *   - id filled -> UPDATE that item
 * Answers {"success": true} or {"success": false, "error": "..."}.
 *
 * STOCK RULE when editing the total quantity:
 *   The number currently borrowed (total - available) must stay valid.
 *   Example: total 8, available 5 (3 borrowed). Setting total to 10
 *   -> available becomes 7. Setting total to 2 is refused (3 are out).
 * ------------------------------------------------------------
 */
require_once '../config/db.php';
require_once '../includes/functions.php';
session_start();
api_require_admin();

$d = json_decode(file_get_contents('php://input'), true) ?: [];

$id          = (int)($d['id'] ?? 0);
$code        = trim($d['code'] ?? '');
$name        = trim($d['name'] ?? '');
$category_id = (int)($d['category_id'] ?? 0);
$total       = (int)($d['total_qty'] ?? 0);
$location    = trim($d['location'] ?? '');
$jurusan     = trim($d['jurusan'] ?? '');   // department that owns the item ('' = shared by all)
$needs_form  = !empty($d['requires_clearance']);                       // high-clearance item?
$form_where  = mb_substr(trim($d['clearance_contact'] ?? ''), 0, 200); // where to get the permission

if ($code === '' || $name === '') json_out(['success' => false, 'error' => 'Item code and name are required.']);
if ($location === '')             json_out(['success' => false, 'error' => 'Storage location is required.']);
if ($category_id < 1)             json_out(['success' => false, 'error' => 'Please choose a category.']);
if ($total < 1)                   json_out(['success' => false, 'error' => 'Total quantity must be at least 1.']);
if ($needs_form && $form_where === '') json_out(['success' => false, 'error' => 'Please write where students can get the higher permission.']);

try {
    if ($id === 0) {
        // ---- ADD: everything starts in storage, so available = total ----
        $stmt = $pdo->prepare("
            INSERT INTO items (code, name, category_id, total_qty, available_qty, location, is_active)
            VALUES (?, ?, ?, ?, ?, ?, TRUE)
            RETURNING id
        ");
        $stmt->execute([$code, $name, $category_id, $total, $total, $location]);
        $id = (int)$stmt->fetchColumn();   // the new item's id (needed to save its jurusan)
    } else {
        // ---- EDIT ----
        $stmt = $pdo->prepare("SELECT total_qty, available_qty FROM items WHERE id = ?");
        $stmt->execute([$id]);
        $old = $stmt->fetch();
        if (!$old) json_out(['success' => false, 'error' => 'Item not found.']);

        $borrowed = (int)$old['total_qty'] - (int)$old['available_qty'];   // how many are out right now
        if ($total < $borrowed) {
            json_out(['success' => false, 'error' => "Total cannot be lower than the $borrowed item(s) currently borrowed."]);
        }
        $available = $total - $borrowed;

        $stmt = $pdo->prepare("
            UPDATE items
            SET code = ?, name = ?, category_id = ?, total_qty = ?, available_qty = ?, location = ?
            WHERE id = ?
        ");
        $stmt->execute([$code, $name, $category_id, $total, $available, $location, $id]);
    }
    // JURUSAN is kept in uploads/items/<id>/meta.json (no database column needed)
    $meta = meta_read('items/' . $id);
    if ($jurusan !== '') $meta['jurusan'] = $jurusan; else unset($meta['jurusan']);
    if ($needs_form) { $meta['requires_clearance'] = true; $meta['clearance_contact'] = $form_where; }
    else             { unset($meta['requires_clearance'], $meta['clearance_contact']); }
    meta_write('items/' . $id, $meta);

    json_out(['success' => true]);

} catch (PDOException $e) {
    // 23505 = "unique violation" (another item already has this code)
    if ($e->getCode() === '23505') json_out(['success' => false, 'error' => 'That item code is already used.']);
    json_out(['success' => false, 'error' => $e->getMessage()]);
}
