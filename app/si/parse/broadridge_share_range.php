<?php
declare(strict_types=1);

/**
 * Connector `broadridge_share_range_xls` — Broadridge's Share Range Analysis (the owner's 47 workbooks; old Excel, read by app/si/xls.php): two
 * sheets — "Share Range - Holder Count" (every account, pre-suppression: population `all`) and "OBO Count" (the objecting owners: `obo`) —
 * each a RANGE / ACCTS / SHARES table down to its TOTALS row, and a footer "JOB: N96192 CUSIP: 09175A ISSUER: …" and "RECORD DATE:
 * 12/15/2023" (one writes "11 /19/ 2024"). No person anywhere. Control: each sheet's rows against its TOTALS (accounts and shares). The footer's CUSIP is six
 * characters: it is checked against the security's first six.
 */
require_once __DIR__ . '/common.php';
require_once dirname(__DIR__) . '/xls.php';

function si_parse_broadridge_share_range_xls(string $bytes): array
{
    try {
        if (str_starts_with($bytes, "PK\x03\x04")) {
            require_once dirname(__DIR__) . '/xlsx.php';
            $sheets = si_xlsx_sheets($bytes);                              // the same analysis saved as .xlsx
        } else {
            $sheets = si_xls_sheets($bytes);
        }
    } catch (SiXlsError $e) {
        throw new SiParseError($e->getMessage());
    }
    $pops = [];
    $job = null;
    $cusip6 = null;
    $rd = null;
    $diffs = [];
    foreach ($sheets as $name => $rows) {
        $u = strtoupper($name);
        $pop = str_contains($u, 'OBO') ? 'obo' : (str_contains($u, 'HOLDER COUNT') || str_contains($u, 'SHARE RANGE') ? 'all' : null);
        if ($pop === null) {
            continue;
        }
        $h = si_find_row($rows, ['RANGE', 'ACCTS', 'SHARES']);
        if ($h === null) {
            continue;
        }
        $list = [];
        $tot = null;
        for ($i = $h + 1, $n = count($rows); $i < $n; $i++) {
            $cells = array_values(array_filter($rows[$i], static fn ($c) => trim((string) $c) !== ''));
            if ($cells === []) {
                continue;
            }
            $label = trim((string) $cells[0]);
            if (strcasecmp($label, 'TOTALS') === 0) {
                $tot = ['accounts' => (int) si_cell_shares((string) ($cells[1] ?? '')), 'shares' => (int) si_cell_shares((string) ($cells[2] ?? ''))];
                continue;
            }
            if (preg_match('~JOB:\s*(\S+).*CUSIP:\s*(\S+)~i', $label, $m)) {
                $job ??= $m[1];
                $cusip6 ??= strtoupper($m[2]);
                continue;
            }
            if (preg_match('~RECORD DATE:\s*([\d/ ]+)~i', $label, $m)) {
                $rd ??= si_cell_date(str_replace(' ', '', $m[1]));              // one footer reads "11 /19/ 2024"
                continue;
            }
            if (preg_match('~^([\d,]+)\s*-\s*([\d,]+)$~', $label, $m)) {
                [$from, $to] = [(int) str_replace(',', '', $m[1]), (int) str_replace(',', '', $m[2])];
            } elseif (preg_match('~^([\d,]+)\s*\+$~', $label, $m)) {
                [$from, $to] = [(int) str_replace(',', '', $m[1]), null];
            } else {
                continue;
            }
            $list[] = ['label' => $label, 'from' => $from, 'to' => $to, 'accounts' => (int) si_cell_shares((string) ($cells[1] ?? '0')), 'shares' => (int) si_cell_shares((string) ($cells[2] ?? '0'))];
        }
        if ($list === []) {
            continue;
        }
        $acc = array_sum(array_column($list, 'accounts'));
        $sh = array_sum(array_column($list, 'shares'));
        $word = $pop === 'obo' ? 'the OBO sheet' : 'the holder-count sheet';
        if ($tot === null) {
            $diffs[] = $word . ' has no TOTALS row';
        } elseif ($tot['accounts'] !== $acc || $tot['shares'] !== $sh) {
            $diffs[] = $word . '\'s TOTALS say ' . number_format($tot['accounts']) . ' accounts and ' . number_format($tot['shares']) . ' shares, its rows ' . number_format($acc) . ' and ' . number_format($sh);
        }
        $pops[$pop] = ['rows' => $list, 'totals' => $tot, 'accounts' => $acc, 'shares' => $sh];
    }
    if (!isset($pops['all'])) {
        throw new SiParseError('This is not Broadridge\'s Share Range Analysis: no "Share Range - Holder Count" sheet with RANGE / ACCTS / SHARES.');
    }
    if ($rd === null) {
        throw new SiParseError('The Share Range Analysis has no RECORD DATE in its footer.');
    }
    return ['cusip' => null, 'cusip6' => $cusip6, 'record_date' => $rd, 'period' => [$rd, $rd], 'job_number' => $job, 'populations' => $pops, 'rows' => [],
            'control' => ['layout' => 'share_range', 'job' => $job, 'cusip6' => $cusip6, 'record_date' => $rd,
                          'all' => $pops['all']['totals'], 'obo' => $pops['obo']['totals'] ?? null],
            'derived' => ['all' => ['accounts' => $pops['all']['accounts'], 'shares' => $pops['all']['shares']], 'obo' => isset($pops['obo']) ? ['accounts' => $pops['obo']['accounts'], 'shares' => $pops['obo']['shares']] : null],
            'tie' => [$diffs === [], $diffs === [] ? null : ucfirst(implode('; ', $diffs)) . '.']];
}
