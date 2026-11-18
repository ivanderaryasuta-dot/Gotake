<?php
/**
 * api/export_report.php  —  DOWNLOAD THE LOAN REPORT AS AN EXCEL FILE   (REWRITTEN)
 * ------------------------------------------------------------
 * Linked from the "Download Excel" button on reports.php:
 *     api/export_report.php?from=2026-11-01&to=2026-11-30
 *
 * One row per loan whose borrow date is inside the range:
 *   who borrowed (name + student ID), what they borrowed, when they borrowed it,
 *   when it was due, when it was returned, and whether there is a fine.
 * A loan with several items (a "bulk" loan) is ONE row.
 *
 * It sends a real .xlsx file. If the PHP "zip" extension is switched off
 * (rare) it falls back to a CSV file that Excel can open as well.
 * Admins only.
 * ------------------------------------------------------------
 */
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/xlsx_writer.php';
require_once '../includes/report_data.php';
session_start();

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Unauthorized.');
}

[$from, $to, $range_error] = report_parse_range($_GET['from'] ?? '', $_GET['to'] ?? '');
if ($from === '' || $range_error !== '') {
    http_response_code(400);
    exit($range_error !== '' ? $range_error : 'Please choose a date range first.');
}

$headers = [
    'Loan ID', 'Student', 'Student ID (NIM)', 'Items borrowed', 'Number of items', 'Loan type',
    'Borrow date', 'Handed over at', 'Due date', 'Returned at', 'Returned late?',
    'Fine (Rp)', 'Fine status', 'Status',
];

$rows = [];
foreach (report_loans($pdo, $from, $to) as $l) {
    $rows[] = [
        (int)$l['id'],
        (string)$l['full_name'],
        (string)($l['nim'] ?? ''),
        (string)($l['items'] ?? ''),
        (int)$l['item_count'],
        $l['item_count'] > 1 ? 'Bulk' : 'Single',
        (string)$l['borrow_date'],
        $l['handed_over_at'] ? date('Y-m-d H:i', strtotime($l['handed_over_at'])) : 'Not handed over',
        (string)$l['return_date'],
        $l['returned_at'] ? date('Y-m-d H:i', strtotime($l['returned_at'])) : 'Not returned',
        $l['returned_at'] ? ($l['was_late'] ? 'Yes' : 'No') : '',
        (int)round($l['fine']),
        $l['fine'] > 0 ? ($l['fine_paid'] ? 'Paid' : 'Unpaid') : 'No fine',
        (string)($l['display_status'] ?? $l['status']),
    ];
}

$base = 'gotake_loans_' . $from . '_to_' . $to;

// ---- Excel (.xlsx) ----
if (xlsx_download($base . '.xlsx', 'Loans', $headers, $rows)) exit;

// ---- Fallback: CSV (only when the PHP zip extension is missing) ----
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $base . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");                       // BOM so Excel shows accents correctly
fputcsv($out, $headers);
foreach ($rows as $row) {
    // a cell starting with = + - @ could be run as a formula: put an apostrophe in front
    $row = array_map(function ($v) {
        $v = (string)$v;
        return ($v !== '' && strpos('=+-@', $v[0]) !== false) ? "'" . $v : $v;
    }, $row);
    fputcsv($out, $row);
}
fclose($out);
