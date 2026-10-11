<?php
declare(strict_types=1);

/**
 * Connector `prices_csv` (short-side.md divergence 3; D13 "a manual CSV"): a daily price series for a ticker no provider serves, uploaded
 * through slice 2's pipeline — "Date,Open,High,Low,Close,Volume" (the columns found by name, in any order; Date as 2024-01-11; Close
 * required, the others optional), one bar a line. The control is the file itself: the bars read against the lines that look like bars, a
 * line whose date or close does not type named. Its rows are upserted into `prices` (a re-upload of the same days changes nothing).
 *   ['cusip' => null, 'record_date' => the last date, 'bars' => [[date, open, high, low, close, volume]], 'control', 'derived', 'tie']
 */
require_once __DIR__ . '/common.php';
require_once dirname(__DIR__) . '/fetch/prices.php';

function si_parse_prices_csv(string $bytes): array
{
    $bars = si_prices_from_csv($bytes);
    if ($bars === null) {
        throw new SiParseError('This is not a price file: the first line must name the columns Date and Close (Date,Open,High,Low,Close,Volume).');
    }
    $lines = array_values(array_filter(array_slice(preg_split('~\r?\n~', trim($bytes)), 1), static fn ($l) => trim($l) !== ''));
    if ($bars === []) {
        throw new SiParseError('The price file has no bar this reads (a date as 2024-01-11 and a close).');
    }
    usort($bars, static fn ($a, $b) => strcmp($a[0], $b[0]));
    $dates = array_column($bars, 0);
    $dups = count($dates) - count(array_unique($dates));
    $failed = count($lines) - count($bars);
    $diffs = [];
    if ($failed > 0) {
        $diffs[] = $failed . ' line' . ($failed === 1 ? '' : 's') . ' did not read as a bar (a date as 2024-01-11 and a close)';
    }
    if ($dups > 0) {
        $diffs[] = $dups . ' date' . ($dups === 1 ? ' is' : 's are') . ' there twice';
    }
    $last = end($dates);
    return ['cusip' => null, 'record_date' => $last, 'period' => [$dates[0], $last], 'bars' => $bars,
            'control' => ['layout' => 'prices', 'lines' => count($lines), 'from' => $dates[0], 'to' => $last],
            'derived' => ['bars' => count($bars), 'rows_failed' => $failed, 'duplicates' => $dups],
            'tie' => [$diffs === [], $diffs === [] ? null : ucfirst(implode('; ', $diffs)) . '.']];
}
