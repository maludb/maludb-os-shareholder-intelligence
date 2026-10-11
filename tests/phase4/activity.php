<?php
/**
 * Proof — the activity server (docs/shareholder-intelligence-mcp-tool-surface.md "Activity server"): the seven tools read the trail through mcp_activity_log, as the asking member — the issuer wall is the view's (a restricted
 * issuer's rows do not exist for a member off its list), another member's trail is a manager's, screen views are left out, and record_episodes reads the tenant's memory (the fake MaluDB the ingest shipped to) for the events
 * the member may see and no others. The server writes nothing.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$S = surface();
$iss = $w['smok_issuer'];
$sigO = $w['smok_signal_open']; $sigP = $w['smok_signal_proposed']; $sigD = $w['smok_signal_disposed'];
q("INSERT INTO activity_log (actor_member_id, source, action, entity_type, entity_id, issuer_id) VALUES (26, 'web', 'screen.view', 'issuer', :i, :i)", ['i' => $iss]);
$before = (int) one('SELECT count(*) FROM activity_log');

echo "1. record_history — who did what to a record, in order\n";
$rh = tdata(PRIYA, 'record_history', ['kind' => 'signal', 'record' => $sigO], ACT);
ok($rh['count'] === 1 && $rh['rows'][0]['action'] === 'signal.raise' && $rh['rows'][0]['source'] === 'cron' && $rh['rows'][0]['after']['rule'] === 'si_over_float' && str_contains($rh['rows'][0]['summary'], 'raised a signal'), 'a signal\'s trail: the rule raised it (cron), with its rule key and a sentence');
$ri = tdata(PRIYA, 'record_history', ['kind' => 'issuer', 'record' => $iss], ACT);
ok($ri['count'] >= 6 && $ri['rows'][0]['occurred_at'] <= $ri['rows'][$ri['count'] - 1]['occurred_at'] && !find_row($ri['rows'], 'action', 'screen.view'), 'an issuer\'s trail by its audit key: oldest first, six or more events, no screen view');
$ru = tdata(PRIYA, 'record_history', ['kind' => 'upload', 'record' => $w['nobo_import']], ACT);
ok($ru['count'] === 2 && $ru['rows'][0]['action'] === 'upload.create' && $ru['rows'][1]['action'] === 'upload.tie' && $ru['rows'][0]['actor_name'] === 'SMOKE Priya', 'an upload\'s trail: created, then tied, by whom');
$rp = tdata(PRIYA, 'record_history', ['kind' => 'signal', 'record' => $sigP], ACT);
ok($rp['count'] === 1 && $rp['rows'][0]['action'] === 'signal.propose' && $rp['rows'][0]['source'] === 'agent' && $rp['rows'][0]['actor_name'] === 'SMOKE Surveillance Analyst', 'an agent\'s proposal is in the trail as the agent\'s');
ok(tdata(PRIYA, 'record_history', ['kind' => 'report', 'record' => $w['report_draft']], ACT)['count'] === 1 && tool(PRIYA, 'record_history', ['kind' => 'Bad Kind', 'record' => 1], ACT)['code'] === 'invalid_argument', 'by entity type too (a report); a kind that is not a word is invalid_argument');

echo "2. The wall in the trail: Delta's rows exist for Marco and nobody off its list\n";
$dTrail = [['issuer', $w['delta_issuer']], ['signal', $w['delta_signal']], ['upload', (int) one("SELECT id FROM imports WHERE file_name = 'SMOKE-DELTA-SPR.csv'")], ['holder', $w['delta_holder_entity']]];
$m = array_map(fn ($k) => tdata(MARCO, 'record_history', ['kind' => $k[0], 'record' => $k[1]], ACT)['count'], $dTrail);
ok($m[0] >= 3 && $m[1] === 1 && $m[2] === 1 && $m[3] === 1, 'Marco reads Delta\'s trail by every key (' . implode(', ', $m) . ' rows)');
$off = 0;
foreach ([PRIYA, DANA, ANN, BEA] as $mem) { foreach ($dTrail as $k) { $off += tdata($mem, 'record_history', ['kind' => $k[0], 'record' => $k[1]], ACT)['count']; } }
ok($off === 0, 'Priya, Dana, Ann and Bea: not one row of it by any key');
$bad = [];
foreach ([PRIYA, DANA, ANN] as $mem) {
    foreach ([['activity_search', ['q' => 'DELTA']], ['activity_search', ['q' => 'SECRET']], ['activity_search', ['q' => 'holder merge']], ['recent_activity', ['since' => '2020-01-01', 'limit' => 200]], ['agent_activity', []], ['actor_history', ['member' => 'me', 'from' => '2020-01-01']]] as [$t, $a]) {
        $r = tool($mem, $t, $a, ACT);
        if (str_contains($r['text'], 'DELTA SECRET') || str_contains($r['text'], 'holder.merge') || ($r['data']['ok'] ?? false) !== true) { $bad[] = "$mem:$t"; }
    }
}
ok($bad === [], 'search, recent activity, the agents\' week: none carries a Delta row' . ($bad ? ' — ' . implode(', ', $bad) : ''));
ok(tool(DANA, 'activity_timeline', ['issuer' => 'SMKD', 'from' => date('Y-01-01'), 'to' => date('Y-12-31')], ACT)['code'] === 'not_found' && count(tdata(MARCO, 'activity_search', ['q' => 'holder merge'], ACT)['rows']) === 1, 'activity_timeline for Delta is not_found for Dana; as Marco the search finds the holder merge');

echo "3. actor_history — a member's trail, a manager's to read\n";
$mine = tdata(PRIYA, 'actor_history', ['member' => 'me', 'from' => '2020-01-01'], ACT);
ok($mine['count'] >= 4 && !array_filter($mine['rows'], fn ($r) => $r['actor_member_id'] !== PRIYA) && $mine['rows'][0]['occurred_at'] >= $mine['rows'][1]['occurred_at'], 'Priya\'s own trail, newest first: only her rows (' . $mine['count'] . ')');
ok(tool(PRIYA, 'actor_history', ['member' => 'SMOKE Marco', 'from' => '2020-01-01'], ACT)['code'] === 'managers_only' && tool(PRIYA, 'actor_history', ['member' => ANALYST], ACT)['code'] === 'managers_only', 'another member\'s trail — a person\'s or an agent\'s — is managers_only for Priya');
$ag = tdata(MARCO, 'actor_history', ['member' => 'SMOKE Surveillance Analyst', 'from' => '2020-01-01'], ACT);
ok($ag['count'] === 2 && !array_filter($ag['rows'], fn ($r) => $r['actor_member_id'] !== ANALYST), 'Marco reads the Analyst agent\'s: its proposal and its draft');
ok(tdata(PRIYA, 'actor_history', ['member' => 'me', 'from' => '2020-01-01', 'action' => 'signal.'], ACT)['count'] === 1 && tdata(PRIYA, 'actor_history', ['member' => 'me'], ACT)['count'] >= 4 && tdata(PRIYA, 'actor_history', ['member' => 'me', 'to' => '2020-01-01', 'from' => '2019-01-01'], ACT)['count'] === 0, 'by action prefix, the default week, and a window with nothing in it');

echo "4. activity_search, recent_activity, agent_activity, activity_timeline\n";
$as = tdata(PRIYA, 'activity_search', ['q' => 'signal acknowledge'], ACT);
ok($as['count'] === 1 && $as['rows'][0]['action'] === 'signal.acknowledge' && count(tdata(PRIYA, 'activity_search', ['q' => 'propose agent'], ACT)['rows']) >= 0 && tdata(PRIYA, 'activity_search', ['q' => 'upload', 'issuer' => 'SMOK'], ACT)['count'] === 2, 'activity_search: each word must match — "signal acknowledge" finds the acknowledgement; "upload" for the issuer the two upload events');
ok(tdata(PRIYA, 'activity_search', ['q' => 'upload', 'from' => '2999-01-01'], ACT)['count'] === 0 && tool(PRIYA, 'activity_search', ['q' => 'x'], ACT)['code'] === 'invalid_argument', 'a window with nothing; a one-letter search is invalid');
ok(tdata(PRIYA, 'activity_search', ['q' => 'Priya'], ACT)['count'] >= 4 && tdata(PRIYA, 'activity_search', ['q' => 'si_over_float'], ACT)['count'] >= 1, 'the actor\'s name and a rule key inside a payload are searched too');
$ra = tdata(PRIYA, 'recent_activity', ['since' => '2020-01-01', 'limit' => 200], ACT);
ok($ra['count'] >= 8 && !find_row($ra['rows'], 'action', 'screen.view') && tdata(PRIYA, 'recent_activity', ['action' => 'upload.'], ACT)['count'] === 2 && tdata(PRIYA, 'recent_activity', ['issuer' => 'SMOK'], ACT)['count'] >= 6, 'recent_activity: no screen views; by action prefix and by issuer (the default is the last 7 days)');
$aa = tdata(PRIYA, 'agent_activity', [], ACT);
ok(count($aa['actions']) >= 2 && !array_filter($aa['actions'], fn ($r) => $r['actor_member_id'] !== ANALYST && $r['source'] !== 'agent') && count(array_filter($aa['proposals_open'], fn ($p) => $p['kind'] === 'signal' && $p['record_id'] === $sigP)) === 1 && count(array_filter($aa['proposals_open'], fn ($p) => $p['kind'] === 'holder_match')) === 1
    && find_row($aa['proposals_decided'], 'action', 'signal.acknowledge') !== null, 'agent_activity: what the agents did, the proposals still open (a signal, a holder match), the decisions people made');
$aaAnn = tdata(ANN, 'agent_activity', [], ACT);
ok(!array_filter($aaAnn['proposals_open'], fn ($p) => $p['kind'] !== 'signal') && count($aaAnn['proposals_open']) >= 1, 'for Ann the open proposals are the signal\'s alone (the holder ones name holders)');
ok(count(tdata(PRIYA, 'agent_activity', ['agent' => 'SMOKE Surveillance Analyst'], ACT)['actions']) === 2 && count(tdata(PRIYA, 'agent_activity', ['agent' => EXPERT], ACT)['actions']) === 0, 'agent_activity for one agent');
$tl = tdata(PRIYA, 'activity_timeline', ['issuer' => 'SMOK', 'from' => date('Y-01-01'), 'to' => date('Y-12-31')], ACT);
$sqlN = (int) one("SELECT count(*) FROM activity_log WHERE issuer_id = :i AND action <> 'screen.view'", ['i' => $iss]);
ok($tl['count'] >= 6 && array_sum(array_column($tl['rows'], 'count')) === $sqlN && $tl['rows'][0]['day'] >= $tl['rows'][$tl['count'] - 1]['day'], "activity_timeline: a row per day and action, the counts add up to the issuer's $sqlN events, newest day first");
ok(tool(PRIYA, 'activity_timeline', ['issuer' => 'SMOK', 'from' => '2000-01-01', 'to' => '2030-01-01'], ACT)['code'] === 'too_large', 'more than 400 days is too_large');

echo "5. record_episodes — the tenant's memory, for the events a member may see\n";
shell_exec('set -a; . ' . escapeshellarg(need('SI_DEV_ENV')) . '; set +a; cd ' . escapeshellarg(dirname(__DIR__, 2) . '/mcp') . ' && ' . escapeshellarg(trim((string) shell_exec(escapeshellarg(__DIR__ . '/mcp_servers.sh') . ' venv'))) . '/bin/python activity_ingest.py 2>&1');
$shipped = array_filter(array_map(fn ($l) => json_decode($l, true), file(need('FAKE_MALUDB_LOG'), FILE_IGNORE_NEW_LINES) ?: []));
$hasDelta = count(array_filter($shipped, fn ($b) => ($b['payload']['issuer_id'] ?? 0) === $w['delta_issuer']));
ok(count($shipped) >= 10 && $hasDelta >= 3, 'the ingest shipped ' . count($shipped) . ' episodes to the memory, ' . $hasDelta . ' of them Delta\'s');
$ep = tdata(PRIYA, 'record_episodes', ['kind' => 'signal', 'record' => $sigO], ACT);
ok($ep['source'] === 'memory' && $ep['count'] === 1 && $ep['rows'][0]['payload']['signal_id'] === $sigO && $ep['rows'][0]['payload']['application'] === 'shareholder_intelligence' && isset($ep['rows'][0]['episode_id'], $ep['rows'][0]['title']), 'a signal\'s episodes from the memory: the one episode the ingest made of the log row');
$epI = tdata(PRIYA, 'record_episodes', ['kind' => 'issuer', 'record' => $iss, 'limit' => 100], ACT);
ok($epI['source'] === 'memory' && $epI['count'] === $sqlN && !array_filter($epI['rows'], fn ($e) => ($e['payload']['issuer_id'] ?? 0) !== $iss), "the issuer's episodes: all $sqlN, and not one of another issuer's");
ok(tdata(DANA, 'record_episodes', ['kind' => 'issuer', 'record' => $w['delta_issuer']], ACT)['count'] === 0 && tdata(DANA, 'record_episodes', ['kind' => 'signal', 'record' => $w['delta_signal']], ACT)['count'] === 0, 'Delta\'s episodes ARE in the memory — and Dana is given none of them (the wall is the log\'s view, not the memory)');
ok(tdata(MARCO, 'record_episodes', ['kind' => 'issuer', 'record' => $w['delta_issuer']], ACT)['count'] >= 3, 'Marco is given them');
ok(!has_key($epI, 'display_name') && !has_key($epI, 'registration_text') && !has_key($epI, 'address_line'), 'and an episode carries ids, counts and keys — no name, no address');

echo "6. An agent's grants on this endpoint\n";
$g = agent_grants('analyst');
facts(runid(80), ANALYST, $g);
$tk = rtoken(ANALYST, runid(80));
$offered = mcp_tools(ACT, $tk);
$want = array_keys($g['Activity MCP']); sort($want);
ok($offered === $want, 'the Analyst is offered exactly its three activity tools (' . implode(', ', $want) . ')');
ok(!mcp_tool(ACT, $tk, 'recent_activity', ['limit' => 5])['error'] && mcp_tool(ACT, $tk, 'actor_history', ['member' => 'me'])['error'], 'and calls them; actor_history, not granted, is refused');

echo "7. The server wrote nothing\n";
ok((int) one('SELECT count(*) FROM activity_log') === $before, 'the activity log has the same number of rows after every activity call (' . $before . ')');
finish();
