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

if ($code === '' || $name === '') json_out(['success' => false, 'error' => 'Item code and name are required.']);
if ($location === '')             json_out(['success' => false, 'error' => 'Storage location is required.']);
if ($category_id < 1)             json_out(['success' => false, 'error' => 'Please choose a category.']);
if ($total < 1)                   json_out(['success' => false, 'error' => 'Total quantity must be at least 1.']);

try {
    if ($id === 0) {
        // ---- ADD: everything starts in storage, so available = total ----
        $stmt = $pdo->prepare("
            INSERT INTO items (code, name, category_id, total_qty, available_qty, location, is_active)
            VALUES (?, ?, ?, ?, ?, ?, TRUE)
        ");
        $stmt->execute([$code, $name, $category_id, $total, $total, $location]);
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
    json_out(['success' => true]);

} catch (PDOException $e) {
    // 23505 = "unique violation" (another item already has this code)
    if ($e->getCode() === '23505') json_out(['success' => false, 'error' => 'That item code is already used.']);
    json_out(['success' => false, 'error' => $e->getMessage()]);
}
