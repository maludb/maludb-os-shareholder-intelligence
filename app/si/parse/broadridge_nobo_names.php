<?php
declare(strict_types=1);

/**
 * Connector `broadridge_nobo_names_xlsx` — Broadridge's newer NOBO export (the owner's 12-03-24 workbook): a header row naming First name,
 * Middle name, Last name, Last active date, Shares, Address, City, State, Country, ZIP code, ZIP code + 4, Custodian — and Work email,
 * Personal email, Work phone, Home phone, Mobile phone, Facebook / LinkedIn / Twitter URL, which are NEVER stored (si_names_export_kept()
 * writes the stored copy without them: a CSV of the kept columns, read by this same parser). The name lines become `line1` (FIRST MIDDLE
 * LAST), `line2` the address, `line3` CITY ST ZIP; the custodian is matched to a participant by name. Control: the footer's =SUM (its cached
 * value) against the rows. No record date in the file (the upload's, or the file name's) and no CLIENTS section: the Bank/Broker listing
 * is absent and the OBO remainder for the date is not computed (the upload's page says so).
 */
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/broadridge_nobo.php';

const SI_NAMES_KEEP = ['FIRST NAME', 'MIDDLE NAME', 'LAST NAME', 'LAST ACTIVE DATE', 'SHARES', 'ADDRESS', 'CITY', 'STATE', 'COUNTRY', 'ZIP CODE', 'ZIP CODE + 4', 'CUSTODIAN'];

/** The header row's index and its columns by name (upper-cased). */
function si_names_header(array $rows): array
{
    for ($i = 0, $n = min(count($rows), 20); $i < $n; $i++) {
        $u = array_map(static fn ($c): string => strtoupper(trim((string) $c)), $rows[$i]);
        if (in_array('FIRST NAME', $u, true) && in_array('LAST NAME', $u, true) && in_array('SHARES', $u, true)) {
            return [$i, array_flip($u)];
        }
    }
    throw new SiParseError('This is not Broadridge\'s NOBO names export: no "First name … Last name … Shares" header.');
}

/** The stored copy: the kept columns only, as CSV (the footer's total kept). Contact columns never reach the store. */
function si_names_export_kept(string $bytes): string
{
    $rows = si_file_rows($bytes);
    [$h, $cols] = si_names_header($rows);
    $idx = array_values(array_filter(array_map(static fn (string $k) => $cols[$k] ?? null, SI_NAMES_KEEP), static fn ($v) => $v !== null));
    $fh = fopen('php://memory', 'r+');
    for ($i = $h, $n = count($rows); $i < $n; $i++) {
        fputcsv($fh, array_map(static fn (int $c): string => (string) ($rows[$i][$c] ?? ''), $idx), ',', '"', '');
    }
    rewind($fh);
    $out = (string) stream_get_contents($fh);
    fclose($fh);
    return $out;
}

function si_parse_broadridge_nobo_names_xlsx(string $bytes): array
{
    $rows = si_file_rows($bytes);
    [$h, $cols] = si_names_header($rows);
    $c = static fn (array $r, string $k): string => isset($cols[$k]) ? trim(preg_replace('~\s+~', ' ', (string) ($r[$cols[$k]] ?? '')) ?? '') : '';
    $out = [];
    $sum = 0;
    $footer = null;
    $fractional = 0;
    for ($i = $h + 1, $n = count($rows); $i < $n; $i++) {
        $r = $rows[$i];
        if ($r === [] || implode('', $r) === '') {
            continue;
        }
        $name = trim(implode(' ', array_filter([$c($r, 'FIRST NAME'), $c($r, 'MIDDLE NAME'), $c($r, 'LAST NAME')], static fn ($x) => $x !== '')));
        $exact = si_shares_exact($c($r, 'SHARES'));
        if ($name === '' && $c($r, 'ADDRESS') === '' && $exact !== null) {
            $footer = $exact;                                              // the =SUM footer (its cached value)
            continue;
        }
        $zip = $c($r, 'ZIP CODE');
        $country = strtoupper($c($r, 'COUNTRY'));
        $us = $country === '' || str_starts_with($country, 'UNITED STATES') || $country === 'US' || $country === 'USA';
        if ($us && preg_match('~^\d{3,4}$~', $zip)) {
            $zip = str_pad($zip, 5, '0', STR_PAD_LEFT);                    // a workbook drops a New England zip's leading zero
        } elseif ($us && preg_match('~^\d{9}$~', $zip)) {
            $zip = substr($zip, 0, 5);
        }
        $csz = trim(implode(' ', array_filter([$c($r, 'CITY'), $c($r, 'STATE'), $zip], static fn ($x) => $x !== '')));
        $lines = [$name, $c($r, 'ADDRESS'), $csz, $us ? '' : $c($r, 'COUNTRY'), '', '', ''];
        $err = $exact === null ? 'The shares are not a number: ' . mb_substr($c($r, 'SHARES'), 0, 20) : ($name === '' ? 'The row has no name.' : null);
        if ($exact !== null && $exact % 100000 !== 0) {
            $fractional++;
        }
        $out[] = [$i + 1, $exact === null ? null : (string) intdiv($exact + 50000, 100000), $lines, $zip, null, $err, $c($r, 'SHARES'), $c($r, 'CUSTODIAN') ?: null, $us ? 'US' : $country];
        if ($exact !== null && $err === null) {
            $sum += $exact;
        }
    }
    if ($out === []) {
        throw new SiParseError('The names export lists no holder.');
    }
    $failed = count(array_filter($out, static fn ($r) => $r[5] !== null));
    $diffs = [];
    if ($footer !== null && $footer !== $sum) {
        $diffs[] = 'the footer\'s total says ' . si_shares_words($footer) . ' shares, the rows add to ' . si_shares_words($sum);
    }
    if ($failed > 0) {
        $diffs[] = $failed . ' row' . ($failed === 1 ? '' : 's') . ' failed to type';
    }
    return [
        'cusip' => null, 'record_date' => null, 'period' => [null, null],
        'job' => ['job_number' => null, 'cusip' => null, 'issuer_name' => null, 'record_date' => null, 'sort_order' => null, 'nobo_count_header' => null],
        'rows' => $out, 'clients' => [],
        'control' => ['layout' => 'names', 'footer_shares' => $footer === null ? null : si_shares_words($footer, false), 'fractional_rows' => $fractional, 'clients' => 0,
                      'no_clients' => 'The names export carries no Bank/Broker listing: the OBO remainder for this date is not computed.'],
        'derived' => ['nobo_count' => count($out) - $failed, 'shares' => si_shares_words($sum, false), 'rows_failed' => $failed],
        'tie' => [$diffs === [] && $footer !== null, $diffs !== [] ? ucfirst(implode('; ', $diffs)) . '.' : ($footer === null ? 'The export has no total to tie it to.' : null)],
        'needs_record_date' => true,
    ];
}
