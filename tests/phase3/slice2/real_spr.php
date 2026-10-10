<?php
/**
 * Proof: EVERY ONE of the owner's real SPR files (tests/fixtures/real/*SPR*.csv — an SPR names brokers, never a person) uploaded through
 * upload_create as a person would, its connector detected, parsed and loaded — each TIED to its own Total row on every date (the one-time
 * report to the sum of its rows); the holiday weeks load four or five dates; consecutive weeks overlap on the Opening without a difference;
 * every position the Python oracle reads is in the database; the analysis workbook made from SPRs is refused in words
 * (position-reports.md §6).
 */
require __DIR__ . '/lib.php';
[$jP, ] = sign_on(26);
$ref = reference_issuer();
$files = real_spr_files();
ok(count($files) === 70, count($files) . ' real SPR CSVs in the owner\'s folder');

echo "1. Every file uploads and ties\n";
$untied = [];
$dups = [];
$seen = [];
$layouts = ['weekly' => 0, 'one_time' => 0];
$t0 = microtime(true);
foreach ($files as $f) {
    $h = hash_file('sha256', $f);
    $r = upload($jP, $f, ['security' => $ref['security']]);
    if (isset($seen[$h])) {                                              // the owner's folder holds ten files twice, under two names
        if ($r['code'] !== 422 || !str_contains($r['body'], 'already uploaded')) { $dups[] = basename($f) . ' ' . $r['code']; }
        continue;
    }
    $seen[$h] = true;
    $layouts[str_contains((string) file_get_contents($f), 'One Time') ? 'one_time' : 'weekly']++;
    if ($r['code'] !== 200 || ($r['json']['tied'] ?? null) !== true) { $untied[] = basename($f) . ' ' . $r['code'] . ' ' . substr($r['body'], 0, 160); }
}
ok($untied === [], 'all ' . count($seen) . ' distinct files tie to their own totals' . ($untied ? ': ' . implode(' | ', array_slice($untied, 0, 4)) : '') . sprintf(' (%.1f s)', microtime(true) - $t0));
ok($dups === [] && count($files) - count($seen) === 10, 'the ten files the folder holds twice are refused the second time, naming the upload they already are' . ($dups ? ': ' . implode(', ', $dups) : ''));
$c = array_column(q("SELECT connector, count(*) AS n FROM imports WHERE kind = 'spr' GROUP BY connector"), 'n', 'connector');
ok((int) ($c['dtc_spr_csv'] ?? 0) === $layouts['weekly'] && (int) ($c['dtc_spr_onetime_csv'] ?? 0) === $layouts['one_time'] && $layouts['one_time'] >= 1, "the connector was detected from each file's own first lines: {$layouts['weekly']} weekly, {$layouts['one_time']} one-time");
ok((int) one("SELECT count(*) FROM imports WHERE kind = 'spr' AND (status <> 'done' OR NOT tied OR rows_failed > 0)") === 0, 'every upload is done, tied, and no row failed to type');

echo "2. The database holds what the oracle reads\n";
$bad = [];
$total = 0;
$oracleDates = [];
foreach ($files as $f) {
    $tmp = tempnam(sys_get_temp_dir(), 'or');
    $ctrl = json_decode((string) shell_exec('mcp/venv/bin/python -I tests/fixtures/load_real_spr.py ' . escapeshellarg($f) . " $tmp.csv $tmp.json"), true);
    foreach ($ctrl['shares_by_date'] as $d => $v) {
        $oracleDates[$d] = true;
        $db = one('SELECT si_spr_total(:s, CAST(:d AS date))', ['s' => $ref['security'], 'd' => $d]);
        if ((int) $db !== (int) $v) { $bad[] = basename($f) . " $d oracle $v db $db"; }
        $total++;
    }
    @unlink("$tmp.csv"); @unlink("$tmp.json"); @unlink($tmp);
}
ok($bad === [], "on each of $total file-dates the database's DTC total equals the oracle's" . ($bad ? ': ' . implode('; ', array_slice($bad, 0, 4)) : ''));
$dates = (int) one('SELECT count(DISTINCT position_date) FROM participant_positions WHERE security_id = :s', ['s' => $ref['security']]);
ok($dates === count($oracleDates), "$dates distinct position dates loaded — exactly the oracle's (some weeks arrive in more than one file)");

echo "3. The holidays, the overlap, the one-time report\n";
ok(one("SELECT count(*) FROM participant_positions WHERE security_id = :s AND position_date = '2024-06-19'", ['s' => $ref['security']]) === 0
    && (int) one("SELECT count(*) FROM participant_positions WHERE security_id = :s AND position_date = '2024-06-18'", ['s' => $ref['security']]) > 0, 'Juneteenth 2024 is absent (no row), the day before is present');
$hol = q("SELECT i.id, i.control_totals FROM imports i WHERE i.kind = 'spr' AND jsonb_array_length(COALESCE(i.control_totals -> 'absent_dates', '[]'::jsonb)) > 0");
ok(count($hol) >= 3, count($hol) . ' holiday weeks name their absent days, and still tie');
$over = q("SELECT a.id AS earlier, b.id AS later, a.period_to AS d FROM imports a JOIN imports b ON b.period_from = a.period_to AND b.id <> a.id AND b.period_to > b.period_from WHERE a.kind = 'spr' AND b.kind = 'spr' AND a.period_to > a.period_from");
ok(count($over) >= 30, count($over) . ' weeks share their Friday with the next week\'s Opening');
$diffs = 0;
foreach ($over as $o) {
    $ctlA = json_decode((string) one('SELECT control_totals FROM imports WHERE id = :i', ['i' => $o['earlier']]), true)['shares_by_date'][$o['d']] ?? null;
    $ctlB = json_decode((string) one('SELECT control_totals FROM imports WHERE id = :i', ['i' => $o['later']]), true)['shares_by_date'][$o['d']] ?? null;
    if ($ctlA !== null && $ctlB !== null && (string) $ctlA !== (string) $ctlB) { $diffs++; }
}
ok($diffs === 0, '…and the two files agree on the shared day: the overlap replaces it without a difference');
$first = array_unique(array_map(static fn ($o) => min((int) $o['earlier'], (int) $o['later']), $over));
ok((int) one("SELECT count(*) FROM imports WHERE kind = 'spr' AND superseded_by IS NOT NULL AND id = ANY (CAST(:e AS bigint[]))", ['e' => '{' . implode(',', $first) . '}']) === count($first),
    'of each pair the file uploaded first is marked overlapped: the one loaded last owns the shared day (' . count($first) . ')');
$one = q("SELECT * FROM imports WHERE connector = 'dtc_spr_onetime_csv' AND period_from = '2023-05-19'")[0] ?? ['period_from' => null, 'period_to' => null, 'rows_made' => 0, 'control_totals' => '{}'];
ok($one['period_from'] === '2023-05-19' && $one['period_to'] === '2023-05-19' && (int) $one['rows_made'] === 51 && json_decode((string) $one['control_totals'], true)['layout'] === 'one_time', 'the one-time report of 2023-05-19: one date, 51 positions, its zero-padded numbers read');
ok((int) one("SELECT count(*) FROM participants WHERE number = '0756'") === 1 && (int) one("SELECT count(*) FROM participants WHERE number = '756'") === 0, '756 is 0756');

echo "4. What is not DTC's report is refused, in words\n";
$r = upload($jP, REAL . 'SPR - BMNR Analysis 07-07-23.xlsx', ['security' => $ref['security']]);
ok($r['code'] === 422 && str_contains($r['body'], 'workbook made from SPRs'), 'the analysis workbook (a firm per row, no participant numbers): 422 — upload DTC\'s own report');
ok((int) one("SELECT count(*) FROM imports WHERE file_name LIKE '%Analysis%'") === 0, '…and nothing of it is kept');
$r = upload($jP, $files[5], ['security' => $ref['security']]);
ok($r['code'] === 422 && preg_match('~already uploaded on [0-9-]+ \(upload \d+\)~', $r['body']) === 1, 'the same file again: 422, naming the upload it already is');
finish();
