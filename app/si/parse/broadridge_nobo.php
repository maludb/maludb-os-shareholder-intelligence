<?php
declare(strict_types=1);

/**
 * Connectors `broadridge_nobo_csv` and `broadridge_nobo_xlsx` — Broadridge's NOBO job file (holder-identity.md §1; the owner's 55 real files
 * and the anonymised fixture): row 1 the header's labels (JOB NUMBER, CUSIP, ISSUER NAME, RECORD DATE, SORT, NUMBER OF NOBOS), row 2 its
 * values (the record date MMDDYY — an integer in the workbook, 60823 = 06/08/23; the count zero-padded); the SHARES section under the row
 * whose first cell is SHARES: the shares as written (92703, or the CSV's 000461260.00000), the seven name-and-address lines padded with
 * spaces (trimmed), the zip for sorting, the CUSIP; the summary row under "# OF NOBOS" (the count and the shares); then the CLIENTS section
 * under the row whose first cell is CUSIP — cusip, client, count, shares — the Bank/Broker listing.
 *
 * The control is the file's own statements: the header's count, the summary's count and shares (the label is "# OF NOBOS" or "OF NOBOS"),
 * a section total under a rule line (one workbook draws "------------" and the total before the summary), and the CLIENTS section's shares —
 * each against the rows. **Shares may be fractional** (a fractional-share broker's 39.86928): the tie is exact, in hundred-thousandths of a
 * share; a row stores its shares rounded to the whole share (the columns are whole numbers) and its cell as written, and the count of
 * fractional rows is in the control. A difference is NAMED ("the header says 855 NOBOs, the SHARES section has 841 rows" — Broadridge's suppressions)
 * and the upload is untied until a manager accepts it.
 *   ['cusip', 'record_date', 'job' => […], 'rows' => [[row_no, shares, lines[7], zip_sort, cusip, error]], 'clients' => [[row_no, client, count, shares]], 'control', 'tie' => [tied, note]]
 */
require_once __DIR__ . '/common.php';

function si_parse_broadridge_nobo_csv(string $bytes): array
{
    return si_parse_broadridge_nobo_rows(si_file_rows($bytes));
}

function si_parse_broadridge_nobo_xlsx(string $bytes): array
{
    return si_parse_broadridge_nobo_rows(si_file_rows($bytes));
}

/** A record date as Broadridge writes it: MMDDYY, its integer form without the leading zero (60823), or a date. */
function si_nobo_record_date(string $v): ?string
{
    $v = trim($v);
    if (preg_match('~^\d{5,6}(\.0+)?$~', $v)) {
        return si_cell_date(str_pad((string) (int) $v, 6, '0', STR_PAD_LEFT));
    }
    return si_cell_date($v);
}

function si_parse_broadridge_nobo_rows(array $rows): array
{
    $h = si_find_row($rows, ['JOB NUMBER']);
    if ($h === null || !isset($rows[$h + 1])) {
        throw new SiParseError('This is not Broadridge\'s NOBO job file: no "JOB NUMBER … NUMBER OF NOBOS" header.');
    }
    $labels = array_map('strtoupper', $rows[$h]);
    $vals = $rows[$h + 1];
    $at = static function (string $label) use ($labels, $vals): string {
        foreach ($labels as $i => $l) {
            if (str_starts_with($l, $label)) { return trim((string) ($vals[$i] ?? '')); }
        }
        return '';
    };
    $rd = si_nobo_record_date($at('RECORD DATE'));
    if ($rd === null) {
        throw new SiParseError('The NOBO list\'s header has no record date this reads (' . mb_substr($at('RECORD DATE'), 0, 12) . ').');
    }
    $cusip = strtoupper($at('CUSIP'));
    $headerCount = si_cell_shares($at('NUMBER OF NOBOS'));
    $s = si_find_row($rows, ['SHARES'], $h + 2);
    if ($s === null) {
        throw new SiParseError('The NOBO list has no SHARES section.');
    }
    $out = [];
    $sum = 0;                                                            // exact, in hundred-thousandths of a share
    $summary = null;
    $sectionTotal = null;
    $afterRule = false;
    $fractional = 0;
    $clients = [];
    $clientsAt = null;
    for ($i = $s + 1, $n = count($rows); $i < $n; $i++) {
        $r = $rows[$i];
        if ($r === [] || implode('', $r) === '') {
            continue;
        }
        $first = strtoupper(trim((string) ($r[0] ?? '')));
        if (preg_match('~^#?\s*OF NOBOS~', $first)) {
            $next = $rows[$i + 1] ?? [];
            $summary = ['count' => si_cell_shares((string) ($next[0] ?? '')), 'shares' => si_shares_exact((string) ($next[1] ?? ''))];
            $i++;
            continue;
        }
        if ($first === 'CUSIP') {
            $clientsAt = $i;
            break;
        }
        if (preg_match('~^-{3,}$~', $first) && implode('', array_slice($r, 1)) === '') {
            $afterRule = true;                                              // a rule line: what follows it with no name is the section's total
            continue;
        }
        $lines = [];
        for ($k = 1; $k <= 7; $k++) {
            $lines[] = trim(preg_replace('~\s+~', ' ', (string) ($r[$k] ?? '')) ?? '');
        }
        $exact = si_shares_exact((string) ($r[0] ?? ''));
        if ($afterRule && $exact !== null && implode('', $lines) === '') {
            $sectionTotal = $exact;
            $afterRule = false;
            continue;
        }
        $afterRule = false;
        $err = $exact === null ? 'The shares are not a number: ' . mb_substr((string) ($r[0] ?? ''), 0, 20) : ($lines[0] === '' ? 'The row has no name line.' : null);
        if ($exact !== null && $exact % 100000 !== 0) {
            $fractional++;
        }
        $out[] = [$i + 1, $exact === null ? null : (string) intdiv($exact + 50000, 100000), $lines, trim((string) ($r[8] ?? '')), strtoupper(trim((string) ($r[9] ?? ''))), $err, (string) ($r[0] ?? '')];
        if ($exact !== null && $err === null) {
            $sum += $exact;
        }
    }
    $clientSum = 0;
    $clientCount = 0;
    if ($clientsAt !== null) {
        for ($i = $clientsAt + 1, $n = count($rows); $i < $n; $i++) {
            $r = $rows[$i];
            $client = trim(preg_replace('~\s+~', ' ', (string) ($r[1] ?? '')) ?? '');
            $sh = si_shares_exact((string) ($r[3] ?? ''));
            if ($client === '' || $sh === null) {
                continue;                                                   // the rule lines under the section
            }
            $cnt = si_cell_shares((string) ($r[2] ?? ''));
            $clients[] = [$i + 1, $client, $cnt === null ? null : (int) $cnt, (string) intdiv($sh + 50000, 100000)];
            $clientSum += $sh;
            $clientCount += (int) $cnt;
        }
    }
    if ($out === []) {
        throw new SiParseError('The NOBO list\'s SHARES section has no rows.');
    }
    $failed = count(array_filter($out, static fn ($r) => $r[5] !== null));
    $rowsN = count($out) - $failed;
    $w = static fn (int $x): string => si_shares_words($x);
    $diffs = [];
    if ($headerCount !== null && (int) $headerCount !== $rowsN) {
        $diffs[] = 'the header says ' . number_format((int) $headerCount) . ' NOBOs, the SHARES section has ' . number_format($rowsN) . ' rows';
    }
    if ($summary !== null && $summary['count'] !== null && (int) $summary['count'] !== $rowsN && (int) $summary['count'] !== (int) $headerCount) {
        $diffs[] = 'the summary counts ' . number_format((int) $summary['count']) . ' NOBOs, the SHARES section has ' . number_format($rowsN) . ' rows';
    }
    if ($summary !== null && $summary['shares'] !== null && $summary['shares'] !== $sum) {
        $diffs[] = 'the summary says ' . $w($summary['shares']) . ' shares, the rows add to ' . $w($sum);
    }
    if ($sectionTotal !== null && $sectionTotal !== $sum) {
        $diffs[] = 'the section\'s total says ' . $w($sectionTotal) . ' shares, the rows add to ' . $w($sum);
    }
    if ($clients !== [] && $clientSum !== $sum) {
        $diffs[] = 'the Bank/Broker listing adds to ' . $w($clientSum) . ' shares, the rows to ' . $w($sum);
    }
    if ($failed > 0) {
        $diffs[] = $failed . ' row' . ($failed === 1 ? '' : 's') . ' failed to type';
    }
    return [
        'cusip' => preg_match('~^[0-9A-Z]{9}$~', $cusip) ? $cusip : null, 'record_date' => $rd, 'period' => [$rd, $rd],
        'job' => ['job_number' => $at('JOB NUMBER') ?: null, 'cusip' => $cusip ?: null, 'issuer_name' => preg_replace('~\s+~', ' ', $at('ISSUER NAME')) ?: null, 'record_date' => $rd,
                  'sort_order' => $at('SORT') ?: null, 'nobo_count_header' => $headerCount === null ? null : (int) $headerCount],
        'rows' => $out, 'clients' => $clients,
        'control' => ['layout' => 'job', 'record_date' => $rd, 'nobo_count' => $headerCount === null ? null : (int) $headerCount, 'summary_count' => $summary['count'] ?? null,
                      'summary_shares' => $summary['shares'] === null ? null : si_shares_words($summary['shares'], false), 'section_total' => $sectionTotal === null ? null : si_shares_words($sectionTotal, false),
                      'clients_shares' => $clients === [] ? null : si_shares_words($clientSum, false), 'clients_count' => $clients === [] ? null : $clientCount, 'clients' => count($clients), 'fractional_rows' => $fractional],
        'derived' => ['nobo_count' => $rowsN, 'shares' => si_shares_words($sum, false), 'rows_failed' => $failed],
        'tie' => [$diffs === [], $diffs === [] ? null : ucfirst(implode('; ', $diffs)) . '.'],
    ];
}

/**
 * Shares exactly, in hundred-thousandths ("000461260.00000" → 46126000000000, "39.86928" → 3986928, a workbook's 1606921.8023600001 rounded to
 * five places); null when the cell is not a number of shares. PHP's integers hold a billion shares at this scale; no bcmath is needed.
 */
function si_shares_exact(?string $v): ?int
{
    $v = str_replace([',', ' ', "\u{a0}"], '', trim((string) $v));
    if (preg_match('~^(\d+)(?:\.(\d+))?$~', $v, $m)) {
        $frac = substr(str_pad($m[2] ?? '', 6, '0'), 0, 6);
        return (int) ltrim($m[1], '0') * 100000 + intdiv((int) $frac + 5, 10);
    }
    if (preg_match('~^\d+(\.\d+)?E-?\d+$~i', $v)) {
        return (int) round((float) $v * 100000);
    }
    return null;
}

/** Exact shares in words: "1,606,921.80236" (a whole number without its decimals); $grouped = false for the stored control. */
function si_shares_words(int $exact, bool $grouped = true): string
{
    $whole = intdiv($exact, 100000);
    $frac = rtrim(str_pad((string) ($exact % 100000), 5, '0', STR_PAD_LEFT), '0');
    return ($grouped ? number_format($whole) : (string) $whole) . ($frac === '' ? '' : '.' . $frac);
}
