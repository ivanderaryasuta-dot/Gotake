<?php
/**
 * api/save_user.php  —  ADD OR EDIT A USER   (NEW)
 * ------------------------------------------------------------
 * Receives JSON from user_data.php:
 *   {id, role, full_name, identifier, email, password}
 *   - id empty  -> create a new user (password required)
 *   - id filled -> update the user  (password optional)
 *
 * "identifier" is saved in a different column depending on the role:
 *   student -> users.nim        admin -> users.username
 * Admins can also have a staff_id (e.g. ADM-001).
 * Your database requires: email NOT NULL + unique, and
 *   students need a nim, admins need a username (rule chk_user_identity).
 *
 * Passwords are always stored HASHED (hash_new_password()).
 * ------------------------------------------------------------
 */
require_once '../config/db.php';
require_once '../includes/functions.php';
session_start();
api_require_admin();

$d = json_decode(file_get_contents('php://input'), true) ?: [];

$id         = (int)($d['id'] ?? 0);
$role       = $d['role'] ?? 'student';
$full_name  = trim($d['full_name'] ?? '');
$identifier = trim($d['identifier'] ?? '');
$email      = trim($d['email'] ?? '');
$staff_id   = trim($d['staff_id'] ?? '');
$password   = $d['password'] ?? '';

// ---- Validation ----
if (!in_array($role, ['student', 'admin'])) json_out(['success' => false, 'error' => 'Invalid role.']);
if ($full_name === '')                       json_out(['success' => false, 'error' => 'Full name is required.']);
if ($identifier === '')                      json_out(['success' => false, 'error' => ($role === 'admin' ? 'Username' : 'NIM') . ' is required.']);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['success' => false, 'error' => 'A valid email address is required.']);
if ($id === 0 && strlen($password) < 8)      json_out(['success' => false, 'error' => 'Password must be at least 8 characters.']);
if ($id !== 0 && $password !== '' && strlen($password) < 8) json_out(['success' => false, 'error' => 'Password must be at least 8 characters.']);
if ($id === (int)$_SESSION['user_id'] && $role !== 'admin') json_out(['success' => false, 'error' => 'You cannot remove your own admin role.']);

// Which column holds the login name?
$nim      = ($role === 'student') ? $identifier : null;
$username = ($role === 'admin')   ? $identifier : null;
$staff    = ($role === 'admin' && $staff_id !== '') ? $staff_id : null;   // only admins have a staff id

try {
    // ---- Is the login name or e-mail already used by SOMEONE ELSE? ----
    if ($role === 'student') {
        $dup = $pdo->prepare("SELECT 1 FROM users WHERE CAST(nim AS TEXT) = ? AND id <> ?");
    } else {
        $dup = $pdo->prepare("SELECT 1 FROM users WHERE username = ? AND id <> ?");
    }
    $dup->execute([$identifier, $id]);
    if ($dup->fetch()) json_out(['success' => false, 'error' => 'That ' . ($role === 'admin' ? 'username' : 'NIM') . ' is already used.']);

    $dup = $pdo->prepare("SELECT 1 FROM users WHERE LOWER(email) = LOWER(?) AND id <> ?");
    $dup->execute([$email, $id]);
    if ($dup->fetch()) json_out(['success' => false, 'error' => 'That email is already used.']);

    if ($staff !== null) {
        $dup = $pdo->prepare("SELECT 1 FROM users WHERE staff_id = ? AND id <> ?");
        $dup->execute([$staff, $id]);
        if ($dup->fetch()) json_out(['success' => false, 'error' => 'That staff ID is already used.']);
    }

    if ($id === 0) {
        // ---- ADD ----
        $stmt = $pdo->prepare("
            INSERT INTO users (full_name, nim, username, staff_id, email, password_hash, role, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, TRUE)
        ");
        $stmt->execute([$full_name, $nim, $username, $staff, $email, hash_new_password($password), $role]);
    } else {
        // ---- EDIT ----
        $stmt = $pdo->prepare("UPDATE users SET full_name = ?, nim = ?, username = ?, staff_id = ?, email = ?, role = ?, updated_at = now() WHERE id = ?");
        $stmt->execute([$full_name, $nim, $username, $staff, $email, $role, $id]);

        if ($password !== '') {   // only change the password if one was typed
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = now() WHERE id = ?");
            $stmt->execute([hash_new_password($password), $id]);
        }
    }
    json_out(['success' => true]);

} catch (Exception $e) {
    json_out(['success' => false, 'error' => $e->getMessage()]);
}
