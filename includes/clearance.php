<?php
/**
 * includes/clearance.php  —  "HIGH CLEARANCE" ITEMS AND THE SIGNED PERMISSION FORM
 * ------------------------------------------------------------
 * THE IDEA
 *   Some items need permission from someone higher up (head of lab, dean ...).
 *   1. The admin marks such an item in Manage Items (checkbox) and writes WHERE the
 *      student can get the permission (office / person / room).
 *   2. After a student requests it, the admin presses "Ask for permission form"
 *      on the loan detail page. The student is notified and sees where to go.
 *   3. The student uploads the signed form (PDF, photo, Word ... anything the admin can check).
 *   4. The admin can NOT hand the items over until the form has been uploaded.
 *      (the student should still bring the original when picking up)
 *
 * WHERE THINGS ARE STORED  (NO database change, same idea as the photos)
 *   item setting : uploads/items/<item id>/meta.json   requires_clearance, clearance_contact
 *   loan state   : uploads/loans/<loan id>/meta.json   clearance_requested_at, clearance_file, ...
 *   the form     : private_uploads/clearance/<loan id>/   (closed to the web by .htaccess;
 *                  shown only through api/clearance_file.php to the admin and the owner)
 * ------------------------------------------------------------
 */

const CLEARANCE_MAX_BYTES = 10 * 1024 * 1024;                              // 10 MB
const CLEARANCE_TYPES     = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx'];

/** Does this item need a signed permission form, and where can the student get it? */
function item_clearance(int $item_id): array
{
    $m = meta_read('items/' . $item_id);
    return ['required' => !empty($m['requires_clearance']), 'contact' => (string)($m['clearance_contact'] ?? '')];
}

/** The items of a loan that need a permission form: [['item_id', 'name', 'contact'], ...] */
function loan_clearance_items(PDO $pdo, int $loan_id): array
{
    $stmt = $pdo->prepare("SELECT li.item_id, i.name FROM loan_items li JOIN items i ON i.id = li.item_id WHERE li.loan_id = ? ORDER BY i.name");
    $stmt->execute([$loan_id]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $c = item_clearance((int)$row['item_id']);
        if ($c['required']) $out[] = ['item_id' => (int)$row['item_id'], 'name' => $row['name'], 'contact' => $c['contact']];
    }
    return $out;
}

/** What has happened so far with the form of this loan */
function loan_clearance_state(int $loan_id): array
{
    $m = meta_read('loans/' . $loan_id);
    return [
        'requested_at' => $m['clearance_requested_at'] ?? null,   // when the admin asked
        'file'         => $m['clearance_file'] ?? null,           // stored file name (null = nothing uploaded)
        'original'     => $m['clearance_original'] ?? null,       // the name the student gave it
        'uploaded_at'  => $m['clearance_uploaded_at'] ?? null,
    ];
}

function clearance_dir(int $loan_id): string
{
    return __DIR__ . '/../private_uploads/clearance/' . $loan_id;
}

/** "Head of Lab: Room B-204" lines for the notification / pages */
function clearance_where_text(array $items): string
{
    $lines = [];
    foreach ($items as $it) {
        $lines[] = $it['name'] . ': ' . ($it['contact'] !== '' ? $it['contact'] : 'ask the facilities admin');
    }
    return implode('; ', $lines);
}

/**
 * save_clearance_file()  —  stores the uploaded form safely. Returns ['file' => ..., 'original' => ...].
 * Checks: upload worked, up to 10 MB, allowed type (PDF, JPG, PNG, WEBP, DOC, DOCX) AND the file
 * content really is that type (not just the name). The stored name is made by us.
 * A new upload REPLACES the old one. Throws an Exception with a readable message on any problem.
 */
function save_clearance_file(array $file, int $loan_id): array
{
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new Exception('The file is bigger than the server limit. Use a smaller file (maximum 10 MB).');
    }
    if ($error !== UPLOAD_ERR_OK) throw new Exception('Please choose the permission form file first.');
    if ($file['size'] > CLEARANCE_MAX_BYTES) throw new Exception('The file must be smaller than 10 MB.');

    $original = (string)($file['name'] ?? 'form');
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!in_array($ext, CLEARANCE_TYPES, true)) {
        throw new Exception('Allowed file types: PDF, JPG, PNG, WEBP, DOC, DOCX.');
    }

    // The first bytes of a file tell what it really is
    $head = (string)file_get_contents($file['tmp_name'], false, null, 0, 12);
    $ok = false;
    if ($ext === 'pdf')                          $ok = strncmp($head, '%PDF', 4) === 0;
    elseif ($ext === 'jpg' || $ext === 'jpeg')   $ok = strncmp($head, "\xFF\xD8\xFF", 3) === 0;
    elseif ($ext === 'png')                      $ok = strncmp($head, "\x89PNG", 4) === 0;
    elseif ($ext === 'webp')                     $ok = strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP';
    elseif ($ext === 'docx')                     $ok = strncmp($head, "PK\x03\x04", 4) === 0;
    elseif ($ext === 'doc')                      $ok = strncmp($head, "\xD0\xCF\x11\xE0", 4) === 0;
    if (!$ok) throw new Exception('That file does not look like a real ' . strtoupper($ext) . ' file.');

    $dir = clearance_dir($loan_id);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new Exception('Could not create the folder for the form.');

    // Make sure the folder is closed to the web even if the shipped .htaccess was lost
    $guard = __DIR__ . '/../private_uploads/.htaccess';
    if (!is_file($guard)) @file_put_contents($guard, "Require all denied\nOrder allow,deny\nDeny from all\n");

    $name = 'form_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) throw new Exception('Could not save the file. Is the folder writable?');

    foreach (glob($dir . '/form_*') ?: [] as $old) {       // remove the replaced form
        if (basename($old) !== $name) @unlink($old);
    }

    $original = preg_replace('/[\x00-\x1F\x7F]/', '', basename($original));
    return ['file' => $name, 'original' => mb_substr($original, 0, 100)];
}

/** Content-Type for sending a stored form back to the browser */
function clearance_mime(string $file): string
{
    $map = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png'  => 'image/png',  'webp' => 'image/webp',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
    return $map[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
}
