<?php
/**
 * includes/report_data.php  —  SHARED BY reports.php AND api/export_report.php
 * ------------------------------------------------------------
 * report_parse_range()  checks the ?from=...&to=... dates
 * report_loans()        every loan whose BORROW DATE is inside the range, with
 *                       who borrowed, what, when, fine and return time
 * ------------------------------------------------------------
 */

/**
 * Returns [from, to, error]. Both dates must be real dates (YYYY-MM-DD)
 * and "from" cannot be after "to". error = '' when everything is fine.
 */
function report_parse_range(?string $from, ?string $to): array
{
    $from = trim((string)$from);
    $to   = trim((string)$to);
    if ($from === '' && $to === '') return ['', '', ''];          // nothing chosen yet (not an error)

    $ok = function (string $d): bool {
        $dt = DateTime::createFromFormat('Y-m-d', $d);
        return $dt && $dt->format('Y-m-d') === $d;
    };
    if (!$ok($from) || !$ok($to)) return ['', '', 'Please choose both a start date and an end date.'];
    if ($from > $to)              return ['', '', 'The start date cannot be after the end date.'];
    return [$from, $to, ''];
}

/**
 * One row per loan (a bulk loan is still ONE row; "items" lists everything in it).
 *   fine            = returns.compensation_amount (0 when none)
 *   fine_paid       = true / false (kept in uploads/loans/<id>/meta.json by mark_fine_paid.php)
 *   returned_at     = null until the items are back
 */
function report_loans(PDO $pdo, string $from, string $to): array
{
    $stmt = $pdo->prepare("
        SELECT l.id, l.borrow_date, l.return_date, l.handed_over_at, l.purpose,
               l.status::text AS status,
               u.full_name, u.nim,
               v.display_status,
               (SELECT string_agg(i.name || ' x' || li.qty, ', ' ORDER BY i.name)
                  FROM loan_items li JOIN items i ON i.id = li.item_id
                 WHERE li.loan_id = l.id)                                   AS items,
               (SELECT COUNT(*) FROM loan_items li WHERE li.loan_id = l.id) AS item_count,
               EXISTS (SELECT 1 FROM loan_items li
                        WHERE li.loan_id = l.id AND li.condition_after IS NOT NULL
                          AND li.condition_after::text <> 'good')           AS has_damage,
               r.returned_at, r.was_late,
               COALESCE(r.compensation_amount, 0)                           AS fine
        FROM loans l
        JOIN users u                  ON u.id = l.user_id
        LEFT JOIN v_loan_overview v   ON v.id = l.id
        LEFT JOIN returns r           ON r.loan_id = l.id
        WHERE l.borrow_date BETWEEN ? AND ?
        ORDER BY l.borrow_date DESC, l.id DESC
    ");
    $stmt->execute([$from, $to]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['item_count'] = (int)$row['item_count'];
        $row['fine']       = (float)$row['fine'];
        $row['fine_paid']  = false;
        if ($row['fine'] > 0) {
            $row['fine_paid'] = !empty(meta_read('loans/' . $row['id'])['fine_paid_at']);
        }
    }
    unset($row);
    return $rows;
}

/** Text for the "Fine" column: "No fine", "Rp 150.000 (unpaid)" ... */
function report_fine_text(array $row): string
{
    if ($row['fine'] <= 0) return 'No fine';
    return format_rupiah($row['fine']) . ($row['fine_paid'] ? ' (paid)' : ' (unpaid)');
}
