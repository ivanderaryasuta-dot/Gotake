<?php
/**
 * includes/xlsx_writer.php  —  MAKE A REAL .xlsx FILE WITHOUT ANY LIBRARY
 * ------------------------------------------------------------
 * An .xlsx file is just a ZIP of small XML files. PHP's built-in
 * ZipArchive class (enabled by default in XAMPP) can build one, so
 * the project needs no Composer / PhpSpreadsheet.
 *
 *   xlsx_available()                       -> true if ZipArchive exists
 *   xlsx_download($file, $sheet, $headers, $rows)   -> sends the file to the browser
 *
 * Every cell is written as text or as a number. Text cells are never
 * evaluated as formulas, so "=1+1" typed by a student stays plain text.
 * ------------------------------------------------------------
 */

function xlsx_available(): bool
{
    return class_exists('ZipArchive');
}

/** Makes a string safe to put inside XML (removes control characters too). */
function xlsx_xml(string $s): string
{
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** 0 -> A, 1 -> B, ... 26 -> AA */
function xlsx_col(int $index): string
{
    $name = '';
    for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
        $name = chr(65 + ($n - 1) % 26) . $name;
    }
    return $name;
}

/**
 * xlsx_download()  —  builds the workbook and sends it as a download.
 *   $filename  e.g. 'gotake_loans_2026-11-01_to_2026-11-30.xlsx'
 *   $sheet     the tab name (max 31 characters)
 *   $headers   ['Loan ID', 'Student', ...]
 *   $rows      [[12, 'Ana', ...], ...]   (ints/floats become numbers, everything else text)
 * Returns false (and sends nothing) if the file could not be built.
 */
function xlsx_download(string $filename, string $sheet, array $headers, array $rows): bool
{
    if (!xlsx_available()) return false;

    // ---- the sheet: header row (style 1 = bold + grey) then the data rows ----
    $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    $xml .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';

    // column widths: as wide as the longest text (between 10 and 60 characters)
    $widths = [];
    foreach ($headers as $c => $h) $widths[$c] = mb_strlen((string)$h);
    foreach ($rows as $row) {
        foreach (array_values($row) as $c => $v) {
            $widths[$c] = max($widths[$c] ?? 0, min(60, mb_strlen((string)$v)));
        }
    }
    $xml .= '<cols>';
    foreach ($widths as $c => $w) {
        $xml .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . max(10, $w + 2) . '" customWidth="1"/>';
    }
    $xml .= '</cols><sheetData>';

    $xml .= '<row r="1">';
    foreach (array_values($headers) as $c => $h) {
        $xml .= '<c r="' . xlsx_col($c) . '1" s="1" t="inlineStr"><is><t>' . xlsx_xml((string)$h) . '</t></is></c>';
    }
    $xml .= '</row>';

    $r = 2;
    foreach ($rows as $row) {
        $xml .= '<row r="' . $r . '">';
        foreach (array_values($row) as $c => $v) {
            $ref = xlsx_col($c) . $r;
            if (is_int($v) || is_float($v)) {
                $xml .= '<c r="' . $ref . '"><v>' . $v . '</v></c>';
            } else {
                $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . xlsx_xml((string)$v) . '</t></is></c>';
            }
        }
        $xml .= '</row>';
        $r++;
    }
    $xml .= '</sheetData></worksheet>';

    // ---- the other small files every .xlsx needs ----
    $sheet = preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $sheet);
    $sheet = mb_substr($sheet, 0, 31);

    $content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';

    $root_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . xlsx_xml($sheet) . '" sheetId="1" r:id="rId1"/></sheets></workbook>';

    $wb_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    // style 0 = normal, style 1 = bold text on a light-grey background
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFD9E2F3"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    // ---- zip it into a temporary file, send it, delete it ----
    $tmp = tempnam(sys_get_temp_dir(), 'gtk');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;
    $zip->addFromString('[Content_Types].xml', $content_types);
    $zip->addFromString('_rels/.rels', $root_rels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wb_rels);
    $zip->addFromString('xl/styles.xml', $styles);
    $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
    if (!$zip->close()) { @unlink($tmp); return false; }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $filename) . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    @unlink($tmp);
    return true;
}
