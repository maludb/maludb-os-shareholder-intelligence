<?php
declare(strict_types=1);

/**
 * Connector `stc_position_detail` — Securities Transfer Corporation's "Security Holder List - Position Detail" workbook (the reference file,
 * anonymised as tests/fixtures/register_stc.xlsx): a title, a summary of positions per section ("844 | Common"), the record date, then
 * sections by title (Common, Common 2 …) each under a header row (Position ID, Position Type, Holder ID, Registration Name(s), Mailing
 * Address, City, State, Country, Zip Code, Issue Date, # Of Securities, Cost Basis, Description of Restriction, % Owned, Employee, Officer
 * / Director, US Citizen — the seeded template's columns B to R). Every section is loaded with its title (the preferred classes too — a
 * register per class is Extended). **The file carries no total**: the summary's counts (1, 14, 844 "Common" on the reference file, against
 * 842 positions) are not a count of the positions and are shown, not compared — the upload is tied to its own rows, as DTC's one-time report is.
 */
require_once __DIR__ . '/common.php';

function si_parse_stc_position_detail(string $bytes): array
{
    $rows = si_file_rows($bytes);
    if (!str_contains(strtoupper(si_rows_text($rows, 5)), 'POSITION DETAIL')) {
        throw new SiParseError('This is not STC\'s Security Holder List - Position Detail.');
    }
    $summary = [];
    $rd = null;
    $section = null;
    $out = [];
    $bySection = [];
    $inData = false;
    $rowNo = 0;
    foreach ($rows as $i => $r) {
        $a = trim((string) ($r[0] ?? ''));
        $b = trim((string) ($r[1] ?? ''));
        if (!$inData && preg_match('~^\d+$~', $a) && $b !== '' && !isset($r[2])) {
            $summary[$b] = ($summary[$b] ?? 0) + (int) $a;                // the summary: positions per section
            continue;
        }
        if ($rd === null && ($d = si_cell_date($a)) !== null && implode('', array_slice($r, 1)) === '') {
            $rd = $d;
            continue;
        }
        if (stripos($b, 'Position') === 0 && stripos((string) ($r[2] ?? ''), 'Position') === 0) {
            $inData = true;                                                 // a section's header row
            continue;
        }
        if (($b !== '' && !isset($r[2]) && !preg_match('~^\d+$~', $b)) || (strcasecmp($a, 'COMMON') === 0 && $b === '')) {
            $section = $b !== '' ? $b : 'Common';                           // a section's title
            continue;
        }
        if (!$inData || $b === '' || !isset($r[4])) {
            continue;
        }
        $section ??= 'Common';
        $sh = si_cell_shares((string) ($r[11] ?? ''));
        $yes = static fn ($v): ?bool => in_array(strtoupper(trim((string) $v)), ['Y', 'YES'], true) ? true : (in_array(strtoupper(trim((string) $v)), ['N', 'NO'], true) ? false : null);
        $num = static fn ($v): ?string => is_numeric(str_replace(',', '', (string) $v)) ? str_replace(',', '', (string) $v) : null;
        $out[] = ['row_no' => ++$rowNo, 'section' => $section, 'position_id' => $b, 'position_type' => trim((string) ($r[2] ?? '')) ?: null, 'holder_ref' => trim((string) ($r[3] ?? '')) ?: null,
                  'registration_name' => trim((string) $r[4]) ?: null, 'address_line' => trim((string) ($r[5] ?? '')) ?: null, 'city' => trim((string) ($r[6] ?? '')) ?: null,
                  'state' => trim((string) ($r[7] ?? '')) ?: null, 'country' => trim((string) ($r[8] ?? '')) ?: null, 'zip' => trim((string) ($r[9] ?? '')) ?: null,
                  'issue_date' => si_cell_date(preg_replace('~\.\d+$~', '', trim((string) ($r[10] ?? ''))) ?? ''), 'shares' => $sh, 'cost_basis' => $num($r[12] ?? ''),
                  'restriction_text' => trim((string) ($r[13] ?? '')) ?: null, 'percent_owned' => $num($r[14] ?? ''), 'is_employee' => $yes($r[15] ?? ''),
                  'is_officer_director' => $yes($r[16] ?? ''), 'is_us_citizen' => $yes($r[17] ?? ''), 'raw' => ['line' => $i + 1],
                  'error' => $sh === null ? 'The number of securities is not a number: ' . mb_substr((string) ($r[11] ?? ''), 0, 20) : null];
        $bySection[$section] = ($bySection[$section] ?? 0) + 1;
    }
    if ($out === []) {
        throw new SiParseError('The position detail lists no position.');
    }
    $diffs = [];
    $listed = array_sum($summary);
    $failed = count(array_filter($out, static fn ($r) => $r['error'] !== null));
    if ($failed > 0) {
        $diffs[] = $failed . ' row' . ($failed === 1 ? '' : 's') . ' failed to type';
    }
    $sum = array_sum(array_map(static fn ($r) => (int) $r['shares'], $out));
    return ['cusip' => null, 'record_date' => $rd, 'period' => [$rd, $rd], 'rows' => $out,
            'control' => ['layout' => 'stc_position_detail', 'summary_listed' => $listed, 'by_section' => $bySection, 'summary' => $summary, 'control_is' => 'the sum of the rows (the file carries no total)'],
            'derived' => ['rows' => count($out), 'shares' => $sum, 'rows_failed' => $failed],
            'tie' => [$diffs === [], $diffs === [] ? null : ucfirst(implode('; ', $diffs)) . '.'], 'needs_record_date' => $rd === null];
}
