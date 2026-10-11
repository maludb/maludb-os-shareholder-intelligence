<?php
declare(strict_types=1);

/**
 * Connector `generic_mapped` — a file in a layout no vendor connector reads, mapped column by column by a saved import template
 * (position-reports.md §1.2: "else the person picks a template"). It reads a position report (`spr`), a NOBO list (`nobo`) or a register
 * (`register`) from a CSV, an XLSX or an old XLS. The template's mapping:
 *
 *   {"columns": {"<field>": "<header text>" | "<column letter>" | ["<header>", …]},   the fields of the kind (SI_GENERIC_FIELDS); a list is joined
 *    "header_row": 3,            the row (1-based) holding the column names; found by the names when omitted; 0 = no header (letters only)
 *    "sheet": 1 | "Holders",     a workbook's sheet (the first when omitted)
 *    "total_row": "Total",       a row whose first cell starts with this carries the file's own total (the control); else the rows' sum is
 *    "record_date_cell": "B2",   the as-of date's cell (else the date the person gives, else the file name's)
 *    "cusip_cell": "A1"}         the CUSIP's cell (or a `cusip` column)
 *
 * WHAT IS KEPT (design §0.2b): only the mapped columns. The stored copy is a canonical CSV — a first line `#si-generic/1` with what the file said
 * of itself (kind, CUSIP, record date, its total), the field names, then each row's mapped cells with the file's row number — so nothing the
 * template does not name (a tax id, a birth date, a phone) ever reaches the store, and a re-parse reads the copy as it is. A template may not map
 * a column whose name says it is one of those (SI_GENERIC_NEVER_MAPPED): refused when saved and when read.
 */
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/broadridge_nobo.php';                         // si_shares_exact, si_shares_words
require_once __DIR__ . '/ta_extract.php';                              // si_excel_unquote, si_cell_date_short
require_once __DIR__ . '/dtc_spr_onetime_csv.php';                     // bcadd_si

/** kind => [field => required]; the order is the canonical copy's column order. */
const SI_GENERIC_FIELDS = [
    'spr'      => ['participant_number' => true, 'participant_name' => false, 'shares' => true, 'position_date' => false, 'cusip' => false],
    'register' => ['registration_name' => true, 'shares' => true, 'holder_ref' => false, 'address_line' => false, 'city' => false, 'state' => false, 'zip' => false,
                   'country' => false, 'position_type' => false, 'account_kind' => false, 'issue_date' => false, 'cost_basis' => false, 'restriction_text' => false, 'cusip' => false],
    'nobo'     => ['name' => true, 'shares' => true, 'address_line' => false, 'city' => false, 'state' => false, 'zip' => false, 'country' => false, 'custodian' => false, 'cusip' => false],
];
/** A mapped column whose header says it holds what the application never stores (design §0.2b) — refused, whatever the template calls the field. */
const SI_GENERIC_NEVER_MAPPED = '~\b(TAX[\s_-]*ID|TIN|SSN|SOCIAL[\s_-]*SECURITY|EIN|DATE[\s_-]*OF[\s_-]*BIRTH|BIRTH[\s_-]*DATE|DOB|PHONE|MOBILE|CELL|FAX|E[\s_-]*MAIL|PASSPORT|DRIVER|BANK[\s_-]*ACC\w*|ROUTING|IBAN)\b~i';
const SI_GENERIC_MARK = '#si-generic/1';

/** Why $mapping cannot read a $kind file, or null. Checked when a template of kind spr, nobo or register is saved and again when a file is read
 *  (a generic template of another kind — prices, holders, share range — is kept as written for a reader to come; it too never maps a never-stored column). */
function si_generic_mapping_problem(string $kind, mixed $mapping): ?string
{
    if (!isset(SI_GENERIC_FIELDS[$kind])) {
        return 'A generic template reads a DTC position report, a NOBO list or a register (kind spr, nobo or register).';
    }
    if (!is_array($mapping) || !is_array($mapping['columns'] ?? null) || $mapping['columns'] === [] || array_is_list($mapping['columns'])) {
        return 'A generic template names its columns: {"columns": {"field": "column"}}.';
    }
    $fields = SI_GENERIC_FIELDS[$kind];
    $unknown = array_diff(array_keys($mapping['columns']), array_keys($fields));
    if ($unknown !== []) {
        return 'A ' . $kind . ' template reads the fields ' . implode(', ', array_keys($fields)) . ' — not ' . implode(', ', $unknown) . '.';
    }
    $missing = array_keys(array_filter($fields, static fn (bool $req, string $f) => $req && !isset($mapping['columns'][$f]), ARRAY_FILTER_USE_BOTH));
    if ($missing !== []) {
        return 'A ' . $kind . ' template must map ' . implode(' and ', $missing) . '.';
    }
    foreach ($mapping['columns'] as $field => $ref) {
        $refs = is_array($ref) ? $ref : [$ref];
        if ($refs === [] || count($refs) > 8) {
            return 'The field ' . $field . ' names one to eight columns.';
        }
        foreach ($refs as $r) {
            if (!is_string($r) || trim($r) === '' || mb_strlen($r) > 80) {
                return 'The field ' . $field . ' names a column by its header or its letter.';
            }
            if (preg_match(SI_GENERIC_NEVER_MAPPED, $r)) {
                return 'The column "' . $r . '" holds what the application never stores (a tax id, a birth date, a phone, an email): it cannot be mapped.';
            }
        }
    }
    if (isset($mapping['header_row']) && (!is_int($mapping['header_row']) || $mapping['header_row'] < 0 || $mapping['header_row'] > 200)) {
        return 'header_row is the row number of the column names (0 for none).';
    }
    foreach (['record_date_cell', 'cusip_cell'] as $k) {
        if (isset($mapping[$k]) && (!is_string($mapping[$k]) || !preg_match('~^[A-Z]{1,3}[1-9]\d{0,3}$~', $mapping[$k]))) {
            return $k . ' is a cell such as B2.';
        }
    }
    if (isset($mapping['total_row']) && (!is_string($mapping['total_row']) || trim($mapping['total_row']) === '')) {
        return 'total_row is the words the total row starts with.';
    }
    if (isset($mapping['sheet']) && !is_int($mapping['sheet']) && !is_string($mapping['sheet'])) {
        return 'sheet is a sheet\'s number or its name.';
    }
    return null;
}

/** "C" → 2, "AA" → 26. */
function si_generic_letter(string $l): int
{
    $n = 0;
    foreach (str_split(strtoupper($l)) as $ch) {
        $n = $n * 26 + (ord($ch) - 64);
    }
    return $n - 1;
}

/** The value of a cell such as B2 in $rows. */
function si_generic_cell(array $rows, string $ref): string
{
    preg_match('~^([A-Z]{1,3})(\d+)$~', $ref, $m);
    return trim(si_excel_unquote((string) ($rows[(int) $m[2] - 1][si_generic_letter($m[1])] ?? '')));
}

/** The sheet's rows: a CSV's, an XLSX's or an XLS's (the template's sheet, by number or name; the first when it names none). */
function si_generic_rows(string $bytes, mixed $sheet): array
{
    $isZip = str_starts_with($bytes, "PK\x03\x04");
    $isOle = str_starts_with($bytes, "\xD0\xCF\x11\xE0");
    if (!$isZip && !$isOle) {
        return si_csv_rows($bytes);
    }
    if ($isZip) {
        require_once dirname(__DIR__) . '/xlsx.php';
        $sheets = si_xlsx_sheets($bytes);
    } else {
        require_once dirname(__DIR__) . '/xls.php';
        $sheets = si_xls_sheets($bytes, 200000);
    }
    if ($sheet === null) {
        return array_values((array) (reset($sheets) ?: []));
    }
    if (is_int($sheet)) {
        $all = array_values($sheets);
        return isset($all[$sheet - 1]) ? array_values((array) $all[$sheet - 1]) : throw new SiParseError('The workbook has no sheet ' . $sheet . '.');
    }
    foreach ($sheets as $name => $r) {
        if (strcasecmp(trim((string) $name), trim($sheet)) === 0) {
            return array_values((array) $r);
        }
    }
    throw new SiParseError('The workbook has no sheet named "' . mb_substr($sheet, 0, 40) . '".');
}

/**
 * The file as the template reads it: ['meta' => [kind, cusip, record_date, total, template], 'fields' => [...], 'rows' => [[file row, [field => value]]…]].
 * A canonical copy (the stored file) reads itself. $given is the record date the person gave or the file name holds.
 */
function si_generic_extract(string $bytes, array $mapping, ?string $given = null): array
{
    if (str_starts_with(ltrim($bytes, "\xEF\xBB\xBF"), SI_GENERIC_MARK)) {
        return si_generic_read_canonical($bytes);
    }
    $kind = (string) ($mapping['kind'] ?? '');
    if (($p = si_generic_mapping_problem($kind, $mapping)) !== null) {
        throw new SiParseError($p);
    }
    $rows = si_generic_rows($bytes, $mapping['sheet'] ?? null);
    $cols = $mapping['columns'];
    $named = [];
    foreach ($cols as $ref) {
        foreach ((array) $ref as $r) {
            if (!preg_match('~^[A-Z]{1,3}$~', $r)) { $named[] = strtoupper(trim($r)); }
        }
    }
    // the header row: the template's, or the first of the first thirty that carries every named column
    $h = isset($mapping['header_row']) ? (int) $mapping['header_row'] - 1 : null;
    if ($h === null && $named !== []) {
        for ($i = 0, $n = min(count($rows), 30); $i < $n && $h === null; $i++) {
            $u = array_map(static fn ($c): string => strtoupper(si_excel_unquote((string) $c)), $rows[$i]);
            if (array_diff($named, $u) === []) { $h = $i; }
        }
        if ($h === null) {
            throw new SiParseError('The template\'s columns (' . implode(', ', array_slice($named, 0, 6)) . ') are not in this file\'s first thirty rows: it is another layout.');
        }
    }
    $h ??= -1;
    $header = $h >= 0 ? array_map(static fn ($c): string => strtoupper(si_excel_unquote((string) $c)), $rows[$h] ?? []) : [];
    $index = [];
    foreach ($cols as $field => $ref) {
        foreach ((array) $ref as $r) {
            $k = array_search(strtoupper(trim($r)), $header, true);
            if ($k === false && preg_match('~^[A-Z]{1,3}$~', $r)) {
                $k = si_generic_letter($r);
            }
            if ($k === false) {
                throw new SiParseError('The column "' . mb_substr($r, 0, 40) . '" is not in the file\'s header row.');
            }
            if ($header !== [] && isset($header[$k]) && preg_match(SI_GENERIC_NEVER_MAPPED, $header[$k])) {
                throw new SiParseError('The column "' . mb_substr($header[$k], 0, 40) . '" holds what the application never stores: the template cannot read it.');
            }
            $index[$field][] = $k;
        }
    }
    $join = static fn (string $f): string => match ($f) { 'registration_name' => "\n", 'name' => ' ', default => ', ' };   // a registration's lines; a holder's first, middle and last names
    $marker = isset($mapping['total_row']) ? strtoupper(trim($mapping['total_row'])) : null;
    $total = null;
    $out = [];
    for ($i = $h + 1, $n = count($rows); $i < $n; $i++) {
        $r = $rows[$i];
        if ($r === [] || implode('', $r) === '') {
            continue;
        }
        $first = '';
        foreach ($r as $c) { if (trim((string) $c) !== '') { $first = strtoupper(trim((string) $c)); break; } }
        if ($marker !== null && str_starts_with($first, $marker)) {
            $total = trim(si_excel_unquote((string) ($r[$index['shares'][0]] ?? '')));
            continue;
        }
        $v = [];
        foreach ($index as $field => $ks) {
            $parts = array_values(array_filter(array_map(static fn (int $k): string => trim(preg_replace('~\s+~', ' ', si_excel_unquote((string) ($r[$k] ?? ''))) ?? ''), $ks), static fn ($x) => $x !== ''));
            $v[$field] = implode($join($field), $parts);
        }
        if (implode('', $v) === '') {
            continue;
        }
        $out[] = [$i + 1, $v];
    }
    $rd = isset($mapping['record_date_cell']) ? si_cell_date_short(si_generic_cell($rows, $mapping['record_date_cell'])) : null;
    $cusip = isset($mapping['cusip_cell']) ? strtoupper(si_generic_cell($rows, $mapping['cusip_cell'])) : null;
    if ($cusip === null || !preg_match('~^[0-9A-Z]{9}$~', $cusip)) {
        $cusip = null;
        foreach ($out as [, $v]) {
            $c = strtoupper((string) ($v['cusip'] ?? ''));
            if (preg_match('~^[0-9A-Z]{9}$~', $c)) { $cusip = $c; break; }
        }
    }
    return ['meta' => ['kind' => $kind, 'cusip' => $cusip, 'record_date' => $rd ?? $given, 'total' => $total, 'template' => $mapping['template_name'] ?? null],
            'fields' => array_keys($index), 'rows' => $out];
}

/** The canonical copy kept in the store: the marker line with what the file said of itself, the field names, the mapped cells. */
function si_generic_canonical_csv(array $x): string
{
    $fh = fopen('php://memory', 'r+');
    fputcsv($fh, [SI_GENERIC_MARK, (string) json_encode($x['meta'])], ',', '"', '');
    fputcsv($fh, array_merge(['_row'], $x['fields']), ',', '"', '');
    foreach ($x['rows'] as [$rowNo, $v]) {
        fputcsv($fh, array_merge([(string) $rowNo], array_map(static fn (string $f): string => (string) ($v[$f] ?? ''), $x['fields'])), ',', '"', '');
    }
    rewind($fh);
    $out = (string) stream_get_contents($fh);
    fclose($fh);
    return $out;
}

function si_generic_read_canonical(string $bytes): array
{
    $rows = si_csv_rows($bytes);
    $meta = json_decode((string) ($rows[0][1] ?? ''), true);
    $fields = array_slice($rows[1] ?? [], 1);
    if (!is_array($meta) || !isset(SI_GENERIC_FIELDS[$meta['kind'] ?? '']) || array_diff($fields, array_keys(SI_GENERIC_FIELDS[$meta['kind']])) !== []) {
        throw new SiParseError('The kept copy of this generic upload cannot be read.');
    }
    $out = [];
    for ($i = 2, $n = count($rows); $i < $n; $i++) {
        if ($rows[$i] === []) { continue; }
        $out[] = [(int) $rows[$i][0], array_combine($fields, array_map(static fn (int $k) => (string) ($rows[$i][$k + 1] ?? ''), array_keys($fields)))];
    }
    return ['meta' => $meta, 'fields' => $fields, 'rows' => $out];
}

/** The kept copy of an original file (what upload_create stores). */
function si_generic_kept(string $bytes, array $mapping, ?string $given = null): string
{
    return si_generic_canonical_csv(si_generic_extract($bytes, $mapping, $given));
}

/** The parse: the kind's rows and control in the shape its loader reads (SPR as dtc_spr_*, NOBO as the names export, a register as the extract). */
function si_parse_generic_mapped(string $bytes, array $mapping = [], ?string $given = null): array
{
    $x = si_generic_extract($bytes, $mapping, $given);
    if ($x['rows'] === []) {
        throw new SiParseError('The template reads no row from this file.');
    }
    $meta = $x['meta'];
    $control = static fn (array $c): array => $c + ['layout' => 'generic', 'template' => $meta['template'],
        'control_is' => $meta['total'] !== null ? 'the file\'s own total row' : 'the sum of the rows (the template names no total row)'];
    $base = ['cusip' => $meta['cusip'], 'kind' => $meta['kind'], 'generic_meta' => $meta];
    return $base + match ($meta['kind']) {
        'spr' => si_generic_spr($x, $control),
        'register' => si_generic_register($x, $control),
        'nobo' => si_generic_nobo($x, $control),
    };
}

function si_generic_spr(array $x, callable $control): array
{
    $meta = $x['meta'];
    $out = [];
    $sums = [];
    $numbers = [];
    foreach ($x['rows'] as [$rowNo, $v]) {
        if (trim($v['participant_number']) === '') {
            continue;                                                      // a heading or a subtotal line: no participant on it
        }
        $num = si_cell_participant($v['participant_number']);
        $date = ($v['position_date'] ?? '') !== '' ? si_cell_date_short($v['position_date']) : $meta['record_date'];
        if ($date === null) {
            throw new SiParseError(($v['position_date'] ?? '') !== '' ? 'Row ' . $rowNo . '\'s position date is not a date.' : 'The file does not say the date its positions are as of: give it.');
        }
        $sh = si_cell_shares($v['shares']);
        $err = $num === null ? 'The participant number is not one: ' . mb_substr($v['participant_number'], 0, 20)
             : ($sh === null ? 'The shares are not a whole number: ' . mb_substr($v['shares'], 0, 30) : null);
        $out[] = [$rowNo, $num, $v['participant_name'] ?? '', $date, $sh, array_filter(['number' => $v['participant_number'], 'name' => $v['participant_name'] ?? null, 'date' => $v['position_date'] ?? null, 'shares' => $v['shares']], static fn ($c) => $c !== null), $err];
        if ($err === null) {
            $sums[$date] = bcadd_si($sums[$date] ?? '0', (string) $sh);
            $numbers[$num] = true;
        }
    }
    if ($out === []) {
        throw new SiParseError('The template reads no participant from this file.');
    }
    $byDate = $sums;
    $notes = [];
    if ($meta['total'] !== null) {
        if (count($sums) > 1) {
            $notes[] = 'The file\'s total row covers several dates: each date is tied to the sum of its rows.';
        } else {
            $t = si_cell_shares((string) $meta['total']);
            $byDate = [array_key_first($sums) ?? (string) $meta['record_date'] => $t ?? '0'];
        }
    } else {
        $notes[] = 'The template names no total row: the file is tied to the sum of its own rows.';
    }
    $dates = array_keys($sums);
    sort($dates);
    return ['rows' => $out, 'period' => [$dates[0] ?? null, end($dates) ?: null], 'record_date' => null,
            'control' => $control(['shares_by_date' => $byDate, 'participants_listed' => count($numbers)]), 'notes' => $notes];
}

function si_generic_register(array $x, callable $control): array
{
    $meta = $x['meta'];
    $out = [];
    $written = 0;
    foreach ($x['rows'] as [$rowNo, $v]) {
        $sh = si_cell_shares($v['shares']);
        $err = $sh === null ? 'The shares are not a whole number: ' . mb_substr($v['shares'], 0, 30) : ($v['registration_name'] === '' ? 'The row has no registration.' : null);
        $out[] = ['row_no' => $rowNo, 'section' => null, 'position_id' => null, 'position_type' => ($v['position_type'] ?? '') ?: null, 'holder_ref' => ($v['holder_ref'] ?? '') ?: null,
                  'registration_name' => $v['registration_name'] ?: null, 'address_line' => ($v['address_line'] ?? '') ?: null, 'city' => ($v['city'] ?? '') ?: null, 'state' => ($v['state'] ?? '') ?: null,
                  'country' => ($v['country'] ?? '') ?: null, 'zip' => ($v['zip'] ?? '') ?: null, 'issue_date' => ($v['issue_date'] ?? '') !== '' ? si_cell_date_short($v['issue_date']) : null,
                  'shares' => $sh, 'cost_basis' => ($v['cost_basis'] ?? '') !== '' && is_numeric(str_replace([',', '$'], '', $v['cost_basis'])) ? str_replace([',', '$'], '', $v['cost_basis']) : null,
                  'restriction_text' => ($v['restriction_text'] ?? '') ?: null, 'percent_owned' => null, 'is_employee' => null, 'is_officer_director' => null, 'is_us_citizen' => null,
                  'raw' => ['line' => $rowNo, 'shares' => mb_substr($v['shares'], 0, 30)] + (($v['account_kind'] ?? '') !== '' ? ['ownership_type' => $v['account_kind']] : []), 'error' => $err];
        if ($err === null) { $written += (int) $sh; }
    }
    $failed = count(array_filter($out, static fn ($r) => $r['error'] !== null));
    $total = $meta['total'] !== null ? si_cell_shares((string) $meta['total']) : (string) $written;
    $diffs = [];
    if ($total === null) {
        $diffs[] = 'the total row\'s shares are not a number';
    } elseif ((int) $total !== $written) {
        $diffs[] = 'the file\'s total says ' . number_format((int) $total) . ', the rows written add to ' . number_format($written);
    }
    if ($failed > 0) {
        $diffs[] = $failed . ' row' . ($failed === 1 ? '' : 's') . ' failed to type';
    }
    return ['record_date' => $meta['record_date'], 'period' => [null, null], 'rows' => $out,
            'control' => $control(['holders' => count($out), 'total_shares' => $total === null ? null : (int) $total]),
            'derived' => ['rows' => count($out), 'shares' => $written, 'rows_failed' => $failed],
            'tie' => [$diffs === [], $diffs === [] ? ($meta['total'] === null ? 'Tied to the sum of its own rows: the template names no total row.' : null) : ucfirst(implode('; ', $diffs)) . '.'], 'needs_record_date' => true];
}

function si_generic_nobo(array $x, callable $control): array
{
    $meta = $x['meta'];
    $out = [];
    $sum = 0;
    foreach ($x['rows'] as [$rowNo, $v]) {
        $exact = si_shares_exact($v['shares']);
        $country = strtoupper(trim($v['country'] ?? ''));
        $us = $country === '' || str_starts_with($country, 'UNITED STATES') || in_array($country, ['US', 'USA'], true);
        $zip = trim($v['zip'] ?? '');
        if ($us && preg_match('~^\d{3,4}$~', $zip)) { $zip = str_pad($zip, 5, '0', STR_PAD_LEFT); }
        $csz = trim(implode(' ', array_filter([$v['city'] ?? '', $v['state'] ?? '', $zip], static fn ($s) => $s !== '')));
        $lines = array_values(array_filter([$v['name'], $v['address_line'] ?? '', $csz, $us ? '' : $country], static fn ($s) => $s !== ''));
        $lines = array_pad(array_slice($lines, 0, 7), 7, '');
        $err = $exact === null ? 'The shares are not a number: ' . mb_substr($v['shares'], 0, 20) : ($v['name'] === '' ? 'The row has no name.' : null);
        $out[] = [$rowNo, $exact === null ? null : (string) intdiv($exact + 50000, 100000), $lines, $zip, ($v['cusip'] ?? '') ?: null, $err, $v['shares'], ($v['custodian'] ?? '') ?: null, $us ? 'US' : $country];
        if ($exact !== null && $err === null) { $sum += $exact; }
    }
    $failed = count(array_filter($out, static fn ($r) => $r[5] !== null));
    $total = $meta['total'] !== null ? si_shares_exact((string) $meta['total']) : $sum;
    $diffs = [];
    if ($total === null) {
        $diffs[] = 'the total row\'s shares are not a number';
    } elseif ($total !== $sum) {
        $diffs[] = 'the file\'s total says ' . si_shares_words($total) . ' shares, the rows add to ' . si_shares_words($sum);
    }
    if ($failed > 0) {
        $diffs[] = $failed . ' row' . ($failed === 1 ? '' : 's') . ' failed to type';
    }
    return [
        'record_date' => $meta['record_date'], 'period' => [null, null],
        'job' => ['job_number' => null, 'cusip' => $meta['cusip'], 'issuer_name' => null, 'record_date' => $meta['record_date'], 'sort_order' => null, 'nobo_count_header' => null],
        'rows' => $out, 'clients' => [],
        'control' => $control(['total_shares' => $total === null ? null : si_shares_words($total, false), 'clients' => 0,
                               'no_clients' => 'A generic NOBO list carries no Bank/Broker listing: the OBO remainder for this date is not computed.']),
        'derived' => ['nobo_count' => count($out) - $failed, 'shares' => si_shares_words($sum, false), 'rows_failed' => $failed],
        'tie' => [$diffs === [], $diffs === [] ? ($meta['total'] === null ? 'Tied to the sum of its own rows: the template names no total row.' : null) : ucfirst(implode('; ', $diffs)) . '.'],
        'needs_record_date' => true,
    ];
}
