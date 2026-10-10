<?php
/**
 * Proof: THE OWNER'S REAL FILES through the pipeline, for their LAYOUT (holder-identity.md §6). Every NOBO list (Broadridge's job file as CSV and
 * workbook, the names export), every transfer agent's Shareholder Extract and every Share Range Analysis in tests/fixtures/real/ is uploaded as an
 * Analyst would, in record-date order: each parses and ties to the file's own statements, or is refused in words (a file uploaded twice, a second
 * list of a record date, a password-protected workbook, an analysis made from the lists, a PDF). The matcher runs over every list. Then THE REAL
 * RECONCILIATION, both halves: the SPR of 2024-06-28 and the register of 2024-06-30 (outstanding 49,912,607 = Cede & Co 3,397,800 + the rest of
 * the register; Cede = the SPR total). Then the walls on real data: no person's name in the activity log, in the server log, or in a Viewer's
 * answer; the stored extracts without their never-stored columns.
 * NOTHING OF A ROW IS PRINTED: names are compared in memory, counts are printed. The scratch database is dropped when run.sh ends.
 */
require __DIR__ . '/lib.php';
require_once dirname(__DIR__, 3) . '/app/si/parse/detect.php';
require_once dirname(__DIR__, 3) . '/app/si/storage.php';
require_once dirname(__DIR__, 3) . '/app/features/uploads/load.php';
[$jP, ] = sign_on(26);
[$jM, ] = sign_on(27);
[$jV, ] = sign_on(29);
$ref = reference_issuer();
q("INSERT INTO issuers (name, ticker, exchange) VALUES ('NOCERA, INC.', 'NCRA', 'nasdaq')");
$ncra = (int) one("SELECT id FROM issuers WHERE ticker = 'NCRA'");
q("INSERT INTO securities (issuer_id, name, cusip, ticker, is_primary) VALUES (:i, 'Common Stock', '655186500', 'NCRA', true)", ['i' => $ncra]);
$ncraSec = (int) one('SELECT id FROM securities WHERE issuer_id = :i', ['i' => $ncra]);
foreach ([$jP, $jM, $jV] as $j) { page($j, '/?issuer=' . $ref['issuer']); }

// the files of slice 3's kinds, with the record date each says of itself (read here only to order them and to know which repeat a date)
$files = [];
foreach (glob(REAL . '*') as $f) {
    $b = (string) file_get_contents($f);
    [$c, ] = si_detect_connector($b, basename($f));
    $kind = $c === null ? null : SI_CONNECTORS[$c][0];
    if ($kind === 'spr') { continue; }
    $rd = null;
    if ($c !== null) {
        try { $p = si_parse_file($c, $b); $rd = $p['record_date'] ?? null; } catch (Throwable) { $rd = null; }
        $rd ??= upload_record_date(null, null, preg_replace('~^[0-9a-f-]{36}-~', '', basename($f)));
    }
    $files[] = ['path' => $f, 'connector' => $c, 'kind' => $kind, 'rd' => $rd, 'sha' => hash('sha256', $b), 'ncra' => str_contains(basename($f), 'NCRA')];
}
usort($files, static fn ($a, $b) => strcmp((string) $a['rd'], (string) $b['rd']));
$t0 = microtime(true);
$out = ['tied' => [], 'untied' => [], 'dup_bytes' => 0, 'dup_date' => 0, 'refused' => [], 'other' => []];
$seenSha = [];
$seenDate = [];
foreach ($files as $f) {
    $sec = $f['ncra'] ? $ncraSec : $ref['security'];
    $r = upload($jP, $f['path'], ['security' => $sec, 'kind' => '']);
    $body = (string) ($r['json']['error']['message'] ?? $r['body']);
    $key = $f['kind'] . '|' . $sec . '|' . $f['rd'];
    if ($f['connector'] === null) {
        $out['refused'][] = $r['code'] === 422 ? 'ok' : 'FAIL ' . $r['code'];
        continue;
    }
    if (isset($seenSha[$f['sha']])) {
        $out['dup_bytes'] += ($r['code'] === 422 && str_contains($body, 'already uploaded')) ? 1 : 0;
        if (!($r['code'] === 422 && str_contains($body, 'already uploaded'))) { $out['other'][] = 'a repeated file was not refused as one'; }
        continue;
    }
    $seenSha[$f['sha']] = true;
    if (isset($seenDate[$key])) {
        $out['dup_date'] += ($r['code'] === 422 && str_contains($body, 'is already loaded')) ? 1 : 0;
        if (!($r['code'] === 422 && str_contains($body, 'is already loaded'))) { $out['other'][] = 'a second ' . $f['kind'] . ' of a date was not refused (' . $r['code'] . ')'; }
        continue;
    }
    $seenDate[$key] = true;
    if ($r['code'] !== 200) {
        $out['other'][] = $f['connector'] . ' answered ' . $r['code'] . ': ' . mb_substr($body, 0, 120);
        continue;
    }
    $out[($r['json']['tied'] ?? null) === true ? 'tied' : 'untied'][] = $f['connector'];
}
$secs = round(microtime(true) - $t0);
$t1 = microtime(true);
$mr = match_all();
$msecs = round(microtime(true) - $t1);
$count = static fn (array $l): string => implode(', ', array_map(static fn ($k, $v) => "$k $v", array_keys(array_count_values($l)), array_count_values($l)));

echo "1. Every file of slice 3's kinds\n";
ok($out['other'] === [], 'no file answered anything but a load or a refusal in words' . ($out['other'] ? ': ' . implode(' | ', array_slice($out['other'], 0, 3)) : ''));
ok($out['untied'] === [] && count($out['tied']) >= 100, count($out['tied']) . ' files loaded and TIED to their own statements (' . $count($out['tied']) . ') in ' . $secs . ' s — none untied');
ok($mr['errors'] === [], 'the worker\'s matcher pass matched ' . $mr['uploads'] . ' lists and registers in ' . $msecs . ' s (' . $mr['passes'] . ' passes), oldest record date first');
ok($out['dup_bytes'] >= 1 && $out['dup_date'] >= 1, $out['dup_bytes'] . ' file(s) uploaded twice refused as such; ' . $out['dup_date'] . ' second file(s) of a record date refused naming the first');
ok(count($out['refused']) === 4 && !in_array(true, array_map(static fn ($x) => $x !== 'ok', $out['refused']), true), 'the 4 files no connector reads refused in words (the password-protected workbook, the comparison, the PDF, the SPR analysis)');
$m = q("SELECT sum((derived_totals -> 'matcher' ->> 'auto')::int) AS a, sum((derived_totals -> 'matcher' ->> 'new')::int) AS n, sum((derived_totals -> 'matcher' ->> 'proposed')::int) AS p, sum((derived_totals -> 'matcher' ->> 'failed')::int) AS f,
               count(*) FILTER (WHERE (derived_totals -> 'matcher') IS NOT NULL) AS lists FROM imports WHERE kind IN ('nobo', 'register') AND deleted_at IS NULL")[0];
ok((int) $m['lists'] === count(array_filter($out['tied'], static fn ($c) => !str_contains($c, 'share_range'))) && (int) $m['f'] === 0,
    sprintf('the matcher ran over every list and register: %d matched by the rules, %d new holders, %d proposed, none failed', $m['a'], $m['n'], $m['p']));
ok((int) one("SELECT count(*) FROM holders WHERE issuer_id = :i AND kind = 'person'", ['i' => $ref['issuer']]) > 500 && (int) one("SELECT count(*) FROM holders WHERE issuer_id = :i AND kind = 'entity'", ['i' => $ref['issuer']]) > 10,
    sprintf('%d holders of Bitmine (%d persons, %d entities)', (int) one('SELECT count(*) FROM holders WHERE issuer_id = :i', ['i' => $ref['issuer']]), (int) one("SELECT count(*) FROM holders WHERE issuer_id = :i AND kind = 'person'", ['i' => $ref['issuer']]), (int) one("SELECT count(*) FROM holders WHERE issuer_id = :i AND kind = 'entity'", ['i' => $ref['issuer']])));
$rep = q("SELECT count(DISTINCT h.id) AS h FROM holders h WHERE h.issuer_id = :i AND (SELECT count(DISTINCT record_date) FROM holder_positions WHERE holder_id = h.id AND channel = 'nobo') >= 20", ['i' => $ref['issuer']])[0];
ok((int) $rep['h'] > 100, (int) $rep['h'] . ' holders are on twenty or more of the NOBO lists — the same registration recognised week after week');
$sum = (float) one("SELECT sum(shares_total) FROM nobo_jobs WHERE security_id = :s AND record_date = '2024-06-21'", ['s' => $ref['security']]);
ok($sum > 0 && (float) one("SELECT sum(shares) FROM holder_positions WHERE security_id = :s AND channel = 'nobo' AND record_date = '2024-06-21'", ['s' => $ref['security']])
    + (float) one("SELECT COALESCE(sum(n.shares), 0) FROM nobo_rows n JOIN nobo_jobs j ON j.id = n.job_id WHERE j.security_id = :s AND j.record_date = '2024-06-21' AND n.holder_id IS NULL", ['s' => $ref['security']]) === $sum,
    'on the list of 2024-06-21 the holders\' positions and the rows still waiting add up to the list (' . number_format($sum) . ' shares)');
$obo = q("SELECT count(*) AS n, count(*) FILTER (WHERE participant_id IS NOT NULL) AS matched FROM nobo_broker_rows b JOIN nobo_jobs j ON j.id = b.job_id WHERE j.record_date = '2024-06-21' AND j.security_id = :s", ['s' => $ref['security']])[0];
$up = (int) one("SELECT import_id FROM nobo_jobs WHERE record_date = '2024-06-21' AND security_id = :s", ['s' => $ref['security']]);
$hu = page($jP, '/uploads/' . $up)['body'];
ok((int) $obo['matched'] >= 10 && substr_count($hu, 'not in the directory</span>') === (int) $obo['n'] - (int) $obo['matched'],
    $obo['matched'] . ' of the ' . $obo['n'] . ' brokers of the 2024-06-21 listing matched to a participant (the scratch directory holds ' . one('SELECT count(*) FROM participants') . ' of DTCC\'s ~900); every other one shown as not in the directory — never guessed');

echo "2. THE REAL RECONCILIATION, both halves\n";
$r = upload($jP, glob(REAL . '*SPR_06-28-2024_09175A107*.csv')[0], ['security' => $ref['security'], 'kind' => '']);
ok(($r['json']['tied'] ?? null) === true, 'the SPR of the week ending 2024-06-28: tied');
$reg = (int) one("SELECT id FROM imports WHERE kind = 'register' AND security_id = :s AND record_date = '2024-06-30'", ['s' => $ref['security']]);
$regTotal = (float) one('SELECT sum(shares) FROM register_rows WHERE import_id = :i AND error IS NULL', ['i' => $reg]);
ok($regTotal === 49912607.0 && (float) one('SELECT sum(shares) FROM register_rows WHERE import_id = :i AND is_cede', ['i' => $reg]) === 3397800.0, 'the register of 2024-06-30: 49,912,607 shares, Cede & Co 3,397,800');
post_json($jM, '/issuers/share-counts/save.php', ['security' => $ref['security'], 'as_of' => '2024-06-30', 'kind' => 'outstanding', 'value' => '49,912,607', 'source' => 'transfer_agent']);
$r = post_json($jP, '/reconciliation/run.php', ['security' => $ref['security'], 'as_of' => '2024-06-30']);
$rec = q('SELECT * FROM share_reconciliations WHERE id = :i', ['i' => (int) ($r['json']['record_id'] ?? 0)])[0] ?? [];
ok(($r['json']['state'] ?? '') === 'tied' && $rec['cede_source'] === 'register' && $rec['tied_registered'] === true && (float) $rec['difference_registered'] === 0.0 && $rec['tied_spr'] === true && (float) $rec['difference_spr'] === 0.0 && $rec['spr_date'] === '2024-06-28',
    'IT TIES ON BOTH HALVES: outstanding 49,912,607 = Cede 3,397,800 + the register\'s rest; Cede on the register = the SPR total of 2024-06-28, to the share');
ok($rec['nobo_date'] === '2024-06-21' && (float) $rec['obo_remainder'] === 3397800.0 - (float) $rec['nobo_shares'], sprintf('the NOBO side: %s NOBO shares on 2024-06-21; the OBO remainder %s', number_format((float) $rec['nobo_shares']), number_format((float) $rec['obo_remainder'])));

echo "3. The walls on real data\n";
$names = array_map('strtoupper', array_column(q("SELECT display_name FROM holders WHERE kind = 'person' AND length(display_name) >= 8"), 'display_name'));
$lines = array_column(q('SELECT DISTINCT upper(line1) AS l FROM nobo_rows WHERE line1 IS NOT NULL AND length(line1) >= 8 UNION SELECT DISTINCT upper(split_part(registration_name, E\'\n\', 1)) FROM register_rows WHERE NOT is_cede AND length(registration_name) >= 8'), 'l');
$hits = (int) one("SELECT count(*) FROM (SELECT upper(v #>> '{}') AS v FROM activity_log, jsonb_path_query(COALESCE(after, '{}') || COALESCE(before, '{}'), 'strict $.**') v WHERE jsonb_typeof(v) = 'string') x
                    WHERE x.v IN (SELECT upper(display_name) FROM holders WHERE kind = 'person') OR x.v IN (SELECT upper(line1) FROM nobo_rows) OR x.v IN (SELECT upper(split_part(registration_name, E'\n', 1)) FROM register_rows WHERE NOT is_cede)");
ok(count($names) > 500 && $hits === 0, 'no value anywhere in the activity log is a person\'s name or a registration line (' . number_format((int) one('SELECT count(*) FROM activity_log')) . ' lines, ' . number_format(count($names)) . ' names)');
$log = strtoupper((string) @file_get_contents(need('SI_DEV_STATE') . '/app.log'));
$inLog = 0;
foreach (array_slice($names, 0, 3000) as $n) { if (str_contains($log, $n)) { $inLog++; } }
ok($inLog === 0, 'no person\'s name in the server log');
$jsonText = static fn (string $b): string => (string) json_encode(json_decode($b, true), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$vj = $jsonText((string) page($jV, '/holders?security=' . $ref['security'], ['headers' => ['Accept: application/json']])['body']) . $jsonText((string) page($jV, '/holders/changes?security=' . $ref['security'], ['headers' => ['Accept: application/json']])['body'])
    . html_entity_decode(page($jV, '/holders?security=' . $ref['security'])['body'] . page($jV, '/holders/register?security=' . $ref['security'] . '&record_date=2024-06-30')['body'] . page($jV, '/uploads/' . $reg)['body'], ENT_QUOTES | ENT_HTML5);
$ents = array_map('strtoupper', array_column(q("SELECT display_name FROM holders WHERE kind <> 'person'"), 'display_name'));
usort($ents, static fn ($x, $y) => strlen($y) <=> strlen($x));
$bare = str_replace($ents, ' ', strtoupper($vj));                         // an entity's own name is shown by design (D4), even when it holds a person's
$firms = array_map('strtoupper', array_merge(array_column(q('SELECT DISTINCT client FROM nobo_broker_rows'), 'client'), array_column(q('SELECT name FROM participants'), 'name')));
usort($firms, static fn ($x, $y) => strlen($y) <=> strlen($x));
$bare2 = str_replace($firms, ' ', $bare);                                  // a broker of the Bank/Broker listing is public (a Viewer reads the listing)
$inV = 0;
$firmHits = [];
foreach ($names as $n) {
    if (str_contains($bare2, $n)) { $inV++; }
    elseif (str_contains($bare, $n)) { foreach ($firms as $f) { if (str_contains($f, $n)) { $firmHits[$f] = true; } } }
}
if ($firmHits !== []) { echo '   note: holders read as persons whose name is a broker\'s (a custodian line taken for the name): ', implode(' | ', array_keys($firmHits)), "\n"; }
ok($inV === 0 && str_contains($vj, 'Individual holder'), 'a Viewer\'s holder list, changes, register and upload pages name no person ("Individual holder") — outside an entity\'s own name');
$leak = 0;
foreach (q("SELECT a.storage_path FROM attachments a JOIN imports i ON i.attachment_id = a.id WHERE i.connector IN ('ta_extract_csv', 'broadridge_nobo_names_xlsx')") as $a) {
    $s = (string) file_get_contents(need('SI_STORAGE_DIR') . '/' . $a['storage_path']);
    if (preg_match('~TAX-ID|DATE OF BIRTH|PHONE NUMBER|EMAIL ADDRESS|\*\*\*-\*\*-|@[a-z0-9-]+\.[a-z]{2,}|Work email|Mobile phone|Linkedin~i', $s)) { $leak++; }
}
ok($leak === 0, 'every stored extract and names export is without its tax ids, dates of birth, phones, emails and profiles');
finish();
