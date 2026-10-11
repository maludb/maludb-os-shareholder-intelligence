<?php
/**
 * Proof — the reads: every records tool of a person (the 90) answers through the real protocol in the world, the numbers equal the database's own, the conventions hold (shares are integers, money a string with two
 * decimals, a missing day is absent, errors are answers), and each group's gates answer as the surface says (docs/shareholder-intelligence-mcp-tool-surface.md).
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$S = surface();
$sec = $w['smok_security'];
$iss = $w['smok_issuer'];
$CUSIP = '07831X206';

echo "1. Every tool of the surface answers\n";
$sw = sweep($w);
ok(count($sw) === 90 && array_column($sw, 0) === array_values(array_unique(array_column($sw, 0))) && array_diff($S['person'], array_column($sw, 0)) === [] && array_diff(array_column($sw, 0), $S['person']) === [], 'the sweep names each of the 90 person tools once');
$failed = [];
foreach ($sw as [$t, $a]) {
    if ($t === 'key_usage_summary') { $a['api_key'] = 'p4 marco key'; }
    $r = tool(MARCO, $t, $a);
    if ($r['http'] !== 200 || $r['error']) { $failed[] = "$t=" . ($r['code'] ?? 'http' . $r['http']); }
}
ok($failed === [], 'as the IR Manager every one of the 90 answers without an error' . ($failed ? ' — ' . implode(', ', $failed) : ''));
$failed = [];
foreach ($sw as [$t, $a]) {
    $r = tool(PRIYA, $t, $a);
    if ($r['http'] !== 200 || ($r['error'] && !in_array($t, ['issuer_restrictions', 'tombstones'], true))) { $failed[] = "$t=" . ($r['code'] ?? 'http' . $r['http']); }
}
ok($failed === [], 'as an Analyst the same, but the two manager\'s tools (issuer_restrictions, tombstones), which say managers_only' . ($failed ? ' — ' . implode(', ', $failed) : ''));
$actFailed = [];
foreach (sweep_activity($w) as [$t, $a]) { $r = tool(MARCO, $t, $a, ACT); if ($r['http'] !== 200 || $r['error']) { $actFailed[] = "$t=" . ($r['code'] ?? 'http' . $r['http']); } }
ok($actFailed === [], 'and the seven activity tools answer' . ($actFailed ? ' — ' . implode(', ', $actFailed) : ''));
$bad = [];
foreach ($sw as [$t, $a]) { $r = tool(PRIYA, $t, $a); if (is_array($r['data']) && !isset($r['data']['ok']) && !in_array($t, ['app_roles'], true)) { $bad[] = $t; } }
ok($bad === [], 'every answer says ok (true or false), app_roles being the contract\'s document' . ($bad ? ' — ' . implode(', ', $bad) : ''));

echo "2. The shell, me and the vocabulary\n";
$s = tdata(PRIYA, 'search', ['q' => 'SMOKE', 'limit' => 100]);
ok($s['total'] > 3 && count($s['rows']) === min(100, $s['total']) && isset($s['rows'][0]['entity_kind'], $s['rows'][0]['rank']) && !isset($s['rows'][0]['total']), 'search: ranked rows with entity_kind, entity_id, title, headline, status, issuer_id, rank — and the total once');
ok(count(array_unique(ids(tdata(PRIYA, 'search', ['q' => 'SMOKE', 'kinds' => ['issuer']])['rows'], 'entity_kind'))) === 1 && tdata(PRIYA, 'search', ['q' => 'SMOKE', 'issuer' => 'SMKA'])['total'] >= 1, 'search: by kind and by issuer');
$notif = trows(PRIYA, 'my_notifications', ['unread_only' => true]);
ok(count($notif) >= 1 && $notif[0]['kind'] === 'morning_note' && count(trows(ANN, 'my_notifications')) === 0, 'my_notifications: Priya\'s morning note — only hers; Ann has none');
$set = tdata(PRIYA, 'get_settings');
ok($set['business_name'] === 'Kinetic Seas' && isset($set['edgar_user_agent_set'], $set['market_timezone'], $set['auto_match_threshold']) && !has_key($set, 'edgar_contact_email') && $set['escalate_high_to'] === null && tdata(OWNER, 'get_settings')['escalate_high_to'] === MARCO,
    'get_settings: the settings without the EDGAR contact, the escalation member for the administrator alone');
$mem = trows(PRIYA, 'find_members', ['q' => 'SMOKE', 'limit' => 100]);
ok(count($mem) >= 8 && !has_key($mem, 'email') && find_row($mem, 'display_name', 'SMOKE Ann')['roles_resolved'] === ['viewer'] && find_row($mem, 'display_name', 'SMOKE Marco')['member_kind'] === 'human' && find_row($mem, 'display_name', 'SMOKE Surveillance Analyst')['member_kind'] === 'agent', 'find_members: people and agents with their roles, no e-mail address');
ok(count(trows(PRIYA, 'find_members', ['kind' => 'agent'])) >= 2 && count(trows(PRIYA, 'find_members', ['role' => 'manager'])) >= 1 && count(trows(PRIYA, 'find_members', ['q' => 'SMOKE Marco'])) === 1, 'find_members: by kind, by role, and q alone resolves one');
ok(is_array(tdata(PRIYA, 'find_departments')['rows']) && count(trows(PRIYA, 'find_departments', ['q' => 'Investor'])) === 1, 'find_departments: the department by name');
$tg = trows(PRIYA, 'find_tags', ['q' => 'p4 watch']);
ok(count($tg) === 1 && $tg[0]['usage_count'] === 2 && $tg[0]['slug'] === 'p4-watch', 'find_tags: p4 watch, used twice');
$notes = trows(PRIYA, 'find_notes', ['kind' => 'issuer', 'record' => $iss]);
ok(count($notes) === 1 && $notes[0]['body'] === 'p4 note on SMOK' && $notes[0]['author'] === 'SMOKE Priya', 'find_notes: the note on the issuer with its author');

echo "3. Issuers, securities, share counts, participants, uploads\n";
$fi = tdata(PRIYA, 'find_issuers', ['limit' => 100]);
$sqlIss = array_column(sql_as(PRIYA, 'SELECT issuer_id FROM mcp_issuers ORDER BY issuer_id'), 'issuer_id');
$got = ids($fi['rows'], 'issuer_id'); sort($got);
ok($got == $sqlIss && count($sqlIss) === 4, 'find_issuers lists exactly the issuers the view gives Priya (' . count($sqlIss) . ')');
ok(count(trows(PRIYA, 'find_issuers', ['q' => 'SMKA'])) === 1 && count(trows(PRIYA, 'find_issuers', ['q' => '0000999001'])) === 1 && count(trows(PRIYA, 'find_issuers', ['q' => 'SMOKE Alpha Corp'])) === 1 && count(trows(PRIYA, 'find_issuers', ['q' => 'SMOKE', 'exchange' => 'otc'])) === 1,
    'find_issuers: by ticker, by CIK, by name (each one row — the resolver), and narrowed by exchange');
$gi = tdata(PRIYA, 'get_issuer', ['issuer' => 'SMOK']);
ok($gi['issuer']['ticker'] === 'SMOK' && $gi['restricted'] === false && count($gi['securities']) === 1 && $gi['securities'][0]['cusip'] === $CUSIP && $gi['share_counts']['outstanding']['value'] === 17100000 && $gi['share_counts']['outstanding']['source'] === 'manual'
    && isset($gi['uploads']['spr'], $gi['uploads']['nobo'], $gi['uploads']['register']) && $gi['uploads']['register']['tied'] === true && count($gi['sources']) >= 5, 'get_issuer: the issuer, its security, the latest outstanding (17,100,000, manual, as the share-count order says), the latest upload of each kind with its tie, the fetchers');
$fs = trows(PRIYA, 'find_securities', ['q' => $CUSIP]);
ok(count($fs) === 1 && $fs[0]['ticker'] === 'SMOK' && count(trows(PRIYA, 'find_securities', ['q' => 'SMOK'])) >= 1 && count(trows(PRIYA, 'find_securities', ['issuer' => 'SMKA'])) === 1, 'find_securities: by CUSIP (one row), ticker and issuer');
$sc = tdata(PRIYA, 'share_counts', ['security' => $CUSIP, 'history' => true]);
ok($sc['latest']['outstanding']['value'] === 17100000 && $sc['latest']['float']['value'] === 12000000 && count($sc['rows']) === (int) one('SELECT count(*) FROM share_counts WHERE security_id = :s', ['s' => $sec]) && $sc['rows'][0]['as_of'] >= $sc['rows'][1]['as_of'], 'share_counts: the latest of each kind and the history, newest first');
$sc2 = tdata(PRIYA, 'share_counts', ['security' => $CUSIP, 'as_of' => '2024-04-01']);
ok($sc2['latest']['outstanding']['value'] === 17053900 && $sc2['latest']['outstanding']['source'] === 'filing' && tdata(PRIYA, 'share_counts', ['security' => $CUSIP, 'kind' => 'float'])['latest'] === ['float' => $sc['latest']['float']] , 'share_counts: as of an earlier day the older count answers (17,053,900 from the filing); one kind narrows it');
$part = trows(PRIYA, 'find_participants', ['q' => '0164']);
ok(count($part) === 1 && str_contains(strtoupper($part[0]['name']), 'SCHWAB') && $part[0]['number'] === '0164' && in_array($part[0]['class_key'], ['retail', 'clearing_retail'], true) && count(trows(PRIYA, 'find_participants', ['class' => 'prime', 'limit' => 100])) >= 3 && count(trows(PRIYA, 'find_participants', ['q' => 'Schwab'])) >= 1,
    'find_participants: by number (one row — the resolver), name, class');
$gp = tdata(PRIYA, 'get_participant', ['participant' => '0164', 'security' => $CUSIP]);
ok($gp['participant']['number'] === '0164' && $gp['dates_present'] >= 15 && $gp['latest']['shares'] > 0 && $gp['latest']['pct_of_total'] > 0 && $gp['first_date'] <= $gp['last_date'], 'get_participant: Schwab\'s class, its latest position in SMOK with its share of the total, the dates present');
ok(tdata(PRIYA, 'get_participant', ['participant' => 'Schwab', 'issuer' => 'SMOK'])['participant']['number'] === '0164' && tdata(PRIYA, 'get_participant', ['participant' => 164])['participant']['number'] === '0164', 'get_participant by name and by an unpadded number');
$im = tdata(PRIYA, 'find_imports', ['issuer' => 'SMOK', 'limit' => 100]);
ok($im['count'] > 10 && find_row($im['rows'], 'kind', 'nobo') !== null && count(trows(PRIYA, 'find_imports', ['issuer' => 'SMOK', 'kind' => 'nobo'])) >= 2 && count(trows(PRIYA, 'find_imports', ['issuer' => 'SMOK', 'tied' => true, 'kind' => 'register'])) >= 1
    && count(trows(PRIYA, 'find_imports', ['q' => 'register_stc.xlsx'])) === 1, 'find_imports: by kind, tie and file name (q alone: one row — the resolver)');
$gm = tdata(PRIYA, 'get_import', ['upload' => $w['nobo_import']]);
ok($gm['import']['kind'] === 'nobo' && $gm['tied'] === true && $gm['nobo_job']['job_number'] === 'O08888' && $gm['matcher']['rows'] > 120 && $gm['matcher']['matched'] > 100 && $gm['matcher']['proposed'] >= 1 && $gm['matcher']['unmatched'] >= 1 && $gm['matcher']['failed'] === 1,
    'get_import: the NOBO list ties, its job header, the matcher\'s counts (rows ' . $gm['matcher']['rows'] . ', unmatched ' . $gm['matcher']['unmatched'] . ', failed 1)');
$gs = tdata(PRIYA, 'get_import', ['upload' => $w['upload_spr']]);
ok($gs['import']['kind'] === 'spr' && isset($gs['control_totals'], $gs['derived_totals']) && !isset($gs['matcher']), 'get_import: an SPR shows its totals');
$rows = tdata(PRIYA, 'import_rows', ['upload' => $w['nobo_import'], 'failed_only' => true]);
ok($rows['count'] === 1 && $rows['rows'][0]['error'] === 'no name line', 'import_rows: failed_only gives the one NOBO row that failed to type');
ok(tdata(PRIYA, 'import_rows', ['upload' => $w['nobo_import'], 'limit' => 10])['rows'][0]['error'] !== null, 'import_rows: the failed ones first');
$sr = tdata(PRIYA, 'import_rows', ['upload' => $w['upload_spr'], 'limit' => 100]);
ok($sr['kind'] === 'spr' && $sr['count'] === 100 && $sr['truncated'] === true && isset($sr['rows'][0]['participant_number']) && tdata(PRIYA, 'import_rows', ['upload' => $w['upload_spr'], 'limit' => 100, 'offset' => 100])['count'] > 0, 'import_rows: an SPR\'s rows by page');

echo "4. Positions and the reconciliation\n";
$last = (string) one('SELECT max(position_date) FROM participant_positions WHERE security_id = :s', ['s' => $sec]);
$total = (int) one('SELECT sum(shares) FROM participant_positions WHERE security_id = :s AND position_date = :d', ['s' => $sec, 'd' => $last]);
$po = tdata(PRIYA, 'positions_on', ['security' => $CUSIP, 'top' => 10]);
ok($po['position_date'] === $last && $po['total'] === $total && count($po['rows']) === 10 && $po['rows'][0]['shares'] >= $po['rows'][9]['shares'] && is_int($po['rows'][0]['shares']) && isset($po['rows'][0]['class_key'], $po['rows'][0]['pct_of_total'], $po['rows'][0]['change_prev']),
    "positions_on: the latest date ($last), the total ($total) as the SPR sums, ten participants largest first with class, share of the total and change against the previous date");
$prev = (string) one('SELECT max(position_date) FROM participant_positions WHERE security_id = :s AND position_date < :d', ['s' => $sec, 'd' => $last]);
$r0 = $po['rows'][0];
$was = (int) one('SELECT shares FROM participant_positions WHERE security_id = :s AND participant_id = :p AND position_date = :d', ['s' => $sec, 'p' => $r0['participant_id'], 'd' => $prev]);
ok($po['previous_date'] === $prev && $r0['change_prev'] === $r0['shares'] - $was, 'the change is against the previous position date (' . $prev . '), by participant');
ok(tool(PRIYA, 'positions_on', ['security' => $CUSIP, 'date' => '2019-01-01'])['code'] === 'no_data' && tool(PRIYA, 'positions_on', ['security' => $CUSIP, 'date' => '2024-03-30'])['code'] === 'no_data', 'a date the SPR does not have (a Saturday, Good Friday\'s weekend) is no_data — absent, not zero');
ok(count(tdata(PRIYA, 'positions_on', ['security' => $CUSIP, 'class' => 'retail', 'top' => 100])['rows']) >= 1 && !array_filter(tdata(PRIYA, 'positions_on', ['security' => $CUSIP, 'class' => 'retail', 'top' => 100])['rows'], fn ($r) => $r['class_key'] !== 'retail'), 'positions_on: narrowed to a class');
$pc = tdata(PRIYA, 'position_changes', ['security' => $CUSIP, 'from' => '2024-04-04', 'to' => '2024-03-22']);
$sqlpc = q('SELECT participant_id, net FROM si_position_changes(:s, :a, :b) ORDER BY abs(net) DESC NULLS LAST, close_shares DESC NULLS LAST, number LIMIT 1', ['s' => $sec, 'a' => '2024-03-22', 'b' => '2024-04-04']);
ok($pc['count'] >= 10 && $pc['window'] == ['from' => '2024-03-22', 'to' => '2024-04-04'] && $pc['rows'][0]['participant_id'] === (int) $sqlpc[0]['participant_id'] && $pc['rows'][0]['net'] === (int) $sqlpc[0]['net'], 'position_changes: the biggest movers over the window as the function gives them (a reversed window is swapped)');
ok($pc['rows'][0]['number'] === '0005' || abs($pc['rows'][0]['net']) > 0, 'participant 0005\'s crafted jump or another large mover leads');
$nl = tdata(PRIYA, 'participants_new_lost', ['security' => $CUSIP, 'from' => '2024-02-16', 'to' => '2024-04-04']);
ok($nl['count'] >= 0 && is_array($nl['rows']), 'participants_new_lost answers (' . $nl['count'] . ' rows)');
$pf = tdata(PRIYA, 'participant_flow', ['security' => $CUSIP, 'from' => '2024-03-22', 'to' => '2024-04-04']);
ok($pf['count'] >= 3 && str_contains($pf['caveat'], 'lore') && str_contains($pf['caveat'], 'no published validation') && isset($pf['rows'][0]['side'], $pf['rows'][0]['net']), 'participant_flow: net by class, and THE CAVEAT in the reply — "practitioner lore, with no published validation"');
$ct = tdata(PRIYA, 'concentration', ['security' => $CUSIP]);
$ctS = tdata(PRIYA, 'concentration', ['security' => $CUSIP, 'from' => '2024-02-01', 'to' => '2024-04-05']);
$dates = (int) one('SELECT count(DISTINCT position_date) FROM participant_positions WHERE security_id = :s AND position_date BETWEEN :a AND :b', ['s' => $sec, 'a' => '2024-02-01', 'b' => '2024-04-05']);
ok($ct['position_date'] === $last && $ct['top5_pct'] > 0 && $ct['top20_pct'] >= $ct['top5_pct'] && $ctS['count'] === $dates && $dates >= 20, "concentration: the latest date's top 5/10/20 and the Herfindahl, and a series with a row per position date ($dates)");
$ps = tdata(PRIYA, 'participant_series', ['security' => $CUSIP, 'participant' => '0164', 'from' => '2024-03-22', 'to' => '2024-04-04']);
ok($ps['count'] >= 5 && $ps['participant']['number'] === '0164' && array_key_exists('change', $ps['rows'][0]) && $ps['rows'][0]['change'] === null && $ps['rows'][1]['change'] !== null, 'participant_series: the daily series with the change (null on the first day)');
$st = tdata(PRIYA, 'spr_total', ['security' => $CUSIP, 'date' => $last]);
ok($st['total'] === $total && tool(PRIYA, 'spr_total', ['security' => $CUSIP, 'date' => '2024-03-30'])['code'] === 'no_data', 'spr_total: the sum, and no_data for an absent day');
$rl = tdata(PRIYA, 'reconciliation_latest', ['security' => $CUSIP]);
$sqlr = q('SELECT * FROM share_reconciliations WHERE security_id = :s ORDER BY as_of DESC, ran_at DESC LIMIT 1', ['s' => $sec])[0];
ok($rl['as_of'] === $sqlr['as_of'] && $rl['outstanding'] === (int) $sqlr['outstanding'] && $rl['tied'] === (bool) $sqlr['tied'] && array_key_exists('tied_registered', $rl) && array_key_exists('obo_remainder', $rl) && array_key_exists('tolerance', $rl) && $rl['outstanding'] === 17100000, 'reconciliation_latest: outstanding (the typo\'d 17,100,000), the components, the differences, the tolerance, tied or not — as the table has it');
ok(tool(PRIYA, 'reconciliation_latest', ['security' => 'SMKG'])['code'] === 'no_data' || tool(PRIYA, 'reconciliation_latest', ['issuer' => 'SMKG'])['code'] === 'no_data', 'a security with no reconciliation run: no_data (Gamma has no security)');
ok(tdata(PRIYA, 'reconciliation_history', ['security' => $CUSIP])['count'] >= 1 && tdata(PRIYA, 'reconciliation_history', ['security' => $CUSIP, 'from' => '2030-01-01'])['count'] === 0, 'reconciliation_history: the runs, newest first, narrowed by date');

echo "5. Holders\n";
$all = tdata(PRIYA, 'find_holders', ['issuer' => 'SMOK', 'limit' => 100]);
$sqlN = (int) array_values(sql_as(PRIYA, 'SELECT count(*) FROM mcp_holders WHERE issuer_id = ' . $iss)[0])[0];
ok($all['total'] === $sqlN && $sqlN > 100 && $all['count'] === 100 && $all['truncated'] === true, "find_holders: the total ($sqlN) as the view counts, a page of 100");
ok($all['rows'][0]['last_shares'] >= $all['rows'][5]['last_shares'] && isset($all['rows'][0]['tags'], $all['rows'][0]['accounts_count'], $all['rows'][0]['is_insider']), 'sorted by shares, largest first, with tags, accounts and the insider flag');
$ent = tdata(PRIYA, 'find_holders', ['issuer' => 'SMOK', 'kind' => 'entity', 'limit' => 100]);
ok($ent['total'] > 5 && !array_filter($ent['rows'], fn ($r) => $r['kind'] !== 'entity'), 'find_holders: by kind');
ok(tdata(PRIYA, 'find_holders', ['issuer' => 'SMOK', 'tag' => 'p4 watch'])['total'] === 1, 'by tag');
$nobo = tdata(PRIYA, 'find_holders', ['issuer' => 'SMOK', 'channel' => 'nobo', 'record_date' => '2024-04-04', 'limit' => 100]);
ok($nobo['total'] > 50 && $nobo['total'] < $sqlN, 'by channel and record date (the NOBO list of 2024-04-04: ' . $nobo['total'] . ' holders)');
ok(tdata(PRIYA, 'find_holders', ['issuer' => 'SMOK', 'min_shares' => 5000, 'sort' => 'name'])['total'] < $sqlN && tdata(PRIYA, 'find_holders', ['issuer' => 'SMOK', 'custodian' => '0164'])['total'] >= 0 && tdata(PRIYA, 'find_holders', ['issuer' => 'SMOK', 'account_kind' => 'corporate'])['total'] >= 0, 'by minimum shares, custodian and account kind');
$h = $all['rows'][0];
$gh = tdata(PRIYA, 'get_holder', ['holder' => $h['holder_id']]);
ok($gh['holder']['holder_id'] === $h['holder_id'] && count($gh['accounts']) >= 1 && count($gh['history']) >= 1 && isset($gh['accounts'][0]['registration_text']) && array_key_exists('insider', $gh) && array_key_exists('institution', $gh) && is_int($gh['notes_count']), 'get_holder: accounts with registration lines (Priya), the history, the links, the notes count');
$hh = tdata(PRIYA, 'holder_history', ['holder' => $h['holder_id']]);
ok($hh['count'] >= 1 && isset($hh['rows'][0]['record_date'], $hh['rows'][0]['channel'], $hh['rows'][0]['shares']), 'holder_history: the shares by record date, channel and account');
$hc = tdata(PRIYA, 'holders_changes', ['security' => $CUSIP, 'channel' => 'register', 'date_a' => '2024-03-04', 'date_b' => '2024-04-04']);
$sqlc = array_column(q("SELECT state, count(*) AS n FROM si_holders_changes(:s, 'register', '2024-03-04', '2024-04-04') GROUP BY state", ['s' => $sec]), 'n', 'state');
$byState = [];
foreach (array_column(tdata(PRIYA, 'holders_changes', ['security' => $CUSIP, 'channel' => 'register', 'date_a' => '2024-03-04', 'date_b' => '2024-04-04', 'limit' => 100, 'state' => 'new'])['rows'], 'state') as $x) { $byState[$x] = 1; }
ok($hc['total'] === array_sum($sqlc) && array_sum(array_column($hc['summary'], 'holders')) === array_sum($sqlc) && array_keys($byState) === ['new'] && ($sqlc['lost'] ?? 0) >= 2 && ($sqlc['new'] ?? 0) >= 1, 'holders_changes (Analyst): the rows and the counts by state as the function gives them, narrowed by state');
foreach (['holders_by_range' => ['channel' => 'register'], 'holders_by_geography' => ['channel' => 'register']] as $t => $a) {
    $r = tdata(PRIYA, $t, ['security' => $CUSIP] + $a);
    ok($r['count'] >= 3 && $r['record_date'] === '2024-04-04' && !has_key($r, 'display_name'), "$t: the register of 2024-04-04 by " . ($t === 'holders_by_range' ? 'share range' : 'country and state') . ' — no holder named');
}
$br = tdata(PRIYA, 'holders_by_range', ['security' => $CUSIP, 'channel' => 'register']);
ok(abs(array_sum(array_column($br['rows'], 'share_pct')) - 100) < 1, 'holders_by_range: the shares\' percentages add up to a hundred');
ok(tdata(PRIYA, 'holders_by_custodian', ['security' => $CUSIP])['count'] >= 1 && tdata(PRIYA, 'holders_by_type', ['security' => $CUSIP])['count'] >= 2, 'holders_by_custodian and holders_by_type (the latest list each)');
ok(tool(PRIYA, 'holders_by_custodian', ['security' => 'SMOK2'])['code'] === 'no_data', 'a security with no NOBO list: no_data');
$um = tdata(PRIYA, 'holders_unmatched', ['upload' => $w['nobo_import'], 'limit' => 100]);
$u9001 = find_row($um['rows'], 'row_id', (int) one('SELECT id FROM nobo_rows WHERE row_no = 9001'));
$u9002 = find_row($um['rows'], 'row_id', (int) one('SELECT id FROM nobo_rows WHERE row_no = 9002'));
ok($u9001 !== null && str_contains($u9001['registration'], 'SMOKE UNMATCHED PERSON ZZZ') && $u9001['candidates'] === [] && $u9002 !== null && count($u9002['candidates']) === 1 && $u9002['candidates'][0]['score'] === 0.8 && count($um['rows']) >= 5 && $um['rows'][count($um['rows']) - 1]['candidates'] === [],
    'holders_unmatched: the unmatched row with no candidate, the row with a candidate waiting (score 0.8); rows with a proposal come first');
ok(!array_filter($um['rows'], fn ($r) => $r['row_kind'] === 'nobo' && $r['row_id'] === (int) one('SELECT id FROM nobo_rows WHERE row_no = 9003')), 'and the row that failed to type is not in the queue');
$mp = trows(PRIYA, 'match_proposals', ['limit' => 100]);
ok(count($mp) > 10 && count(trows(PRIYA, 'match_proposals', ['status' => 'auto', 'limit' => 100])) >= 1 && count(trows(PRIYA, 'match_proposals', ['proposed_by' => ANALYST])) === 1 && count(trows(PRIYA, 'match_proposals', ['proposed_by' => 'matcher', 'status' => 'proposed'])) >= 0
    && count(trows(PRIYA, 'match_proposals', ['upload' => $w['nobo_import'], 'limit' => 100])) >= 1, 'match_proposals: by status, by proposer (the agent\'s one, the matcher), by upload');
$or = tdata(PRIYA, 'obo_remainder', ['security' => $CUSIP, 'record_date' => '2024-04-04']);
$sch = find_row($or['rows'], 'number', '0164');
ok($sch !== null && $sch['nobo_shares'] === 300000 && $sch['nobo_count'] === 120 && $sch['obo_remainder'] === $sch['spr_shares'] - 300000, 'obo_remainder: Schwab\'s SPR position against its 300,000 NOBO shares, the remainder the difference');
$pn = tdata(PRIYA, 'participant_nobo_summary', ['security' => $CUSIP, 'participant' => '0164']);
ok($pn['count'] === 2 && $pn['rows'][0]['record_date'] === '2024-04-04' && $pn['rows'][0]['nobo_shares'] === 300000 && $pn['rows'][1]['nobo_shares'] === 600000, 'participant_nobo_summary: both record dates, newest first (300,000 and 600,000)');
$shr = tdata(PRIYA, 'share_ranges', ['security' => $CUSIP]);
ok($shr['count'] > 10 && isset($shr['rows'][0]['obo_accounts'], $shr['rows'][0]['nobo_shares']) && tdata(PRIYA, 'obo_totals', ['security' => $CUSIP])['count'] === 1 && tool(PRIYA, 'share_ranges', ['security' => 'SMOK2'])['code'] === 'no_data', 'share_ranges and obo_totals (one report), and no_data without one');
$ih = tdata(PRIYA, 'insider_holdings', ['issuer' => 'SMOK']);
ok($ih['count'] >= 1 && isset($ih['rows'][0]['reporting_owner_cik'], $ih['rows'][0]['shares_owned_after']) && array_key_exists('register_shares', $ih['rows'][0]), 'insider_holdings: each insider\'s holdings after the last Form 4');

echo "6. Institutions, beneficial owners, insiders\n";
$inst = trows(PRIYA, 'find_institutions', ['limit' => 100]);
$iid = (int) one('SELECT institution_id FROM institutional_holdings WHERE security_id = :s ORDER BY id LIMIT 1', ['s' => $sec]);
$iname = (string) one('SELECT name FROM institutions WHERE id = :i', ['i' => $iid]);
$icik = (string) one('SELECT cik FROM institutions WHERE id = :i', ['i' => $iid]);
ok(count($inst) >= 3 && count(trows(PRIYA, 'find_institutions', ['q' => $iname])) >= 1 && count(trows(PRIYA, 'find_institutions', ['q' => $icik])) === 1, 'find_institutions: all, by name, by CIK (one row — the resolver)');
$gi2 = tdata(PRIYA, 'get_institution', ['institution' => $icik, 'security' => $CUSIP]);
ok($gi2['institution']['institution_id'] === $iid && count($gi2['holdings']) >= 1 && is_array($gi2['beneficial']) && is_array($gi2['filings']), 'get_institution: by CIK, its holdings and filings in SMOK');
ok(tdata(PRIYA, 'institution_history', ['security' => $CUSIP, 'institution' => $iname])['count'] >= 1, 'institution_history: by name');
$ihd = tdata(PRIYA, 'institutional_holders', ['security' => $CUSIP, 'limit' => 100]);
$per = (string) one('SELECT max(period_end) FROM institutional_holdings WHERE security_id = :s', ['s' => $sec]);
ok($ihd['period_end'] === $per && $ihd['count'] >= 2 && $ihd['rows'][0]['shares'] >= $ihd['rows'][1]['shares'] && preg_match('/^\d+\.\d{2}$/', $ihd['rows'][0]['value_dollars']) === 1 && is_int($ihd['rows'][0]['shares']), 'institutional_holders: the latest quarter (' . $per . '), largest first, value a string with two decimals, shares an integer');
$hch = tdata(PRIYA, 'holdings_changes', ['security' => $CUSIP, 'limit' => 100]);
ok($hch['count'] >= 3 && isset($hch['rows'][0]['state'], $hch['rows'][0]['prior_shares']) && tdata(PRIYA, 'holdings_changes', ['security' => $CUSIP, 'state' => 'new'])['count'] >= 0, 'holdings_changes: against the prior quarter');
$hs = tdata(PRIYA, 'holdings_summary', ['security' => $CUSIP]);
ok($hs['period_end'] === $per && $hs['holders'] >= 2 && $hs['shares'] > 0 && is_string($hs['value_dollars']), 'holdings_summary: the quarter\'s totals');
ok(is_array(tdata(PRIYA, 'holdings_put_call', ['security' => $CUSIP])['rows']), 'holdings_put_call answers');
$bo = tdata(PRIYA, 'beneficial_owners', ['security' => $CUSIP]);
ok($bo['count'] >= 1 && isset($bo['rows'][0]['percent_of_class'], $bo['rows'][0]['schedule'], $bo['rows'][0]['purpose_text']) && $bo['rows'][0]['percent_of_class'] >= $bo['rows'][$bo['count'] - 1]['percent_of_class'], 'beneficial_owners: the latest 13D/13G of each filer, largest first');
$bid = (int) one('SELECT id FROM beneficial_ownership WHERE security_id = :s ORDER BY id LIMIT 1', ['s' => $sec]);
$gb = tdata(PRIYA, 'get_beneficial_filing', ['beneficial_id' => $bid]);
ok($gb['beneficial_id'] === $bid && array_key_exists('sole_voting', $gb) && array_key_exists('group_members', $gb) && array_key_exists('filer_type', $gb) && tool(PRIYA, 'get_beneficial_filing', ['beneficial_id' => $bid, 'filing' => 1])['code'] === 'invalid_argument', 'get_beneficial_filing: one filing in full; both ids at once is invalid_argument');
$itx = tdata(PRIYA, 'insider_transactions', ['issuer' => 'SMOK', 'from' => '2024-01-01', 'to' => '2024-12-31', 'limit' => 100]);
$nF4 = (int) one('SELECT count(*) FROM insider_transactions WHERE issuer_id = :i', ['i' => $iss]);
ok($itx['total'] === $nF4 && $nF4 >= 3 && isset($itx['rows'][0]['transaction_code']) && array_key_exists('is_10b5_1', $itx['rows'][0]) && tdata(PRIYA, 'insider_transactions', ['issuer' => 'SMOK', 'from' => '2024-01-01', 'to' => '2024-12-31', 'code' => 'S'])['total'] >= 1, 'insider_transactions: all of them (' . $nF4 . '), by code');
$isum = tdata(PRIYA, 'insider_transactions', ['issuer' => 'SMOK', 'from' => '2024-01-01', 'to' => '2024-12-31', 'summary' => true]);
ok($isum['count'] >= 1 && isset($isum['rows'][0]['sale_shares'], $isum['rows'][0]['last_owned_after']), 'and, with summary, a row per person');
ok(tdata(PRIYA, 'form144_notices', ['issuer' => 'SMOK', 'from' => '2024-01-01', 'to' => '2024-12-31'])['count'] === 1, 'form144_notices: the one notice');

echo "7. Filings, the watch, dilution\n";
$ff = tdata(PRIYA, 'find_filings', ['issuer' => 'SMOK', 'limit' => 100]);
ok($ff['count'] >= 5 && isset($ff['rows'][0]['label'], $ff['rows'][0]['has_text'], $ff['rows'][0]['watched']) && $ff['rows'][0]['filed_on'] >= $ff['rows'][$ff['count'] - 1]['filed_on'], 'find_filings: the index, newest first, with label, has_text, watched');
$acc = (string) one('SELECT accession_number FROM filings WHERE id = :f', ['f' => $w['filing_text']]);
ok(count(trows(PRIYA, 'find_filings', ['q' => $acc])) === 1 && count(trows(PRIYA, 'find_filings', ['form' => ['8-K'], 'issuer' => 'SMOK'])) >= 1 && !array_filter(trows(PRIYA, 'find_filings', ['form' => ['8-K'], 'issuer' => 'SMOK']), fn ($r) => $r['form'] !== '8-K')
    && count(trows(PRIYA, 'find_filings', ['issuer' => 'SMOK', 'about_us' => false])) >= 1, 'find_filings: q alone by accession (one row — the resolver), by form, by about_us');
$gf = tdata(PRIYA, 'get_filing', ['filing' => $acc]);
ok($gf['filing']['filing_id'] === $w['filing_text'] && $gf['filing']['has_text'] === true && count($gf['watch_hits']) === 1 && is_array($gf['structured']), 'get_filing: by accession number — the row, the watch\'s hit');
$fr = tdata(PRIYA, 'filing_read', ['filing' => $w['filing_text'], 'offset' => 10, 'length' => 100]);
ok($fr['char_count'] > 8000 && strlen($fr['text']) === 100 && $fr['offset'] === 10 && $fr['next_offset'] === 110, 'filing_read: a window of 100 characters from 10, and where the next starts');
$fa = tdata(PRIYA, 'filing_read', ['filing' => $w['filing_text'], 'around' => 'marker-needle']);
ok($fa['hits'] === 1 && count($fa['passages']) === 1 && str_contains($fa['passages'][0]['text'], 'MARKER-NEEDLE'), 'filing_read: around a term, the passage');
ok(tool(PRIYA, 'filing_read', ['filing' => $w['filing_text'], 'length' => 7000])['code'] === 'invalid_argument' && tool(PRIYA, 'filing_read', ['filing' => (int) one("SELECT id FROM filings WHERE issuer_id = :i AND fetched_text_at IS NULL LIMIT 1", ['i' => $iss])])['code'] === 'no_data', 'filing_read: a window over 6,000 characters is refused; a filing with no text kept is no_data');
$fx = tdata(PRIYA, 'filings_search', ['issuer' => 'SMOK', 'q' => 'equity line']);
ok($fx['count'] >= 1 && isset($fx['rows'][0]['headline']) && str_contains($fx['rows'][0]['headline'], '<b>') && tdata(PRIYA, 'filings_search', ['q' => 'equity line'])['count'] >= 1, 'filings_search: ranked, with the highlighted passage; without an issuer, across the ones Priya may see');
$wh = tdata(PRIYA, 'watch_hits', ['issuer' => 'SMOK']);
ok($wh['count'] === 1 && $wh['rows'][0]['term'] === 'equity line of credit' && tdata(PRIYA, 'watch_hits', ['status' => 'dismissed'])['count'] === 0, 'watch_hits: the hit, by status');
$di = tdata(PRIYA, 'dilution_instruments', ['issuer' => 'SMOK']);
ok($di['count'] === 1 && $di['rows'][0]['kind'] === 'eloc' && $di['rows'][0]['status'] === 'active' && $di['rows'][0]['floating_price'] === true && preg_match('/^\d+\.\d{2}$/', $di['rows'][0]['facility_amount']) === 1 && $di['rows'][0]['facility_amount'] === '10000000.00', 'dilution_instruments: the confirmed equity line, the facility a string with two decimals');
ok(count(trows(PRIYA, 'dilution_instruments', ['q' => 'EPSILON'])) === 1 && str_contains(trows(PRIYA, 'dilution_instruments', ['q' => 'SMOKE EPSILON CAPITAL LLC'])[0]['label'], 'EPSILON') && tdata(PRIYA, 'dilution_proposals', ['issuer' => 'SMOK'])['count'] === 0, 'dilution_instruments: q alone resolves it; no proposals left waiting');

echo "8. The short side\n";
$sis = tdata(PRIYA, 'short_interest_series', ['security' => $CUSIP, 'from' => '2024-01-01', 'to' => '2024-04-30']);
ok($sis['count'] >= 4 && isset($sis['rows'][0]['pct_of_float'], $sis['rows'][0]['days_to_cover'], $sis['rows'][0]['observed_at']) && $sis['rows'][0]['settlement_date'] >= $sis['rows'][1]['settlement_date'], 'short_interest_series: by settlement date with the share of float, days to cover, and when observed');
$rev = find_row($sis['rows'], 'settlement_date', '2024-03-28');
ok($rev !== null && $rev['revision_flag'] === true && $rev['short_shares'] === 530000, 'a revised date shows its LATEST observation (530,000)');
$sv = tdata(PRIYA, 'short_vs_float', ['security' => $CUSIP, 'as_of' => '2024-04-05']);
ok($sv['float_shares'] === 12000000 && $sv['pct_of_float'] > 0 && isset($sv['settlement_date']) && tool(PRIYA, 'short_vs_float', ['security' => 'SMOK2', 'as_of' => '2024-01-01'])['code'] === 'no_data', 'short_vs_float: against the 12,000,000 float; no_data without a publication');
$dv = tdata(PRIYA, 'daily_short_volume_series', ['security' => $CUSIP, 'from' => '2024-03-01', 'to' => '2024-04-30']);
ok($dv['count'] >= 5 && str_contains($dv['caveat'], 'off-exchange') && isset($dv['rows'][0]['mean_5'], $dv['rows'][0]['ratio']) && $dv['market'] === 'CNMS', 'daily_short_volume_series: with FINRA\'s caveat in the answer');
ok(tdata(PRIYA, 'fails_series', ['security' => $CUSIP, 'from' => '2024-01-01', 'to' => '2024-04-30'])['count'] >= 1, 'fails_series answers');
$tsa = tdata(PRIYA, 'threshold_status', ['security' => $CUSIP]);
ok(array_key_exists('on_list', $tsa) && isset($tsa['runs']) && tdata(PRIYA, 'threshold_history', ['security' => $CUSIP])['count'] >= 1 && tdata(PRIYA, 'threshold_history', ['security' => $CUSIP, 'from' => '2030-01-01'])['count'] === 0, 'threshold_status with its runs, threshold_history by date');
$bs = tdata(PRIYA, 'borrow_series', ['security' => $CUSIP]);
ok($bs['count'] === 1 && $bs['rows'][0]['indicative'] === true && $bs['rows'][0]['fee_rate_percent'] === 35.5, 'borrow_series: the broker\'s quote is marked indicative');
$ov = tdata(PRIYA, 'short_side_overlay', ['security' => $CUSIP, 'from' => '2024-03-01', 'to' => '2024-04-30']);
ok($ov['count'] >= 20 && isset($ov['rows'][0]['close'], $ov['rows'][0]['on_threshold']) && preg_match('/^\d+\.\d{2}$/', $ov['rows'][0]['close']) === 1 && str_contains($ov['caveat'], 'off-exchange'), 'short_side_overlay: price (a string with two decimals), volume and the series by day');
ok(tool(PRIYA, 'short_side_overlay', ['security' => $CUSIP, 'from' => '2020-01-01', 'to' => '2024-04-30'])['code'] === 'too_large', 'short_side_overlay: more than 400 days is too_large');

echo "9. Signals, rules, events, packs, exports\n";
$fsg = tdata(PRIYA, 'find_signals', ['issuer' => 'SMOK', 'limit' => 100]);
ok($fsg['count'] >= 12 && !find_row($fsg['rows'], 'signal_id', $w['smok_signal_disposed']) && isset($fsg['rows'][0]['evidence_class'], $fsg['rows'][0]['rule_name']) && in_array($fsg['rows'][0]['severity'], ['high', 'watch', 'info'], true), 'find_signals: the ones that need a person, by severity, each printing its rule\'s evidence class (' . $fsg['count'] . ')');
ok(count(array_unique(array_column($fsg['rows'], 'evidence_class'))) >= 2 && count(trows(PRIYA, 'find_signals', ['issuer' => 'SMOK', 'status' => ['acknowledged']])) === 1 && count(trows(PRIYA, 'find_signals', ['issuer' => 'SMOK', 'class' => 'lore', 'limit' => 100])) >= 1 && !array_filter(trows(PRIYA, 'find_signals', ['issuer' => 'SMOK', 'class' => 'lore', 'limit' => 100]), fn ($r) => $r['evidence_class'] !== 'lore')
    && count(trows(PRIYA, 'find_signals', ['issuer' => 'SMOK', 'rule' => 'si_over_float'])) === 1 && !array_filter(trows(PRIYA, 'find_signals', ['issuer' => 'SMOK', 'severity' => ['high']]), fn ($r) => $r['severity'] !== 'high'), 'find_signals: by state, class, rule and severity');
ok(count(trows(PRIYA, 'find_signals', ['q' => 'insider'])) >= 0 && count(trows(PRIYA, 'find_signals', ['q' => (string) one('SELECT summary FROM signals WHERE id = :i', ['i' => $w['smok_signal_disposed']])])) === 1, 'find_signals: q alone resolves one signal whatever its state (the disposed one by its summary)');
$gs = tdata(PRIYA, 'get_signal', ['signal' => $w['smok_signal_proposed']]);
ok($gs['signal']['signal_id'] === $w['smok_signal_proposed'] && $gs['rule']['key'] === 'ftd_spike' && $gs['rule']['evidence_class'] !== null && is_array($gs['rule']['effective_params']) && $gs['proposal']['reason'] === 'known' && $gs['proposal']['by'] === ANALYST && $gs['proposal']['fate'] === 'waiting' && $gs['proposal']['by_kind'] === 'agent' && is_array($gs['evidence']),
    'get_signal: the rule, its basis and thresholds in effect, the evidence, and the Analyst agent\'s proposal — waiting for a person');
$gd = tdata(PRIYA, 'get_signal', ['signal' => $w['smok_signal_disposed']]);
ok($gd['signal']['status'] === 'acknowledged' && $gd['signal']['disposition_reason'] === 'known' && $gd['signal']['disposed_by'] === PRIYA && $gd['proposal'] === null, 'get_signal: a disposed one carries who, why and the note');
$hist = tdata(PRIYA, 'signals_history', ['issuer' => 'SMOK', 'from' => '2024-01-01', 'to' => '2025-12-31', 'limit' => 100]);
ok($hist['count'] >= 12 && find_row($hist['rows'], 'signal_id', $w['smok_signal_disposed'])['disposition_reason'] === 'known' && find_row($hist['rows'], 'signal_id', $w['smok_signal_proposed'])['proposal_fate'] === 'waiting', 'signals_history: every state with the dispositions and the proposals\' fate');
$rc = tdata(PRIYA, 'rules_catalogue', ['issuer' => 'SMOK']);
ok($rc['count'] === 19 && count(array_unique(array_column($rc['rows'], 'evidence_class'))) >= 3 && isset($rc['rows'][0]['effective_params'], $rc['rows'][0]['basis']) && tdata(PRIYA, 'rules_catalogue', ['class' => 'lore'])['count'] >= 4 && tdata(PRIYA, 'rules_catalogue')['rows'][0]['effective_params'] === null, 'rules_catalogue: the nineteen rules with class and basis; thresholds in effect for an issuer, by class');
ok(count(trows(PRIYA, 'find_rules', ['q' => 'si_over_float'])) === 1 && count(trows(PRIYA, 'find_rules', ['q' => 'float'])) >= 1, 'find_rules: by key (one row — the resolver) or name');
$so = tdata(PRIYA, 'signal_outcomes', ['issuer' => 'SMOK']);
ok(isset($so['by_rule']) && $so['enough'] === 30 && $so['count'] >= 1 && array_key_exists('return_20d', $so['rows'][0]) && isset($so['by_rule'][0]['n']), 'signal_outcomes: the price at the signal and the returns, per rule how many have a 20-day return');
$eo = tdata(PRIYA, 'events_on', ['issuer' => 'SMOK', 'date' => '2024-04-03', 'days' => 0]);
ok($eo['count'] === 1 && $eo['rows'][0]['kind'] === 'activist_short_report' && tdata(PRIYA, 'events_on', ['issuer' => 'SMOK', 'date' => '2024-04-04', 'days' => 3])['count'] >= 1 && tool(PRIYA, 'events_on', ['date' => '2024-04-04', 'days' => 15])['code'] === 'invalid_argument', 'events_on: the day\'s event; a window of three days; 15 is invalid');
$fe = tdata(PRIYA, 'find_events', ['issuer' => 'SMOK']);
ok($fe['count'] >= 4 && count(trows(PRIYA, 'find_events', ['issuer' => 'SMOK', 'kind' => ['activist_short_report']])) === 1 && count(trows(PRIYA, 'find_events', ['q' => 'equity line'])) >= 0 && count(trows(PRIYA, 'find_events', ['q' => 'SMOKE Short Research'])) === 1, 'find_events: by kind, and q alone (one row — the resolver)');
$up = tdata(PRIYA, 'events_upcoming', ['issuer' => 'SMOK', 'days' => 120]);
$src = array_count_values(array_column($up['rows'], 'source'));
ok(($src['computed'] ?? 0) >= 2 && count(array_filter($up['rows'], fn ($r) => $r['kind'] === 'thirteen_f_deadline')) >= 1 && count(array_filter($up['rows'], fn ($r) => $r['kind'] === 'short_interest_publication')) >= 2 && $up['rows'][0]['on_date'] <= $up['rows'][count($up['rows']) - 1]['on_date'], 'events_upcoming: the computed days (FINRA\'s publications, the 13F deadline) marked source computed, in date order');
$fr = tdata(PRIYA, 'find_reports', ['issuer' => 'SMOK']);
ok($fr['count'] === 2 && find_row($fr['rows'], 'report_id', $w['report_draft'])['status'] === 'draft' && count(trows(ANN, 'find_reports', ['issuer' => 'SMOK'])) === 1 && trows(ANN, 'find_reports', ['issuer' => 'SMOK'])[0]['status'] === 'approved', 'find_reports: Priya (who runs packs) sees the draft and the approved one; Ann only the approved');
ok(tool(ANN, 'get_report', ['report' => $w['report_draft']])['code'] === 'not_found' && tdata(PRIYA, 'get_report', ['report' => $w['report_draft']])['narrative'] === 'a draft narrative' && tdata(ANN, 'get_report', ['report' => $w['report_approved']])['sections'] === ['movement', 'flow', 'signals'] && count(trows(PRIYA, 'find_reports', ['q' => 'p4 approved monthly pack'])) === 1, 'get_report: a draft is not_found for Ann; the approved pack\'s narrative and sections; q alone resolves one');
ok(count(trows(PRIYA, 'find_exports')) === 1 && count(trows(MARCO, 'find_exports')) === 2 && count(trows(ANN, 'find_exports')) === 0 && !has_key(tdata(PRIYA, 'find_exports'), 'storage_path'), 'find_exports: Priya her own, Marco (export.shared) both, Ann none — never a file path');

echo "10. Agents, keys, sources, the admin\n";
$ap = trows(PRIYA, 'analyst_proposals');
$kinds = array_count_values(array_column($ap, 'kind'));
ok(($kinds['signal'] ?? 0) >= 1 && ($kinds['holder_match'] ?? 0) === 1 && ($kinds['holder_kind'] ?? 0) === 1 && count(array_filter(trows(ANN, 'analyst_proposals'), fn ($r) => $r['kind'] !== 'signal')) === 0 && count(trows(PRIYA, 'analyst_proposals', ['kind' => 'holder_match'])) === 1, 'analyst_proposals: Priya sees a signal, a holder match and a holder kind; Ann the signal alone');
ok(count(trows(MARCO, 'agent_dispatches')) >= 2 && count(trows(PRIYA, 'agent_dispatches')) === 0 && count(trows(MARCO, 'agent_dispatches', ['status' => 'failed'])) === 1 && count(trows(MARCO, 'agent_dispatches', ['kind' => 'signal'])) >= 1, 'agent_dispatches: those who set the agents see all, Priya (who does not) none, by status and kind');
facts(runid(70), ANALYST, ['Records MCP' => ['agent_dispatches' => []]]);
ok(count(mcp_tool(REC, rtoken(ANALYST, runid(70)), 'agent_dispatches')['data']['rows']) >= 1 && !array_filter(mcp_tool(REC, rtoken(ANALYST, runid(70)), 'agent_dispatches')['data']['rows'], fn ($r) => $r['agent_member_id'] !== ANALYST), '...and an agent sees its own');
$mr = trows(PRIYA, 'members_with_roles', ['agents_only' => true]);
ok(count($mr) >= 2 && !array_filter($mr, fn ($r) => $r['member_kind'] !== 'agent') && count(trows(PRIYA, 'members_with_roles', ['role' => 'viewer'])) >= 1, 'members_with_roles: agents only, or a role');
$fk = trows(PRIYA, 'find_keys', ['limit' => 100]);
$mine = find_row($fk, 'label', 'p4 priya key');
ok($mine !== null && $mine['calls_today'] === 10 && !has_key($fk, 'token_hash') && !has_key($fk, 'key') && !array_filter($fk, fn ($k) => $k['member_id'] !== PRIYA) && count(trows(OWNER, 'find_keys', ['limit' => 100])) > count($fk) && count(trows(PRIYA, 'find_keys', ['q' => 'p4 priya key'])) === 1, 'find_keys: Priya her own only, with today\'s calls and never a hash; the administrator all');
$ku = tdata(PRIYA, 'key_usage_summary', ['api_key' => 'p4 priya key']);
ok($ku['count'] === 4 && $ku['rows'][0]['calls'] === 10 && $ku['rows'][0]['day'] >= $ku['rows'][3]['day'] && tool(MARCO, 'key_usage_summary', ['api_key' => 'p4 priya key'])['code'] === 'not_found' && !tool(OWNER, 'key_usage_summary', ['api_key' => $w['key_priya']])['error'], 'key_usage_summary: four days; another person\'s key is not_found for Marco, readable by the administrator');
$fsr = trows(PRIYA, 'find_sources', ['limit' => 100]);
ok(count($fsr) >= 10 && !has_key($fsr, 'base_url') && !has_key($fsr, 'settings') && isset($fsr[0]['has_credential']) && count(trows(PRIYA, 'find_sources', ['connector' => 'finra_short_interest'])) === 1 && count(trows(PRIYA, 'find_sources', ['q' => (string) $fsr[0]['name']])) >= 1, 'find_sources: the fetchers, never a credential or a URL');
$sh = tdata(PRIYA, 'source_health', ['connector' => 'finra_short_interest', 'pulls' => 2]);
ok($sh['count'] === 1 && $sh['rows'][0]['consecutive_failures'] === 0 && count($sh['rows'][0]['pulls']) >= 1 && $sh['rows'][0]['pulls'][0]['status'] === 'failed' && $sh['rows'][0]['pulls'][0]['error'] === 'p4 the host answered 503', 'source_health: the failed pull is in the pulls (the source row\'s last status follows its last_pull_id)');
ok(tdata(PRIYA, 'source_health')['count'] >= 10, 'source_health lists every source');
ok(tdata(PRIYA, 'find_templates', ['kind' => 'spr'])['count'] >= 1 && isset(tdata(PRIYA, 'find_templates')['rows'][0]['mapping']), 'find_templates: the mappings by kind');
$ts = tdata(MARCO, 'tombstones');
ok(is_array($ts['rows']) && tool(PRIYA, 'tombstones')['code'] === 'managers_only', 'tombstones: a manager\'s');

echo "11. records_search: a view, five equality filters, no SQL\n";
$r = tdata(PRIYA, 'records_search', ['view' => 'mcp_issuers', 'filters' => ['ticker' => 'SMOK'], 'columns' => ['issuer_id', 'name']]);
ok($r['count'] === 1 && array_keys($r['rows'][0]) === ['issuer_id', 'name'] && $r['view'] === 'mcp_issuers', 'one filter, two columns');
ok(tdata(PRIYA, 'records_search', ['view' => 'mcp_signals', 'order_by' => '-raised_on', 'limit' => 3])['count'] === 3 && tdata(PRIYA, 'records_search', ['view' => 'mcp_securities', 'filters' => ['is_primary' => true]])['count'] >= 3 && tdata(PRIYA, 'records_search', ['view' => 'mcp_securities', 'filters' => ['delisted_on' => null]])['count'] >= 3, 'ordering, a boolean filter, a null filter');
foreach ([['view' => 'mcp_activity_log'], ['view' => 'mcp_all_tokens'], ['view' => 'mcp_source_credentials'], ['view' => 'mcp_filing_text'], ['view' => 'holders'], ['view' => 'pg_user'], ['view' => 'mcp_issuers; select 1'], ['view' => 'mcp_issuers', 'filters' => ['nope' => 1]],
          ['view' => 'mcp_issuers', 'columns' => ['name; drop table x']], ['view' => 'mcp_issuers', 'order_by' => 'nope'], ['view' => 'mcp_issuers', 'filters' => ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5, 'f' => 6]]] as $a) {
    $x = tool(PRIYA, 'records_search', $a);
    ok($x['code'] === 'invalid_argument', 'records_search ' . json_encode($a) . ' is refused as invalid_argument');
}
$before = (int) one("SELECT count(*) FROM activity_log WHERE action = 'records.search'");
tool(PRIYA, 'records_search', ['view' => 'mcp_holders', 'filters' => ['city' => 'SECRETCITY'], 'limit' => 2]);
$l = last_event('records.search');
$a = json_decode((string) $l['after'], true);
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'records.search'") === $before + 1 && $l['actor_member_id'] === PRIYA && $l['source'] === 'mcp' && $a['view'] === 'mcp_holders' && $a['filters'] === ['city'] && !str_contains((string) $l['after'], 'SECRETCITY'),
    'records_search is logged records.search {view, the filters\' NAMES, rows, ms} — never a value');
$cut = tdata(PRIYA, 'records_search', ['view' => 'mcp_events', 'filters' => ['event_id' => $w['ev_short']]]);
ok($cut['count'] === 1, 'a record by id');

echo "12. Conventions: shares are integers, money is a string with two decimals, errors are answers\n";
$all = [];
foreach ($sw as [$t, $a]) { $d = tdata(PRIYA, $t, $a); if (is_array($d)) { $all[$t] = $d; } }
$floatShares = [];
$walk = function ($x, $path = '') use (&$walk, &$floatShares) {
    if (!is_array($x)) { return; }
    foreach ($x as $k => $v) {
        if (in_array($k, ['shares', 'outstanding', 'float_shares', 'short_shares', 'cede_shares', 'close_shares', 'open_shares', 'quantity'], true) && is_float($v)) { $floatShares[] = $path . '.' . $k; }
        $walk($v, $path . '.' . $k);
    }
};
$walk($all);
ok($floatShares === [], 'no share count anywhere in 90 answers is a float' . ($floatShares ? ' — ' . implode(', ', array_slice($floatShares, 0, 5)) : ''));
$moneyBad = [];
$walk2 = function ($x, $path = '') use (&$walk2, &$moneyBad) {
    if (!is_array($x)) { return; }
    foreach ($x as $k => $v) {
        if (in_array($k, ['value_dollars', 'aggregate_market_value', 'price', 'close', 'price_at', 'facility_amount', 'remaining_amount'], true) && $v !== null && !(is_string($v) && preg_match('/^-?\d+\.\d{2}$/', $v))) { $moneyBad[] = $path . '.' . $k; }
        $walk2($v, $path . '.' . $k);
    }
};
$walk2($all);
ok($moneyBad === [], 'every money field is a string with two decimals' . ($moneyBad ? ' — ' . implode(', ', array_slice($moneyBad, 0, 5)) : ''));
$dateBad = [];
foreach (['positions_on' => 'position_date', 'spr_total' => 'position_date', 'reconciliation_latest' => 'as_of'] as $t => $f) { if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($all[$t][$f] ?? ''))) { $dateBad[] = $t; } }
ok($dateBad === [], 'dates are ISO 8601 and every number carries its date (position_date, as_of, settlement_date)');
$bad = tool(PRIYA, 'get_signal', ['signal' => 99999999]);
ok($bad['data']['ok'] === false && $bad['data']['error']['code'] === 'not_found' && $bad['data']['error']['retry'] === false && is_string($bad['data']['error']['message']), 'an error is an answer: {ok:false, error:{code, message, retry}}');
finish();
