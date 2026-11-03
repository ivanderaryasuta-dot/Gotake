<?php
/**
 * api/dismiss_pickup.php  —  student pressed "Got it" on the notification pop-up
 * ------------------------------------------------------------
 * Receives {"ids": [12, 13]} (notification ids) and marks them as read so
 * the pop-up does not show them again. (v3: works for EVERY notification
 * type now: approved, picked_up, returned, damage_fine, fine_paid, rejected.)
 * ------------------------------------------------------------
 */
require_once '../config/db.php';
require_once '../includes/functions.php';
session_start();

if (($_SESSION['role'] ?? '') !== 'student') {
    json_out(['success' => false, 'error' => 'Unauthorized.']);
}

$d   = json_decode(file_get_contents('php://input'), true) ?: [];
$ids = array_map('intval', $d['ids'] ?? []);
if (empty($ids)) json_out(['success' => true]);

try {
    $marks = implode(',', array_fill(0, count($ids), '?'));   // ?,?,? : one placeholder per id
    $stmt  = $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = ? AND id IN ($marks)");
    $stmt->execute(array_merge([$_SESSION['user_id']], $ids));
    json_out(['success' => true]);
} catch (Exception $e) {
    json_out(['success' => false, 'error' => $e->getMessage()]);
}
