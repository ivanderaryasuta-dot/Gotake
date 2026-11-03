<?php
/**
 * api/item_photo.php  —  REFERENCE PHOTOS OF AN ITEM   (NEW in v3)
 * ------------------------------------------------------------
 * Used by the "Photos" pop-up in manage_items.php. Admin only.
 *
 *   GET  ?action=list&item_id=5                      -> {"success":true,"photos":{"front":"uploads/..."}}
 *   POST action=upload  item_id side photo(file)     -> add a photo, or REPLACE the one on that side
 *   POST action=delete  item_id side                 -> remove the photo of that side
 *
 * NO DATABASE CHANGE: photos are plain files in  uploads/items/<item id>/
 * (see the storage notes in includes/functions.php). The database is only
 * asked "does this item exist?".
 * There is one photo per side. Uploading again REPLACES it (that is the "edit").
 * ------------------------------------------------------------
 */
require_once '../config/db.php';
require_once '../includes/functions.php';
session_start();
api_require_admin();

$action  = $_POST['action'] ?? ($_GET['action'] ?? 'list');
$item_id = (int)($_POST['item_id'] ?? $_GET['item_id'] ?? 0);
if ($item_id < 1) json_out(['success' => false, 'error' => 'Invalid item.']);

try {
    $stmt = $pdo->prepare("SELECT 1 FROM items WHERE id = ?");
    $stmt->execute([$item_id]);
    if (!$stmt->fetch()) json_out(['success' => false, 'error' => 'Item not found.']);

    if ($action === 'list') {
        json_out(['success' => true, 'photos' => (object)item_photos($item_id)]);   // (object) keeps an empty list as {} for JavaScript
    }

    $side = $_POST['side'] ?? '';
    if (!isset(PHOTO_SIDES[$side])) json_out(['success' => false, 'error' => 'Invalid side.']);

    if ($action === 'upload') {
        if (empty($_FILES['photo'])) json_out(['success' => false, 'error' => 'No photo received.']);
        $path = save_photo($_FILES['photo'], 'items/' . $item_id, $side);   // saved first, so a failed upload never loses the old photo
        // remove older files of this side (everything except the file we just saved)
        foreach (glob(__DIR__ . '/../uploads/items/' . $item_id . '/' . $side . '_*') ?: [] as $file) {
            if ('uploads/items/' . $item_id . '/' . basename($file) !== $path) @unlink($file);
        }
        json_out(['success' => true, 'file_path' => $path]);
    }

    if ($action === 'delete') {
        remove_photo_files('items/' . $item_id, $side);
        json_out(['success' => true]);
    }

    json_out(['success' => false, 'error' => 'Unknown action.']);
} catch (Exception $e) {
    json_out(['success' => false, 'error' => $e->getMessage()]);
}
