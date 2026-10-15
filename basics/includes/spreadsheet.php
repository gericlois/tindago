<?php
// Reads product sheets for basics/admin/product_import.php — Excel (.xlsx),
// CSV, and Google Sheets links — without a library, since the host has no
// Composer. Every reader returns [rows, error]: rows is a list of lists of
// cell strings (first sheet only), error is null or a message for the admin.

const TINDAGO_SHEET_MAX_BYTES = 5 * 1024 * 1024;

function tindago_sheet_rows_from_csv($text) {
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    if (!mb_check_encoding($text, 'UTF-8')) {
        // Excel's "CSV" (not "CSV UTF-8") is saved in the Windows codepage.
        $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }
    // Excel in some locales saves "CSV" with semicolons.
    $first_line = strtok($text, "\r\n");
    $delimiter = substr_count($first_line, ';') > substr_count($first_line, ',') ? ';' : ',';

    $handle = fopen('php://temp', 'r+');
    fwrite($handle, $text);
    rewind($handle);
    $rows = [];
    while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
        $rows[] = array_map(fn($cell) => trim((string) $cell), $row);
    }
    fclose($handle);
    return [$rows, null];
}

function tindago_sheet_rows_from_xlsx($path) {
    if (!class_exists('ZipArchive')) {
        return [[], 'This server can\'t read Excel files. Save the sheet as CSV and upload that instead.'];
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return [[], 'That file isn\'t a valid Excel (.xlsx) file. Older .xls files aren\'t supported — save as .xlsx or CSV.'];
    }

    $read = function ($name) use ($zip) {
        $stat = $zip->statName($name);
        // Guards against a tiny upload that unzips to something enormous.
        if (!$stat || $stat['size'] > 50 * 1024 * 1024) {
            return null;
        }
        $xml = $zip->getFromName($name);
        return $xml === false ? null : simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
    };

    // The first sheet in tab order, located through the workbook's
    // relationships (sheet1.xml isn't always the first tab).
    $sheet_file = 'xl/worksheets/sheet1.xml';
    $workbook = $read('xl/workbook.xml');
    $rels = $read('xl/_rels/workbook.xml.rels');
    if ($workbook && $rels) {
        $first = $workbook->xpath('//*[local-name()="sheets"]/*[local-name()="sheet"]')[0] ?? null;
        $rel_id = null;
        if ($first) {
            foreach ($first->attributes('r', true) as $attr => $value) {
                if ($attr === 'id') $rel_id = (string) $value;
            }
        }
        foreach ($rels->xpath('//*[local-name()="Relationship"]') as $rel) {
            if ((string) $rel['Id'] === $rel_id) {
                $target = ltrim((string) $rel['Target'], '/');
                $sheet_file = strpos($target, 'xl/') === 0 ? $target : 'xl/' . $target;
            }
        }
    }

    $shared = [];
    $strings = $read('xl/sharedStrings.xml');
    if ($strings) {
        foreach ($strings->xpath('//*[local-name()="si"]') as $si) {
            // Rich text splits a string into runs; phonetic hints (rPh) aren't part of it.
            $parts = $si->xpath('.//*[local-name()="t"][not(ancestor::*[local-name()="rPh"])]');
            $shared[] = implode('', array_map('strval', $parts));
        }
    }

    $sheet = $read($sheet_file);
    $zip->close();
    if (!$sheet) {
        return [[], 'Couldn\'t find a worksheet in that Excel file.'];
    }

    $rows = [];
    foreach ($sheet->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row_el) {
        $row = [];
        $next_col = 0;
        foreach ($row_el->xpath('*[local-name()="c"]') as $c) {
            // Empty cells are left out of the XML, so place each cell by its
            // reference ("C7" -> column 2) rather than by position.
            $col = $next_col;
            if (preg_match('/^([A-Z]+)/', (string) $c['r'], $m)) {
                $col = 0;
                foreach (str_split($m[1]) as $letter) {
                    $col = $col * 26 + (ord($letter) - 64);
                }
                $col--;
            }
            $type = (string) $c['t'];
            $v = $c->xpath('*[local-name()="v"]')[0] ?? null;
            if ($type === 's') {
                $value = $shared[(int) $v] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = implode('', array_map('strval', $c->xpath('.//*[local-name()="t"]')));
            } elseif ($type === 'b') {
                $value = (string) $v === '1' ? 'TRUE' : 'FALSE';
            } else {
                $value = $v === null ? '' : (string) $v;
                // Excel stores 45.99 as 45.990000000000002.
                if (is_numeric($value) && preg_match('/\.\d{10,}/', $value)) {
                    $value = (string) round((float) $value, 6);
                }
            }
            $row = $row + array_fill(0, $col + 1, '');
            $row[$col] = trim($value);
            $next_col = $col + 1;
        }
        ksort($row);
        // Empty rows are left out too; pad them back so row numbers shown
        // in the preview match the ones in Excel.
        $row_number = (int) $row_el['r'];
        while ($row_number > count($rows) + 1) {
            $rows[] = [];
        }
        $rows[] = $row;
    }
    return [$rows, null];
}

// The CSV export URL for a Google Sheets link, keeping the tab (gid) the
// admin had open. Built from the sheet ID alone, so only Google is fetched.
function tindago_google_sheet_csv_url($url) {
    if (!preg_match('~^https://docs\.google\.com/spreadsheets/d/([a-zA-Z0-9_-]{20,})~', trim($url), $m)) {
        return null;
    }
    $csv_url = 'https://docs.google.com/spreadsheets/d/' . $m[1] . '/export?format=csv';
    if (preg_match('~[#&?]gid=(\d+)~', $url, $g)) {
        $csv_url .= '&gid=' . $g[1];
    }
    return $csv_url;
}

function tindago_sheet_rows_from_google($url) {
    $csv_url = tindago_google_sheet_csv_url($url);
    if (!$csv_url) {
        return [[], 'That doesn\'t look like a Google Sheets link. Copy it from the browser\'s address bar while the sheet is open.'];
    }
    $ch = curl_init($csv_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_MAXFILESIZE, TINDAGO_SHEET_MAX_BYTES);
    $body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $content_type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        error_log('Google Sheets import failed: ' . $curl_error);
        return [[], 'Couldn\'t reach Google Sheets. Try again, or download the sheet as .xlsx and upload it.'];
    }
    // A private sheet answers with Google's sign-in page instead of CSV.
    if ($http_code !== 200 || stripos($content_type, 'text/csv') === false) {
        return [[], 'Google didn\'t share that sheet. In Google Sheets, click Share and set General access to "Anyone with the link" (Viewer), then try again.'];
    }
    return tindago_sheet_rows_from_csv($body);
}
