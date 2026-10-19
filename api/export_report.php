<?php
/**
 * api/export_report.php  —  DOWNLOAD THE LOANS AS A CSV FILE   (NEW)
 * ------------------------------------------------------------
 * Linked from the "Export report (CSV)" button on reports.php.
 * Instead of an HTML page it sends a file (Content-Disposition:
 * attachment) that Excel / Google Sheets can open. Admins only.
 * ------------------------------------------------------------
 */
require_once '../config/db.php';
require_once '../includes/functions.php';
session_start();

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Unauthorized.');
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="gotake_report_' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');        // write straight to the download
fwrite($out, "\xEF\xBB\xBF");             // BOM so Excel shows accents correctly
fputcsv($out, ['Loan ID', 'Student', 'NIM', 'Items', 'Borrow date', 'Return date', 'Collateral', 'Status']);

/**
 * csv_safe()  — protects against "CSV injection": a cell starting with = + - @
 * could be run as a formula by Excel, so we put an apostrophe in front.
 */
function csv_safe($value): string {
    $value = (string)$value;
    return ($value !== '' && strpos('=+-@', $value[0]) !== false) ? "'" . $value : $value;
}

$rows = $pdo->query("SELECT * FROM v_loan_overview ORDER BY id DESC");
foreach ($rows as $loan) {
    fputcsv($out, [
        $loan['id'],
        csv_safe($loan['full_name']),
        csv_safe($loan['nim'] ?? ''),
        csv_safe($loan['items'] ?? ''),
        $loan['borrow_date'],
        $loan['return_date'],
        $loan['collateral'],
        $loan['display_status'],
    ]);
}
fclose($out);
