<?php
/**
 * api/clearance_file.php  —  OPEN THE UPLOADED PERMISSION FORM
 * ------------------------------------------------------------
 * GET ?loan_id=12.   Only an admin, or the student who owns that loan, may open it.
 * (The folder itself is closed to the web, so this is the only way to see the file.)
 * ------------------------------------------------------------
 */
require_once '../config/db.php';
require_once '../includes/functions.php';
session_start();

$role    = $_SESSION['role'] ?? '';
$loan_id = (int)($_GET['loan_id'] ?? 0);
if (!in_array($role, ['admin', 'student'], true) || $loan_id < 1) { http_response_code(403); exit('Unauthorized.'); }

$stmt = $pdo->prepare("SELECT user_id FROM loans WHERE id = ?");
$stmt->execute([$loan_id]);
$owner = $stmt->fetchColumn();
if ($owner === false || ($role === 'student' && (int)$owner !== (int)$_SESSION['user_id'])) { http_response_code(403); exit('Unauthorized.'); }

$state = loan_clearance_state($loan_id);
$file  = (string)($state['file'] ?? '');
if (!preg_match('/^form_\d+_[a-f0-9]{8}\.(pdf|jpg|jpeg|png|webp|doc|docx)$/', $file)) { http_response_code(404); exit('No form uploaded.'); }

$path = clearance_dir($loan_id) . '/' . $file;
if (!is_file($path)) { http_response_code(404); exit('File not found.'); }

$mime   = clearance_mime($file);
$inline = in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true);   // Word files are downloaded
$nice   = preg_replace('/[^A-Za-z0-9_\-\. ]/', '_', (string)($state['original'] ?: $file));

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $nice . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
