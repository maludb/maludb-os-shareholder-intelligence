<?php
/**
 * Proof: exports (events-reports.md) — each kind builds a CSV as the person who asked (built at once, kept seven days, downloaded after the walls and logged); a holders export needs the Analyst's
 * right to holders, is logged with its row count, carries natural persons' names only when si_settings.pii_in_exports admits the creator (analyst, admin, nobody) and says so with a chip; a Viewer
 * exports nothing and sees no one's; export.shared sees everyone's; a spreadsheet formula in a cell is made text; the worker builds what was left queued as its creator, fails what has no active
 * creator, and removes the file of an export past its seven days (the download then answers 410).
 */
require __DIR__ . '/lib.php';
$ids = seed8();
$alpha = $ids['alpha'];
$sec = $ids['security'];
$jP = person(26);
$jB = person(28);
$jM = person(27);
$jV = person(29);
$jO = person(1);
$json = ['headers' => ['Accept: application/json']];
$imp = (int) one("SELECT id FROM imports WHERE issuer_id = :i AND kind = 'spr' LIMIT 1", ['i' => $alpha]);
// two holders with a register position each (Frank is a natural person, Zeta an entity) and a filing, an event with a formula for a title
foreach (q('SELECT id, display_name FROM holders WHERE issuer_id = :i ORDER BY id', ['i' => $alpha]) as $h) {
    $acc = (int) q("INSERT INTO holder_accounts (holder_id, channel, account_key, registration_text) VALUES (:h, 'register', :k, 'SMOKE registration lines') RETURNING id", ['h' => $h['id'], 'k' => 'acct-' . $h['id']])[0]['id'];
    q("INSERT INTO holder_positions (holder_id, holder_account_id, security_id, record_date, shares, channel, import_id) VALUES (:h, :a, :s, '2024-03-31', :n, 'register', :i)",
        ['h' => $h['id'], 'a' => $acc, 's' => $sec, 'n' => str_contains($h['display_name'], 'Frank') ? 12000 : 480000, 'i' => $imp]);
}
q("INSERT INTO filings (issuer_id, filer_cik, accession_number, form, filed_on, items, primary_document, primary_doc_description) VALUES (:i, '0000999001', '0000999001-24-000009', '8-K', CURRENT_DATE - 5, '{5.02}', 'x.htm', 'A change of officers')", ['i' => $alpha]);
$formula = rid(post_json($jP, '/events/save.php', ['issuer' => $alpha, 'on_date' => date('Y-m-d', strtotime('-2 days')), 'kind' => 'note', 'title' => '=HYPERLINK("http://example.invalid","click")']));
$csv = static function (string $body): array { return array_map('str_getcsv', array_filter(explode("\n", $body), 'strlen')); };
$make = static fn (string $jar, array $form): array => post_json($jar, '/exports/create.php', ['security' => $sec] + $form);
$get = static fn (string $jar, int $id): array => req('GET', '/exports/' . $id, ['jar' => $jar]);

echo "1. Each kind builds a CSV\n";
$since = last_activity_id();
$r = $make($jP, ['kind' => 'events_csv']);
$x1 = rid($r);
$row = q('SELECT * FROM exports WHERE id = :i', ['i' => $x1])[0] ?? [];
ok($r['code'] === 200 && $x1 > 0 && $row['status'] === 'done' && (int) $row['created_by'] === 26 && (int) $row['issuer_id'] === $alpha && (int) $row['security_id'] === $sec && $row['storage_path'] !== null && $row['finished_at'] !== null && $row['includes_pii'] === false
    && (int) $row['item_count'] === (int) one("SELECT count(*) FROM events WHERE issuer_id = :i AND deleted_at IS NULL AND on_date BETWEEN CURRENT_DATE - 90 AND CURRENT_DATE", ['i' => $alpha]) && ($r['json']['rows'] ?? -1) === (int) $row['item_count'],
    'export_create (events): built at once as Priya — done, a stored file, the row count of the last ninety days, no names; record_id the export');
$f = $get($jP, $x1);
$rows = $csv($f['body']);
ok($f['code'] === 200 && stripos($f['headers'], 'text/csv') !== false && stripos($f['headers'], 'attachment') !== false && $rows[0] === ['event_id', 'on_date', 'kind', 'severity', 'title', 'url', 'filing_id', 'source'] && count($rows) - 1 === (int) $row['item_count'],
    'its download: a CSV attachment with a header row and the rows');
ok(in_array("'=HYPERLINK(\"http://example.invalid\",\"click\")", array_column($rows, 4), true), 'a title that begins with = is made text for the spreadsheet (a leading apostrophe)');
$a = activity('export.create', $since)[0] ?? [];
$b = activity('export.build', $since)[0] ?? [];
$d = activity('export.download', $since)[0] ?? [];
ok(json_decode($a['after'], true)['rows'] === (int) $row['item_count'] && json_decode($b['after'], true)['rows'] === (int) $row['item_count'] && json_decode($d['after'], true)['kind'] === 'events_csv' && !str_contains((string) $b['after'], 'HYPERLINK'),
    'export.create, export.build and export.download logged with the kind and the row count');
ok(one('SELECT downloaded_at IS NOT NULL FROM exports WHERE id = :i', ['i' => $x1]) === true, 'the download is recorded on the export');
$kinds = [
    'positions_csv' => [[], ['position_date', 'participant_number', 'participant_name', 'class', 'shares', 'percent_of_dtc'], 3],
    'short_csv' => [['from' => date('Y-m-d', strtotime('-60 days')), 'to' => date('Y-m-d')], ['settlement_date', 'published_on', 'short_shares'], 1],
    'signals_csv' => [[], ['signal_id', 'raised_on', 'rule', 'evidence_class'], 1],
    'filings_csv' => [[], ['filing_id', 'form', 'filed_on', 'accession_number'], 1],
    'institutions_csv' => [[], ['period_end', 'institution', 'kind', 'shares'], 0],
];
$wrong = [];
foreach ($kinds as $kind => [$extra, $head, $n]) {
    $x = $make($jP, ['kind' => $kind] + $extra);
    $b = $get($jP, rid($x));
    $c = $csv($b['body']);
    if (!($x['code'] === 200 && $b['code'] === 200 && array_slice($c[0] ?? [], 0, count($head)) === $head && count($c) - 1 === $n)) { $wrong[] = "$kind → {$x['code']} rows " . (count($c) - 1) . ' wanted ' . $n; }
}
ok($wrong === [], 'five more kinds each build: positions (the three participants), short interest, signals, filings, institutions (none yet — the header alone)' . ($wrong ? ': ' . implode(' | ', $wrong) : ''));
$pc = $csv($get($jP, (int) one("SELECT max(id) FROM exports WHERE kind = 'positions_csv'"))['body']);
ok(count($pc) === 4 && (int) $pc[1][4] === 6000000 && (float) $pc[1][5] === 60.0, 'the positions are ranked: 6,000,000 shares, 60.00% of DTC, on the top row');

echo "2. Validation\n";
$bad = [
    'a kind not on the list' => [['security' => $sec, 'kind' => 'gossip'], 'kind'],
    'no security' => [['kind' => 'events_csv'], 'security'],
    'a security that is not there' => [['security' => 999999, 'kind' => 'events_csv'], 'security'],
    'a period backwards' => [['security' => $sec, 'kind' => 'events_csv', 'from' => '2024-05-01', 'to' => '2024-04-01'], 'to'],
    'a date that is not one' => [['security' => $sec, 'kind' => 'positions_csv', 'record_date' => '31/03/2024'], 'record_date'],
    'a channel not on the list' => [['security' => $sec, 'kind' => 'holders_csv', 'channel' => 'dtc'], 'channel'],
];
$wrong = [];
foreach ($bad as $label => [$form, $field]) {
    $x = post_json($jP, '/exports/create.php', $form);
    if (!($x['code'] === 422 && isset($x['json']['error']['fields'][$field]))) { $wrong[] = "$label → {$x['code']} " . substr($x['body'], 0, 90); }
}
ok($wrong === [], 'six refusals: 422 with the sentence on the field' . ($wrong ? ': ' . implode(' | ', $wrong) : ''));

echo "3. A holders export — logged with its count; names by the setting\n";
$since = last_activity_id();
$r = $make($jP, ['kind' => 'holders_csv', 'channel' => 'register', 'record_date' => '2024-03-31']);
$xh = rid($r);
$rows = $csv($get($jP, $xh)['body']);
$row = q('SELECT * FROM exports WHERE id = :i', ['i' => $xh])[0];
ok($r['code'] === 200 && ($r['json']['rows'] ?? 0) === 2 && ($r['json']['includes_pii'] ?? false) === true && $row['includes_pii'] === true && (int) $row['item_count'] === 2 && $rows[0][2] === 'holder' && count($rows) === 3, 'the Analyst\'s holders export: two rows, includes_pii true');
$names = array_column($rows, 2);
ok(in_array('SMOKE Frank Person', $names, true) && in_array('SMOKE Zeta Holdings LLC', $names, true) && $rows[2][6] === 'Springfield' && (int) $rows[1][10] === 480000, 'a natural person\'s name and city are in it (the default: analyst), ranked by shares');
$b = activity('export.build', $since)[0] ?? [];
$ba = json_decode($b['after'], true);
ok($ba['kind'] === 'holders_csv' && $ba['rows'] === 2 && $ba['includes_pii'] === true && !str_contains((string) $b['after'], 'Frank') && !str_contains((string) $b['after'], 'Springfield'), 'export.build logged the kind, the row count (2) and that names are in it — never a name');
$l = page($jP, '/exports')['body'];
ok(str_contains($l, 'id="export-pii-' . $xh . '"') && str_contains($l, 'contains names') && str_contains($l, 'id="export-download-' . $xh . '"'), 'the list marks it "contains names" and offers the download');
q("UPDATE si_settings SET pii_in_exports = 'nobody' WHERE id = 1");
$xn = rid($make($jP, ['kind' => 'holders_csv', 'channel' => 'register', 'record_date' => '2024-03-31']));
$rows = $csv($get($jP, $xn)['body']);
$names = array_column($rows, 2);
ok(in_array('Individual holder', $names, true) && !in_array('SMOKE Frank Person', $names, true) && in_array('SMOKE Zeta Holdings LLC', $names, true) && $rows[2][6] === '' && one('SELECT includes_pii FROM exports WHERE id = :i', ['i' => $xn]) === false,
    'with pii_in_exports = nobody: the person is "Individual holder" with no city, the entity keeps its name, includes_pii false');
q("UPDATE si_settings SET pii_in_exports = 'admin' WHERE id = 1");
$xa = rid($make($jP, ['kind' => 'holders_csv', 'channel' => 'register', 'record_date' => '2024-03-31']));
$xo = rid($make($jO, ['kind' => 'holders_csv', 'channel' => 'register', 'record_date' => '2024-03-31']));
ok(!in_array('SMOKE Frank Person', array_column($csv($get($jP, $xa)['body']), 2), true) && in_array('SMOKE Frank Person', array_column($csv($get($jO, $xo)['body']), 2), true), 'with pii_in_exports = admin: Priya\'s export masks the person; the admin\'s names them');
q("UPDATE si_settings SET pii_in_exports = 'analyst' WHERE id = 1");

echo "4. Who may — a Viewer exports nothing; the lists are each person's own\n";
$x = post_json($jV, '/exports/create.php', ['security' => $sec, 'kind' => 'events_csv']);
ok($x['code'] === 403 && str_contains($x['body'], 'You may not'), 'a Viewer asks for an export: 403 in words');
$lv = page($jV, '/exports');
ok($lv['code'] === 200 && str_contains($lv['body'], 'id="exports-empty"') && !str_contains($lv['body'], 'id="export-form"'), 'a Viewer\'s list is empty and has no form');
ok($get($jV, $x1)['code'] === 404, 'a Viewer opening Priya\'s export by its number: 404');
$xb = rid($make($jB, ['kind' => 'events_csv']));
ok($get($jP, $xb)['code'] === 404 && !str_contains(page($jP, '/exports?all=1')['body'], 'id="export-row-' . $xb . '"') && !str_contains(page($jP, '/exports')['body'], 'id="exports-all"'), 'Priya cannot open Bea\'s export, and "Everyone\'s" is not offered or honoured for her');
$lm = page($jM, '/exports?all=1')['body'];
ok(str_contains($lm, 'id="export-row-' . $xb . '"') && str_contains($lm, 'id="export-row-' . $x1 . '"') && str_contains($lm, 'id="exports-all"') && $get($jM, $xb)['code'] === 200 && $get($jM, $xh)['code'] === 200, 'the IR Manager (export.shared) lists and downloads everyone\'s');
ok(!str_contains(page($jM, '/exports')['body'], 'id="export-row-' . $xb . '"'), 'and "Mine" is only hers');
$d = json_decode(page($jP, '/exports', $json)['body'], true)['data'] ?? [];
ok(count($d['exports'] ?? []) >= 6 && !array_key_exists('storage_path', $d['exports'][0]) && isset($d['exports'][0]['includes_pii'], $d['exports'][0]['item_count']), 'the list answers JSON without a path');
$ah = agent_headers(42, 971);
$x = agent_post($ah, '/exports/create.php', ['security' => $sec, 'kind' => 'short_csv']);
ok($x['code'] === 200 && (int) one('SELECT created_by FROM exports WHERE id = :i', ['i' => rid($x)]) === 42, 'an agent holding export.own may export (no pause in the manifest); it is the agent\'s export');
$x = post_json($jP, '/exports/create.php', ['security' => (int) one('SELECT id FROM securities WHERE issuer_id = :i', ['i' => $ids['beta']]), 'kind' => 'events_csv']);
ok($x['code'] === 422, 'a security of an issuer behind the wall: refused');

echo "5. The worker — queued exports built as their creator; expired files removed\n";
$qid = (int) q("INSERT INTO exports (kind, format, status, created_by, issuer_id, security_id, params) VALUES ('events_csv', 'csv', 'queued', 26, :i, :s, '{}') RETURNING id", ['i' => $alpha, 's' => $sec])[0]['id'];
$orphan = (int) q("INSERT INTO exports (kind, format, status, created_by, issuer_id, security_id, params) VALUES ('events_csv', 'csv', 'queued', NULL, :i, :s, '{}') RETURNING id", ['i' => $alpha, 's' => $sec])[0]['id'];
$bq = (int) q("INSERT INTO exports (kind, format, status, created_by, issuer_id, security_id, params) VALUES ('holders_csv', 'csv', 'queued', 26, :i, :s, '{\"record_date\": \"2024-03-31\", \"channel\": \"register\"}') RETURNING id", ['i' => $alpha, 's' => $sec])[0]['id'];
$w = worker('exports', date('c'));
$rep = $w['report']['ran']['exports'] ?? [];
$qrow = q('SELECT * FROM exports WHERE id = :i', ['i' => $qid])[0];
ok($w['rc'] === 0 && ($rep['built'] ?? 0) === 2 && ($rep['failed'] ?? 0) === 1 && $qrow['status'] === 'done' && (int) $qrow['item_count'] > 0 && one('SELECT status FROM exports WHERE id = :i', ['i' => $orphan]) === 'failed',
    'the pass built the two queued exports of a person and failed the one with no creator');
$brow = q('SELECT * FROM exports WHERE id = :i', ['i' => $bq])[0];
ok($brow['status'] === 'done' && (int) $brow['item_count'] === 2 && $brow['includes_pii'] === true, 'the queued holders export was built as Priya (the walls read as her — names in it)');
ok((int) one("SELECT count(*) FROM notifications WHERE member_id = 26 AND kind = 'export_ready' AND record_id IN (:a, :b)", ['a' => $qid, 'b' => $bq]) === 2, 'Priya is told both are ready');
ok($get($jP, $qid)['code'] === 200, 'and she downloads one');
q("UPDATE exports SET expires_at = now() - interval '1 hour' WHERE id = :i", ['i' => $x1]);
$path = (string) one('SELECT storage_path FROM exports WHERE id = :i', ['i' => $x1]);
$w = worker('exports', date('c'));
ok(($w['report']['ran']['exports']['expired'] ?? 0) === 1 && one('SELECT storage_path FROM exports WHERE id = :i', ['i' => $x1]) === null && !is_file(need('SI_DEV_STATE') . '/storage/' . $path), 'an export past its seven days: the worker removes its file');
$f = $get($jP, $x1);
ok($f['code'] === 410 && str_contains($f['body'], 'expired'), 'its download answers 410, in words');
ok(str_contains(page($jP, '/exports')['body'], 'expired') && !str_contains(page($jP, '/exports')['body'], 'id="export-download-' . $x1 . '"'), 'and the list says expired, with no Download');

echo "6. The registry\n";
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$ap = array_column(json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json'), true)['approvals'] ?? [], 'category', 'action');
ok(!empty($reg['screens']['export-list']['built']) && !empty($reg['actions']['export_create']['built']) && !isset($ap['export_create']), 'export-list and export_create are built; export_create pauses no agent');
finish();
