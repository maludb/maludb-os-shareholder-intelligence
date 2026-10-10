<?php
declare(strict_types=1);

/**
 * What every parser shares (position-reports.md §1–§2): the cell typing — numbers with separators and zero-padded decimals, the three date
 * forms and an Excel serial, DTC's two "no balance" marks — the CSV reader, and the result every connector answers:
 *   ['connector', 'kind', 'cusip', 'rows' => [[row_no, number, name, date, shares, raw[], error|null]…], 'control' => [...], 'record_date' => ?, 'notes' => [...]]
 * A parser throws SiParseError when the file is not the layout it reads (the words reach the person; nothing is stored); a cell that fails
 * to type is a row with its error, never a refusal of the file.
 */

final class SiParseError extends RuntimeException {}

/** DTC's marks for a day with no balance: the Total row's -9999999999999, a participant row's "Holiday", or an empty cell. */
function si_cell_absent(?string $v): bool
{
    $v = strtolower(trim((string) $v));
    return $v === '' || $v === '-9999999999999' || $v === 'holiday';
}

/** A whole number of shares from a cell ("1,234,567", "000461260.00000", "200"); null when it is not one. */
function si_cell_shares(?string $v): ?string
{
    $v = str_replace([',', ' ', '$', "\u{a0}"], '', trim((string) $v));
    if (!preg_match('~^-?\d+(\.\d+)?$~', $v)) {
        return null;
    }
    if (str_contains($v, '.')) {
        [$int, $frac] = explode('.', $v, 2);
        if (trim($frac, '0') !== '') {
            return null;                                                 // a fraction of a share is not a position
        }
        $v = $int;
    }
    $neg = str_starts_with($v, '-');
    $v = ltrim(ltrim($v, '-'), '0');
    return ($neg && $v !== '' ? '-' : '') . ($v === '' ? '0' : $v);
}

/** A date from a cell: MM/DD/YYYY, YYYY-MM-DD, MMDDYY, or an Excel serial day (45000) → Y-m-d; null when it is none of them. */
function si_cell_date(?string $v): ?string
{
    $v = trim((string) $v);
    if (preg_match('~^(\d{1,2})/(\d{1,2})/(\d{4})$~', $v, $m)) {
        return checkdate((int) $m[1], (int) $m[2], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[1], $m[2]) : null;
    }
    if (preg_match('~^(\d{4})-(\d{2})-(\d{2})~', $v, $m)) {
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
    }
    if (preg_match('~^(\d{2})(\d{2})(\d{2})$~', $v, $m)) {
        return checkdate((int) $m[1], (int) $m[2], 2000 + (int) $m[3]) ? sprintf('20%02d-%02d-%02d', $m[3], $m[1], $m[2]) : null;
    }
    if (preg_match('~^\d{5}(\.0+)?$~', $v) && (int) $v > 30000 && (int) $v < 80000) {
        return gmdate('Y-m-d', ((int) $v - 25569) * 86400);              // an Excel serial day (1900 system)
    }
    return null;
}

/** A four-character DTC participant number from a cell ("756" → "0756"); null when it is not one. */
function si_cell_participant(?string $v): ?string
{
    $v = trim((string) $v);
    return preg_match('~^\d{1,4}$~', $v) ? str_pad($v, 4, '0', STR_PAD_LEFT) : null;
}

/**
 * A CSV's rows of cells (a BOM stripped, quotes honoured, CR LF or LF). A row of nothing but empty cells is kept as [] — its place counts (row
 * numbers stay the file's) but it costs nothing; $limit stops after that many rows (the detector reads only the head).
 */
function si_csv_rows(string $bytes, ?int $limit = null): array
{
    if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
        $bytes = substr($bytes, 3);
    }
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $bytes);
    rewind($fh);
    $rows = [];
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        $cells = $r === [null] ? [] : array_map(static fn ($c): string => trim((string) $c), $r);
        $rows[] = implode('', $cells) === '' ? [] : $cells;
        if ($limit !== null && count($rows) >= $limit) {
            break;
        }
    }
    fclose($fh);
    return $rows;
}

/** A sheet's or a CSV's rows as cells (the workbook's first sheet for an XLSX); $limit rows at most. */
function si_file_rows(string $bytes, ?int $limit = null): array
{
    if (str_starts_with($bytes, "PK\x03\x04")) {
        require_once dirname(__DIR__) . '/xlsx.php';
        $sheets = si_xlsx_sheets($bytes, $limit ?? 200000);
        return array_values((array) (reset($sheets) ?: []));
    }
    return si_csv_rows($bytes, $limit);
}

/** The first row whose first non-empty cells start with $prefix (case-insensitive); its index or null. */
function si_find_row(array $rows, array $prefix, int $from = 0): ?int
{
    for ($i = $from, $n = count($rows); $i < $n; $i++) {
        $cells = array_values(array_filter($rows[$i], static fn ($c) => $c !== ''));
        $ok = count($cells) >= count($prefix);
        foreach ($prefix as $k => $p) {
            if (!$ok || strcasecmp($cells[$k], $p) !== 0) { $ok = false; break; }
        }
        if ($ok) {
            return $i;
        }
    }
    return null;
}

/** The text of the whole file's first rows, joined, for the detector and the title lines. */
function si_rows_text(array $rows, int $limit = 30): string
{
    return implode("\n", array_map(static fn ($r) => implode(',', $r), array_slice($rows, 0, $limit)));
}
