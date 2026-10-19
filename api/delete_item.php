<?php
/**
 * api/delete_item.php  —  DELETE AN ITEM   (NEW)
 * ------------------------------------------------------------
 * Receives {"id": 5}.
 *
 * It does NOT erase the row. It sets is_active = FALSE ("soft delete").
 * Why? Old loans still point to this item; really deleting it would
 * break your history and reports. Hidden items disappear from the
 * catalog and from Manage Items.
 *
 * An item that is currently borrowed cannot be deleted.
 * ------------------------------------------------------------
 */
require_once '../config/db.php';
require_once '../includes/functions.php';
session_start();
api_require_admin();

$d  = json_decode(file_get_contents('php://input'), true) ?: [];
$id = (int)($d['id'] ?? 0);
if ($id < 1) json_out(['success' => false, 'error' => 'Invalid item.']);

try {
    $stmt = $pdo->prepare("SELECT total_qty, available_qty FROM items WHERE id = ?");
    $stmt->execute([$id]);
    $item = $stmt->fetch();
    if (!$item) json_out(['success' => false, 'error' => 'Item not found.']);

    if ((int)$item['available_qty'] < (int)$item['total_qty']) {
        json_out(['success' => false, 'error' => 'This item is currently borrowed. Wait until it is returned.']);
    }

    $stmt = $pdo->prepare("UPDATE items SET is_active = FALSE WHERE id = ?");
    $stmt->execute([$id]);
    json_out(['success' => true]);
} catch (Exception $e) {
    json_out(['success' => false, 'error' => $e->getMessage()]);
}
