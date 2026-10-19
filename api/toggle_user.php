<?php
/**
 * api/toggle_user.php  —  SUSPEND / ACTIVATE A USER   (NEW)
 * ------------------------------------------------------------
 * Receives {"id": 4, "active": false}.
 * Sets users.is_active. login.php refuses to log in users where
 * is_active is false, so a suspended student can no longer sign in.
 * An admin cannot suspend their own account.
 * ------------------------------------------------------------
 */
require_once '../config/db.php';
require_once '../includes/functions.php';
session_start();
api_require_admin();

$d      = json_decode(file_get_contents('php://input'), true) ?: [];
$id     = (int)($d['id'] ?? 0);
$active = !empty($d['active']);

if ($id < 1)                              json_out(['success' => false, 'error' => 'Invalid user.']);
if ($id === (int)$_SESSION['user_id'])    json_out(['success' => false, 'error' => 'You cannot suspend your own account.']);

try {
    $stmt = $pdo->prepare("UPDATE users SET is_active = ?, updated_at = now() WHERE id = ?");
    $stmt->bindValue(1, $active ? 'true' : 'false');   // PostgreSQL boolean as text
    $stmt->bindValue(2, $id);
    $stmt->execute();
    json_out(['success' => true]);
} catch (Exception $e) {
    json_out(['success' => false, 'error' => $e->getMessage()]);
}
