<?php
declare(strict_types=1);

/**
 * Connector `dtc_spr_onetime_csv` — DTC's ONE TIME Security Position Report (the owner's 05-19-2023 file): "Position as of: MM/DD/YYYY", the
 * security's line under "Security Description … Cusip", a header ("Participant … / Number, Name, Quantity"), one row per participant (the
 * number WITHOUT its leading zeros — 756 is 0756 — the name, the quantity). It carries no Total row: the control is the sum of its rows,
 * recorded as such (the tie then proves the parse, not the file).
 */
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/dtc_spr_csv.php';

function si_parse_dtc_spr_onetime_csv(string $bytes): array
{
    return si_parse_dtc_spr_onetime_rows(si_csv_rows($bytes));
}

function si_parse_dtc_spr_onetime_rows(array $rows): array
{
    $asOf = null;
    foreach (array_slice($rows, 0, 12) as $r) {
        if (preg_match('~Position as of:\s*(\d{1,2}/\d{1,2}/\d{4})~i', implode(',', $r), $m)) {
            $asOf = si_cell_date($m[1]);
        }
    }
    if ($asOf === null) {
        throw new SiParseError('This one-time report has no "Position as of" date.');
    }
    $h = si_find_row($rows, ['Number']);
    if ($h === null) {
        throw new SiParseError('This is not DTC\'s one-time layout: no "Number, Name, Quantity" header.');
    }
    $out = [];
    $sum = '0';
    $numbers = [];
    for ($i = $h + 1, $n = count($rows); $i < $n; $i++) {
        $r = array_values($rows[$i]);
        $num = si_cell_participant((string) ($r[0] ?? ''));
        if ($num === null) {
            continue;
        }
        $cells = array_values(array_filter(array_slice($r, 1), static fn ($c) => trim((string) $c) !== ''));
        $name = '';
        foreach ($cells as $c) {
            if (si_cell_shares($c) === null) { $name = trim(preg_replace('~\s+~', ' ', $c) ?? ''); break; }
        }
        $qtyCell = (string) (end($cells) ?: '');
        $sh = si_cell_shares($qtyCell);
        $err = $sh === null ? 'The quantity is not a number: ' . mb_substr($qtyCell, 0, 30) : null;
        $numbers[$num] = true;
        $out[] = [$i + 1, $num, $name, $asOf, $sh, ['number' => (string) $r[0], 'name' => $name, 'date' => $asOf, 'shares' => $qtyCell, 'column' => 'quantity'], $err];
        if ($sh !== null) { $sum = bcadd_si($sum, $sh); }
    }
    if ($numbers === []) {
        throw new SiParseError('The report lists no participant.');
    }
    return ['cusip' => si_spr_cusip($rows), 'rows' => $out, 'period' => [$asOf, $asOf], 'record_date' => null,
            'control' => ['layout' => 'one_time', 'as_of' => $asOf, 'shares_by_date' => [$asOf => $sum], 'participants_listed' => count($numbers), 'control_is' => 'the sum of the rows (a one-time report carries no Total row)'],
            'notes' => ['A one-time report carries no Total row: it is tied to the sum of its own rows.']];
}

/** Whole-number addition on strings (shares never need floats; bcmath may be absent). */
function bcadd_si(string $a, string $b): string
{
    return function_exists('bcadd') ? bcadd($a, $b, 0) : (string) ((int) $a + (int) $b);
}
