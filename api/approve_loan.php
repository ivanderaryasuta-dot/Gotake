<?php
/**
 * api/approve_loan.php  —  APPROVE A LOAN REQUEST
 * ------------------------------------------------------------
 * Called by JavaScript on manage_requests.php (postJSON).
 * Receives  {"id": 12}  and answers  {"success": true}  or  {"success": false, "error": "..."}.
 *
 * It calls the PostgreSQL function approve_loan(loan_id, admin_id),
 * which does the real work (changes the status to 'approved').
 *
 * CHANGED: now only a logged-in ADMIN may call it. Before, anybody who knew
 * the URL could approve loans, and the admin id fell back to 1.
 * ------------------------------------------------------------
 */
require_once '../config/db.php';
require_once '../includes/functions.php';
session_start();

api_require_admin();   // stops with "Unauthorized." if not an admin

$data    = json_decode(file_get_contents('php://input'), true);   // read the JSON the browser sent
$loan_id = $data['id'] ?? null;

if (!$loan_id) json_out(['success' => false, 'error' => 'Invalid Loan ID.']);

try {
    $stmt = $pdo->prepare("SELECT approve_loan(?, ?)");
    $stmt->execute([$loan_id, $_SESSION['user_id']]);
    json_out(['success' => true]);
} catch (Exception $e) {
    json_out(['success' => false, 'error' => $e->getMessage()]);
}
