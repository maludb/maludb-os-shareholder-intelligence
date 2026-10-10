<?php
declare(strict_types=1);

/**
 * Connector `dtc_spr_csv` — DTC's WEEKLY Security Position Report as DTC sends it (position-reports.md §2; design §0.2b). The layout of the
 * owner's 70 files (2023–2025): title rows ("Weekly Subscription Security Position Report", "Daily Closing Balances", "For Week Ending
 * MM/DD/YYYY"); "Security Description,Cusip" over the security's line; the two-row header ("Participant,Participant,Opening,Monday…Friday,
 * Share,Percent" / "Number,Name,<the Opening's date: the previous Friday>,<five dates>,Change,Change"); one row per participant (a four-digit
 * number, DTC's abbreviated name, six balances, the change); the "Total" row — THE CONTROL: the file's own total on each date.
 * A balance of -9999999999999 (the Total row) or "Holiday" (a participant's) is an ABSENT day: no row, no control. Unpivoted: one row per
 * participant per date present. tests/fixtures/load_real_spr.py is the oracle the proof compares with.
 */
require_once __DIR__ . '/common.php';

function si_parse_dtc_spr_csv(string $bytes): array
{
    return si_parse_dtc_spr_grid(si_csv_rows($bytes));
}

/** The security's CUSIP: the cell under the header "Cusip" (either layout), upper-cased; null when the file names none. */
function si_spr_cusip(array $rows): ?string
{
    foreach ($rows as $i => $r) {
        $c = array_search('cusip', array_map('strtolower', $r), true);
        if ($c !== false && str_starts_with(strtolower((string) ($r[0] ?? '')), 'security description')) {
            for ($j = $i + 1, $n = min(count($rows), $i + 4); $j < $n; $j++) {
                $v = strtoupper(trim((string) ($rows[$j][$c] ?? '')));
                if (preg_match('~^[0-9A-Z]{9}$~', $v)) {
                    return $v;
                }
            }
        }
    }
    return null;
}

/** The weekly grid, from rows of cells (a CSV's or DTC's workbook's). */
function si_parse_dtc_spr_grid(array $rows): array
{
    $h = si_find_row($rows, ['Participant', 'Participant']);
    if ($h === null || !isset($rows[$h + 1])) {
        throw new SiParseError('This is not DTC\'s weekly layout: no "Participant, Participant, Opening …" header.');
    }
    $labels = array_map('strtolower', $rows[$h]);
    $cols = [];
    foreach (['opening', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday'] as $day) {
        $c = array_search($day, $labels, true);
        if ($c === false) {
            throw new SiParseError('The header has no ' . ucfirst($day) . ' column.');
        }
        $d = si_cell_date((string) ($rows[$h + 1][$c] ?? ''));
        if ($d === null) {
            throw new SiParseError('The ' . ucfirst($day) . ' column has no date under it.');
        }
        $cols[$c] = [$day, $d];
    }
    $changeCol = array_search('share', $labels, true);
    $out = [];
    $total = null;
    $numbers = [];
    for ($i = $h + 2, $n = count($rows); $i < $n; $i++) {
        $r = $rows[$i];
        $first = trim((string) ($r[0] ?? ''));
        if (strcasecmp($first, 'Total') === 0) {
            $total = $r;
            continue;
        }
        $num = si_cell_participant($first);
        if ($num === null) {
            continue;                                                     // a blank line, a page's repeated title
        }
        $name = trim(preg_replace('~\s+~', ' ', (string) ($r[1] ?? '')) ?? '');
        $numbers[$num] = true;
        foreach ($cols as $c => [$day, $date]) {
            $cell = (string) ($r[$c] ?? '');
            if (si_cell_absent($cell)) {
                continue;
            }
            $sh = si_cell_shares($cell);
            $err = $sh === null ? 'The ' . $day . ' balance is not a number: ' . mb_substr($cell, 0, 30) : ($sh[0] === '-' ? 'The ' . $day . ' balance is negative.' : null);
            $out[] = [$i + 1, $num, $name, $date, $err === null ? $sh : null, ['number' => $first, 'name' => $name, 'date' => $date, 'shares' => $cell, 'column' => $day], $err];
        }
    }
    if ($numbers === []) {
        throw new SiParseError('The report lists no participant.');
    }
    $control = ['layout' => 'weekly', 'shares_by_date' => [], 'absent_dates' => []];
    if ($total === null) {
        $control['no_total'] = true;
    } else {
        foreach ($cols as $c => [$day, $date]) {
            $cell = (string) ($total[$c] ?? '');
            if (si_cell_absent($cell)) {
                $control['absent_dates'][] = $date;
            } elseif (($v = si_cell_shares($cell)) !== null) {
                $control['shares_by_date'][$date] = $v;
            }
        }
        if ($changeCol !== false && ($v = si_cell_shares((string) ($total[$changeCol] ?? ''))) !== null) {
            $control['share_change_total'] = $v;
        }
    }
    $text = si_rows_text($rows, 12);
    if (preg_match('~Week Ending\s+(\d{1,2}/\d{1,2}/\d{4})~i', $text, $m)) {
        $control['week_ending'] = si_cell_date($m[1]);
    }
    $control['participants_listed'] = count($numbers);
    $dates = array_column($cols, 1);
    return ['cusip' => si_spr_cusip($rows), 'rows' => $out, 'control' => $control, 'period' => [min($dates), max($dates)], 'record_date' => null,
            'notes' => $total === null ? ['The file has no Total row: nothing to tie it to.'] : []];
}
