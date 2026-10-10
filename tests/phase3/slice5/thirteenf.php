<?php
/**
 * Proof: the 13F data sets (edgar-and-finra-fetchers.md §5, ownership-forms.md "Proof"): the page read for its zips; the newest two read, oldest first;
 * only the tracked CUSIP's rows kept, a filer's two rows for it summed; the RESTATEMENT amendment replacing Gamma's first figure; the institutions
 * made with their kinds; the quarter's changes (Alpha down, Beta out with its PUT apart, Gamma new); the summary; a second pull reads no zip again;
 * the 13F screens and an institution's page; fetch_now queues the set for the worker.
 */
require __DIR__ . '/lib.php';
[$jP, ] = sign_on(26);
[$jV, ] = sign_on(29);
$sm = smoke_issuer();
foreach ([$jP, $jV] as $j) { page($j, '/?issuer=' . $sm['issuer']); }
edgar_contact(true);
$json = ['headers' => ['Accept: application/json']];
q("INSERT INTO share_counts (security_id, as_of, kind, value, source) VALUES (:s, '2024-03-25', 'outstanding', 17053900, 'filing')", ['s' => $sm['security']]);

echo "1. The data sets\n";
stub_reset();
$since = last_activity_id();
pull('edgar_13f_dataset');
$p = last_pull('edgar_13f_dataset');
$paths = array_column(stub_requests(), 'path');
ok($p['status'] === 'ok' && (int) $p['listings_seen'] === 2 && in_array('/data-research/sec-markets-data/form-13f-data-sets', $paths, true), 'the page read, two data sets read — ' . $p['status'] . ' ' . $p['listings_seen'] . ($p['error'] ? ' — ' . $p['error'] : ''));
$zips = array_values(array_filter($paths, static fn ($x) => str_ends_with($x, '.zip')));
ok($zips === ['/files/structureddata/data/form-13f-data-sets/01dec2023-29feb2024_form13f.zip', '/files/structureddata/data/form-13f-data-sets/01mar2024-31may2024_form13f.zip'], 'oldest first: the prior quarter before the next');
$h = q("SELECT i.name, h.period_end, h.put_call, h.shares, h.value_dollars, h.is_amendment FROM institutional_holdings h JOIN institutions i ON i.id = h.institution_id ORDER BY h.period_end, i.name, h.put_call NULLS FIRST");
ok(count($h) === 5 && array_values(array_unique(array_column($h, 'period_end'))) === ['2023-12-31', '2024-03-31'], 'five rows of the tracked CUSIP over two quarters (every other CUSIP skipped)');
$alpha23 = array_values(array_filter($h, static fn ($r) => $r['name'] === 'SMOKE ALPHA CAPITAL MANAGEMENT LP' && $r['period_end'] === '2023-12-31'))[0] ?? [];
ok((float) ($alpha23['shares'] ?? 0) === 100000.0 && (float) $alpha23['value_dollars'] === 500000.0, 'Alpha\'s two rows for the CUSIP (60,000 sole + 40,000 defined) summed: 100,000 shares, $500,000');
$gamma = array_values(array_filter($h, static fn ($r) => str_starts_with($r['name'], 'SMOKE GAMMA')));
ok(count($gamma) === 1 && (float) $gamma[0]['shares'] === 20000.0 && $gamma[0]['is_amendment'] === true, 'Gamma\'s RESTATEMENT replaced its first 17,000: one row, 20,000, the amendment');
ok(one("SELECT kind FROM institutions WHERE name = 'SMOKE ALPHA CAPITAL MANAGEMENT LP'") === 'hedge_fund' && one("SELECT kind FROM institutions WHERE name = 'SMOKE GAMMA PENSION FUND'") === 'pension'
    && one("SELECT kind FROM institutions WHERE name = 'SMOKE BETA ADVISORS LLC'") === 'investment_adviser' && (int) one("SELECT count(*) FROM institutions WHERE name = 'SMOKE UNRELATED BANK'") === 0,
    'the institutions made with their kinds from their names (hedge fund, pension, adviser); a filer holding no tracked CUSIP not made');
$hl = array_map(static fn ($r) => json_decode((string) $r['after'], true), q("SELECT after FROM activity_log WHERE id > :s AND action = 'holding.load' ORDER BY id", ['s' => $since]));
ok(count($hl) === 2 && $hl[0]['institutions_new'] === 2 && $hl[1]['institutions_new'] === 1 && (int) one("SELECT count(*) FROM activity_log WHERE id > :s AND action = 'institution.create'", ['s' => $since]) === 0,
    'holding.load logged per data set, the new institutions counted in it (2, then Gamma), not logged one by one');
$c = q("SELECT name, state, shares, prior_shares FROM si_13f_changes(:s, '2024-03-31')", ['s' => $sm['security']]);
$st = array_column($c, 'state', 'name');
ok(($st['SMOKE ALPHA CAPITAL MANAGEMENT LP'] ?? '') === 'down' && ($st['SMOKE BETA ADVISORS LLC'] ?? '') === 'out' && ($st['SMOKE GAMMA PENSION FUND'] ?? '') === 'new', 'the changes: Alpha down, Beta out (its PUT is not a holding), Gamma new');
$sum = q("SELECT * FROM si_13f_summary(:s, '2024-03-31')", ['s' => $sm['security']])[0];
ok((int) $sum['holders'] === 2 && (float) $sum['shares'] === 60000.0 && (float) $sum['puts_shares'] === 30000.0, 'the summary: two holders, 60,000 shares; 30,000 under puts apart');

echo "2. A second pull\n";
stub_reset();
pull('edgar_13f_dataset');
ok(array_filter(array_column(stub_requests(), 'path'), static fn ($x) => str_ends_with($x, '.zip')) === [] && (int) last_pull('edgar_13f_dataset')['listings_new'] === 0 && (int) one('SELECT count(*) FROM institutional_holdings') === 5,
    'the page read again, no zip read again (both loaded), nothing new');

echo "3. The screens\n";
$d = json_decode(page($jV, '/ownership/13f', $json)['body'], true)['data'] ?? [];
ok(($d['period'] ?? '') === '2024-03-31' && count($d['holders']) === 2 && $d['holders'][0]['name'] === 'SMOKE ALPHA CAPITAL MANAGEMENT LP' && (float) $d['holders'][0]['pct_of_outstanding'] === 0.235 && count($d['options']) === 1,
    'a Viewer reads the quarter: two holders ranked, Alpha 0.235% of the outstanding, the put apart');
$hv = page($jV, '/ownership/13f')['body'];
ok(str_contains($hv, 'id="thirteenf-table"') && str_contains($hv, 'id="thirteenf-options"') && str_contains($hv, 'id="thirteenf-total"') && str_contains($hv, 's Form 13F data sets, the quarter ending'), 'the page: the ranked table, the total row, the options apart, the source line');
$d = json_decode(page($jV, '/ownership/13f/changes?state=out', $json)['body'], true)['data'] ?? [];
ok(count($d['rows'] ?? []) === 1 && $d['rows'][0]['name'] === 'SMOKE BETA ADVISORS LLC' && ($d['counts']['new'] ?? 0) === 1 && $d['prior'] === '2023-12-31', 'the changes screen (through the vhost\'s rewrite): Beta out; one new');
$alpha = (int) one("SELECT id FROM institutions WHERE name = 'SMOKE ALPHA CAPITAL MANAGEMENT LP'");
$v = page($jV, '/institutions/' . $alpha . '?security=' . $sm['security'])['body'];
ok(str_contains($v, 'id="holdings-chart"') && str_contains($v, '13F data set, period') && str_contains($v, 'id="holdings-table"'), 'an institution\'s page: the holdings by quarter as bars, the source line, the table');
$d = json_decode(page($jV, '/institutions', $json)['body'], true)['data']['institutions'] ?? [];
ok(count($d) === 3 && $d[0]['name'] === 'SMOKE ALPHA CAPITAL MANAGEMENT LP', 'the institutions holding the current security as cards, the largest first');

echo "4. institution_update and fetch_now\n";
$r = post_json($jP, '/institutions/save.php', ['institution' => $alpha, 'kind' => 'activist', 'is_activist' => 'yes', 'website' => 'https://alpha.example.invalid']);
ok($r['code'] === 200 && one('SELECT kind_source FROM institutions WHERE id = :i', ['i' => $alpha]) === 'manual' && one('SELECT is_activist FROM institutions WHERE id = :i', ['i' => $alpha]) === true
    && ($r['json']['fields_changed'] ?? []) === ['kind', 'is_activist', 'website'], 'the kind set by a person (manual from then on), the activist mark, the website — the fields named');
ok(post_json($jP, '/institutions/save.php', ['institution' => $alpha, 'kind' => 'pirate'])['code'] === 422 && post_json($jV, '/institutions/save.php', ['institution' => $alpha, 'notes' => 'x'])['code'] === 403, 'a kind that is not one: 422; a Viewer: 403');
$r = post_json($jP, '/admin/sources/pull.php', ['source' => (int) one("SELECT id FROM sources WHERE connector = 'edgar_13f_dataset'")]);
ok($r['code'] === 200 && ($r['json']['status'] ?? '') === 'queued' && one("SELECT last_ok_at FROM sources WHERE connector = 'edgar_13f_dataset'") === null, 'fetch_now on the 13F set: queued for the worker (a data set is not read in a request)');
finish();
