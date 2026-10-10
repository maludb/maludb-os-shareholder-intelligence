<?php
declare(strict_types=1);

/**
 * Connector `ta_extract_csv` — the transfer agent's Shareholder Extract (the owner's eight files; holder-identity.md §1): the header names the
 * columns; Excel-quoted cells (="210") unwrapped; ONE register row per non-zero type column — CERT, BOOK, DRS, BOOKR (restricted book: the
 * insiders' shares), PLAN1–4 — with `position_type` the column's name and `holder_ref` the ACCT#; the registration = HOLDER REG 1–6 joined
 * by newlines; the address = HOLDER ADDRESS 1–3; OWNERSHIP TYPE kept in the row's raw cells; CEDE & CO (FAST) flagged by the table. The
 * columns the application never stores (TAX-ID, DATE OF BIRTH, PHONE NUMBER, EMAIL ADDRESS, the withholding and NRA columns — the template's
 * drop_columns) are stripped before the file is stored and never read here. Control: TOTAL SHARES per row against its type columns (a row
 * that disagrees keeps its error), and the file's TOTAL SHARES against the rows written. No record date in the file: the upload's, or the
 * file name's ("Shareholder Extract - BMNR 06-30-24.csv").
 */
require_once __DIR__ . '/common.php';

const SI_TA_TYPES = ['CERT', 'BOOK', 'DRS', 'BOOKR', 'PLAN1', 'PLAN2', 'PLAN3', 'PLAN4'];

/** ="210" → 210. */
function si_excel_unquote(string $v): string
{
    $v = trim($v);
    return preg_match('~^="(.*)"$~s', $v, $m) ? trim($m[1]) : $v;
}

function si_parse_ta_extract_csv(string $bytes): array
{
    $rows = si_csv_rows($bytes);
    $hdr = array_map(static fn ($c): string => strtoupper(si_excel_unquote((string) $c)), $rows[0] ?? []);
    $cols = array_flip($hdr);
    foreach (['ACCT#', 'HOLDER REG 1', 'TOTAL SHARES'] as $need) {
        if (!isset($cols[$need])) {
            throw new SiParseError('This is not the transfer agent\'s Shareholder Extract: no "' . $need . '" column.');
        }
    }
    $c = static fn (array $r, string $k): string => isset($cols[$k]) ? si_excel_unquote((string) ($r[$cols[$k]] ?? '')) : '';
    $out = [];
    $fileTotal = 0;
    $written = 0;
    $holders = 0;
    $cusip = null;
    $rowNo = 0;
    for ($i = 1, $n = count($rows); $i < $n; $i++) {
        $r = $rows[$i];
        if ($r === [] || implode('', $r) === '') {
            continue;
        }
        $holders++;
        $cusip ??= preg_match('~^[0-9A-Z]{9}$~', strtoupper($c($r, 'CUSIP'))) ? strtoupper($c($r, 'CUSIP')) : null;
        $reg = implode("\n", array_values(array_filter(array_map(static fn (int $k) => trim(preg_replace('~\s+~', ' ', $c($r, 'HOLDER REG ' . $k)) ?? ''), range(1, 6)), static fn ($x) => $x !== '')));
        $addr = implode(', ', array_values(array_filter(array_map(static fn (int $k) => trim(preg_replace('~\s+~', ' ', $c($r, 'HOLDER ADDRESS ' . $k)) ?? ''), range(1, 3)), static fn ($x) => $x !== '')));
        $total = si_cell_shares($c($r, 'TOTAL SHARES'));
        $byType = [];
        $typeSum = 0;
        foreach (SI_TA_TYPES as $t) {
            $v = si_cell_shares($c($r, $t));
            if ($v !== null && $v !== '0') {
                $byType[$t] = $v;
                $typeSum += (int) $v;
            }
        }
        $err = $total === null ? 'TOTAL SHARES is not a number: ' . mb_substr($c($r, 'TOTAL SHARES'), 0, 20)
             : ((int) $total !== $typeSum ? 'TOTAL SHARES ' . number_format((int) $total) . ' is not the sum of its types (' . number_format($typeSum) . ')' : null);
        if ($total !== null) {
            $fileTotal += (int) $total;
        }
        $raw = ['line' => $i + 1, 'ownership_type' => $c($r, 'OWNERSHIP TYPE'), 'total_shares' => $total];
        $base = ['holder_ref' => $c($r, 'ACCT#') ?: null, 'registration_name' => $reg === '' ? null : $reg, 'address_line' => $addr === '' ? null : $addr,
                 'city' => $c($r, 'CITY') ?: null, 'state' => $c($r, 'STATE') ?: null, 'country' => $c($r, 'COUNTRY') ?: null, 'zip' => $c($r, 'ZIP CODE') ?: null,
                 'issue_date' => si_cell_date_short($c($r, 'DATE OPENED')), 'section' => null, 'position_id' => null, 'cost_basis' => null, 'percent_owned' => null,
                 'is_employee' => null, 'is_officer_director' => null, 'is_us_citizen' => null];
        if ($byType === []) {
            $out[] = $base + ['row_no' => ++$rowNo, 'position_type' => null, 'shares' => $total === null ? null : '0', 'restriction_text' => null, 'raw' => $raw, 'error' => $err ?? ($total === '0' ? null : 'No type column holds the shares.')];
            continue;
        }
        foreach ($byType as $t => $v) {
            $out[] = $base + ['row_no' => ++$rowNo, 'position_type' => $t, 'shares' => $v, 'restriction_text' => $t === 'BOOKR' ? 'restricted book' : null, 'raw' => $raw + ['column' => $t], 'error' => $err];
            if ($err === null) {
                $written += (int) $v;
            }
        }
    }
    if ($holders === 0) {
        throw new SiParseError('The extract lists no holder.');
    }
    $failed = count(array_unique(array_map(static fn ($r) => $r['raw']['line'], array_filter($out, static fn ($r) => $r['error'] !== null))));
    $diffs = [];
    if ($fileTotal !== $written) {
        $diffs[] = 'TOTAL SHARES adds to ' . number_format($fileTotal) . ', the rows written to ' . number_format($written);
    }
    if ($failed > 0) {
        $diffs[] = $failed . ' holder line' . ($failed === 1 ? '' : 's') . ' failed to type';
    }
    return ['cusip' => $cusip, 'record_date' => null, 'period' => [null, null], 'rows' => $out,
            'control' => ['layout' => 'ta_extract', 'holders' => $holders, 'total_shares' => $fileTotal],
            'derived' => ['rows' => count($out), 'shares' => $written, 'rows_failed' => $failed],
            'tie' => [$diffs === [], $diffs === [] ? null : ucfirst(implode('; ', $diffs)) . '.'], 'needs_record_date' => true];
}

/** MM/DD/YY (the extract's DATE OPENED) or any si_cell_date form. */
function si_cell_date_short(string $v): ?string
{
    if (preg_match('~^(\d{1,2})/(\d{1,2})/(\d{2})$~', trim($v), $m)) {
        $y = (int) $m[3] + ((int) $m[3] > 69 ? 1900 : 2000);
        return checkdate((int) $m[1], (int) $m[2], $y) ? sprintf('%04d-%02d-%02d', $y, $m[1], $m[2]) : null;
    }
    return si_cell_date($v);
}
