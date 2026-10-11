<?php
/**
 * Proof — the two walls, through the real protocol (docs/shareholder-intelligence-mcp-tool-surface.md, design §3):
 *   THE ISSUER WALL — SMOKE Delta (SMKD) names Marco alone on its restriction list, SMOKE Beta names Dana: a member off the list gets `not_found` from every tool, by id, ticker, CIK or CUSIP, in the words of an issuer that does not exist,
 *   and finds nothing of it by words in a list, the search box, records_search or the activity trail. The SECURITY DEFINER read functions have no wall of their own: each tool proves the security visible first.
 *   THE PII WALL — Ann (a Viewer) never reads a natural person's name, address or registration lines, from any tool (a sweep of every tool's answer against the names in the world); Priya (an Analyst) does.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$S = surface();
$dSec = $w['delta_security'];

/** Run calls as a member's own token; answers [[tool, args, result], …]. */
function run_as(int $member, array $calls, int $port = REC): array
{
    $out = [];
    foreach ($calls as [$t, $a]) { $out[] = [$t, $a, tool($member, $t, $a, $port)]; }
    return $out;
}
function texts(array $results): string { return implode("\n", array_map(fn ($r) => $r[2]['text'], $results)); }

echo "1. Who sees which issuer\n";
$tick = fn (int $m) => array_column(trows($m, 'find_issuers', ['limit' => 100]), 'ticker');
$marco = $tick(MARCO); $priya = $tick(PRIYA); $dana = $tick(DANA); $ann = $tick(ANN); $owner = $tick(OWNER); $bea = $tick(BEA);
ok(in_array('SMKD', $marco, true) && in_array('SMKB', $marco, true) && in_array('SMOK', $marco, true), 'Marco, an IR Manager, sees every issuer, restricted or not (' . count($marco) . ')');
ok(in_array('SMKD', $owner, true) && in_array('SMKB', $owner, true), 'and so does the super-admin');
ok(!in_array('SMKD', $priya, true) && !in_array('SMKB', $priya, true) && in_array('SMKA', $priya, true) && in_array('SMOK', $priya, true), 'Priya, an Analyst on neither list, sees the open issuers and neither Beta nor Delta');
ok(in_array('SMKB', $dana, true) && !in_array('SMKD', $dana, true), 'Dana (on Beta\'s list) sees Beta and not Delta');
ok($ann === $priya && $bea === $priya, 'and Ann and Bea see what Priya sees: the wall is the list, not the role');
$dRow = find_row(trows(MARCO, 'find_issuers', ['q' => 'SMKD']), 'ticker', 'SMKD');
ok($dRow !== null && $dRow['restricted'] === true && $dRow['primary_security_id'] === $dSec, 'Delta reads as restricted to Marco, with its primary security');

echo "2. Delta behind the wall: every tool answers not_found, by every way of naming it\n";
$d = ['iss' => 'SMKD', 'sec' => (string) $dSec];
$byIds = [
    ['get_issuer', ['issuer' => 'SMKD']], ['get_issuer', ['issuer' => (string) $w['delta_issuer']]], ['get_issuer', ['issuer' => '0000999004']], ['get_issuer', ['issuer' => '999004']], ['get_issuer', ['issuer' => 'SMOKE DELTA CORP']],
    ['share_counts', ['security' => $dSec]], ['share_counts', ['security' => '99900D101']], ['share_counts', ['security' => 'SMKD']], ['share_counts', ['issuer' => 'SMKD']],
    ['positions_on', ['security' => $dSec]], ['position_changes', ['security' => $dSec, 'from' => '2024-01-01', 'to' => '2030-01-01']], ['participants_new_lost', ['security' => $dSec, 'from' => '2024-01-01', 'to' => '2030-01-01']],
    ['participant_flow', ['security' => $dSec, 'from' => '2024-01-01', 'to' => '2030-01-01']], ['concentration', ['security' => $dSec]], ['participant_series', ['security' => $dSec, 'participant' => '0164', 'from' => '2024-01-01', 'to' => '2030-01-01']],
    ['spr_total', ['security' => $dSec, 'date' => date('Y-m-d', strtotime('-3 days'))]], ['reconciliation_latest', ['security' => $dSec]], ['reconciliation_history', ['security' => $dSec]],
    ['holders_changes', ['security' => $dSec, 'channel' => 'register', 'date_a' => '2024-01-01', 'date_b' => '2030-01-01']], ['holders_by_range', ['security' => $dSec, 'channel' => 'register']], ['holders_by_geography', ['security' => $dSec, 'channel' => 'register']],
    ['holders_by_custodian', ['security' => $dSec]], ['holders_by_type', ['security' => $dSec]], ['obo_remainder', ['security' => $dSec, 'record_date' => '2024-04-04']], ['participant_nobo_summary', ['security' => $dSec, 'participant' => '0164']],
    ['share_ranges', ['security' => $dSec]], ['obo_totals', ['security' => $dSec]], ['institutional_holders', ['security' => $dSec]], ['holdings_changes', ['security' => $dSec]], ['holdings_summary', ['security' => $dSec]],
    ['holdings_put_call', ['security' => $dSec]], ['beneficial_owners', ['security' => $dSec]], ['institution_history', ['security' => $dSec, 'institution' => 1]],
    ['short_interest_series', ['security' => $dSec, 'from' => '2024-01-01', 'to' => '2030-01-01']], ['short_vs_float', ['security' => $dSec]], ['daily_short_volume_series', ['security' => $dSec, 'from' => '2024-01-01', 'to' => '2030-01-01']],
    ['fails_series', ['security' => $dSec, 'from' => '2024-01-01', 'to' => '2030-01-01']], ['threshold_status', ['security' => $dSec]], ['threshold_history', ['security' => $dSec]], ['borrow_series', ['security' => $dSec]],
    ['short_side_overlay', ['security' => $dSec, 'from' => '2024-01-01', 'to' => '2030-01-01']],
    ['get_import', ['upload' => $w['delta_spr'] ?? (int) one("SELECT id FROM imports WHERE file_name = 'SMOKE-DELTA-SPR.csv'")]], ['import_rows', ['upload' => (int) one("SELECT id FROM imports WHERE file_name = 'SMOKE-DELTA-SPR.csv'")]],
    ['get_holder', ['holder' => $w['delta_holder_entity']]], ['get_holder', ['holder' => $w['delta_holder_person']]], ['holder_history', ['holder' => $w['delta_holder_entity']]],
    ['get_filing', ['filing' => $w['delta_filing']]], ['get_filing', ['filing' => '0000999004-24-000001']], ['filing_read', ['filing' => $w['delta_filing']]], ['get_signal', ['signal' => $w['delta_signal']]], ['get_report', ['report' => $w['delta_report']]],
    ['insider_transactions', ['issuer' => 'SMKD', 'from' => '2024-01-01', 'to' => '2030-01-01', 'summary' => true]], ['rules_catalogue', ['issuer' => 'SMKD']], ['filings_search', ['issuer' => 'SMKD', 'q' => 'secret']],
    ['events_on', ['issuer' => 'SMKD', 'date' => date('Y-m-d')]], ['analyst_proposals', ['issuer' => 'SMKD']], ['activity_timeline', ['issuer' => 'SMKD', 'from' => date('Y-01-01'), 'to' => date('Y-12-31')]],
];
$activityOnes = ['activity_timeline'];
$bad = [];
foreach ([PRIYA, DANA, ANN] as $m) {
    foreach ($byIds as [$t, $a]) {
        $r = tool($m, $t, $a, in_array($t, $activityOnes, true) ? ACT : REC);
        if ($r['code'] !== 'not_found') { $bad[] = "$m:$t=" . ($r['code'] ?? 'answered'); }
    }
}
ok($bad === [], count($byIds) . ' tool calls × Priya, Dana and Ann: every one is not_found — by id, ticker, CIK, CUSIP or name' . ($bad ? ' — ' . implode(', ', array_slice($bad, 0, 6)) : ''));
$leak = [];
foreach ($byIds as [$t, $a]) {
    $r = tool(MARCO, $t, $a, in_array($t, $activityOnes, true) ? ACT : REC);
    if ($r['code'] === 'not_found' || $r['code'] === 'managers_only' || $r['http'] !== 200) { $leak[] = "$t=" . ($r['code'] ?? $r['http']); }
}
ok($leak === [], 'the same calls as Marco (on the list) are never not_found — the wall, not a typo, is what answered' . ($leak ? ' — ' . implode(', ', array_slice($leak, 0, 6)) : ''));
$r = tool(DANA, 'get_issuer', ['issuer' => 'SMKD']);
$r0 = tool(DANA, 'get_issuer', ['issuer' => 'NOSUCHISSUER']);
ok($r['data']['error']['message'] === $r0['data']['error']['message'] && !str_contains($r['text'], 'restrict'), 'the words are the same as for an issuer that does not exist, and the answer never says "restricted"');
ok(tool(PRIYA, 'issuer_restrictions')['code'] === 'managers_only' && tool(DANA, 'tombstones')['code'] === 'managers_only', 'the restriction lists and the tombstone are a manager\'s: others are told so');
$ir = tool(MARCO, 'issuer_restrictions', ['issuer' => 'SMKD']);
ok(!$ir['error'] && count($ir['data']['rows']) === 1 && $ir['data']['rows'][0]['member_id'] === MARCO, 'and Marco reads the list: Delta names Marco alone');

echo "3. By words, nothing of Delta is found\n";
$lists = [
    ['search', ['q' => 'SECRET']], ['search', ['q' => 'SMOKE DELTA']], ['find_issuers', ['q' => 'DELTA']], ['find_securities', ['q' => 'SMKD']], ['find_securities', ['q' => '99900D101']], ['find_filings', ['q' => '0000999004']],
    ['find_filings', ['form' => ['8-K'], 'limit' => 100]], ['find_events', ['q' => 'SECRET']], ['find_signals', ['q' => 'SECRET', 'status' => ['open', 'acknowledged', 'dismissed', 'escalated', 'expired']]], ['dilution_instruments', ['q' => 'SECRET']],
    ['find_reports', ['q' => 'SECRET']], ['find_holders', ['q' => 'Delta']], ['find_imports', ['q' => 'DELTA']], ['find_notes', ['kind' => 'issuer', 'record' => $w['delta_issuer']]],
    ['records_search', ['view' => 'mcp_filings', 'filters' => ['issuer_id' => $w['delta_issuer']]]], ['records_search', ['view' => 'mcp_signals', 'filters' => ['issuer_id' => $w['delta_issuer']]]],
    ['records_search', ['view' => 'mcp_holders', 'filters' => ['issuer_id' => $w['delta_issuer']]]], ['records_search', ['view' => 'mcp_issuers', 'filters' => ['ticker' => 'SMKD']]],
    ['records_search', ['view' => 'mcp_participant_positions', 'filters' => ['security_id' => $dSec]]], ['records_search', ['view' => 'mcp_notes', 'filters' => ['record_type' => 'issuer', 'record_id' => $w['delta_issuer']]]],
    ['records_search', ['view' => 'mcp_short_interest', 'filters' => ['security_id' => $dSec]]], ['records_search', ['view' => 'mcp_reports', 'filters' => ['issuer_id' => $w['delta_issuer']]]],
    ['find_exports', []], ['find_institutions', ['q' => 'SMOKE']],
];
$bad = [];
foreach ([PRIYA, DANA, ANN, BEA] as $m) {
    foreach (run_as($m, $lists) as [$t, $a, $r]) {
        $rows = $r['data']['rows'] ?? [];
        $hit = str_contains($r['text'], 'DELTA SECRET') || str_contains($r['text'], 'SMKD') || str_contains($r['text'], 'SMOKE Delta') || str_contains($r['text'], '0000999004');
        if ($hit || ($r['data']['ok'] ?? true) === false && $r['code'] !== 'not_found' && $r['code'] !== 'no_data') { $bad[] = "$m:$t"; }
    }
}
ok($bad === [], count($lists) . ' list, search and records_search calls × four members off Delta\'s list: nothing of it in any answer (no name, ticker, CIK, secret text)' . ($bad ? ' — ' . implode(', ', array_slice($bad, 0, 6)) : ''));
$found = [];
foreach (['search' => ['q' => 'SECRET'], 'find_filings' => ['q' => '0000999004'], 'find_events' => ['q' => 'SECRET'], 'find_signals' => ['q' => 'SECRET', 'status' => ['open']], 'dilution_instruments' => ['q' => 'SECRET'], 'find_reports' => ['q' => 'SECRET'],
          'find_holders' => ['q' => 'Delta'], 'find_imports' => ['q' => 'DELTA']] as $t => $a) {
    $n = count(tdata(MARCO, $t, $a)['rows'] ?? []);
    if ($n < 1) { $found[] = $t; }
}
ok($found === [], 'the control: as Marco the same eight words DO find Delta\'s filing, event, signal, instrument, pack, holders and upload' . ($found ? ' — empty: ' . implode(', ', $found) : ''));
$amb = tool(PRIYA, 'get_issuer');
$ids = array_column($amb['data']['error']['candidates'] ?? [], 'label');
ok($amb['code'] === 'ambiguous' && count($ids) >= 3 && !array_filter($ids, fn ($l) => str_contains($l, 'DELTA') || str_contains($l, 'Beta')), 'with no issuer named and several visible: ambiguous, the candidates are the ones Priya may see (neither Beta nor Delta)');
$ambD = tool(DANA, 'get_issuer');
ok(count(array_filter(array_column($ambD['data']['error']['candidates'] ?? [], 'label'), fn ($l) => str_contains($l, 'Beta'))) + count($ambD['data']['error']['candidates'] ?? []) >= 1 && !array_filter(array_column($ambD['data']['error']['candidates'] ?? [], 'label'), fn ($l) => str_contains($l, 'DELTA')), 'and Dana\'s candidates never include Delta');

echo "4. Beta, restricted to Dana: Dana reads it, the others do not\n";
$beta = $w['beta'];
$betaSec = (int) one('SELECT id FROM securities WHERE issuer_id = :i AND is_primary', ['i' => $beta]);
ok(!tool(DANA, 'get_issuer', ['issuer' => 'SMKB'])['error'] && !tool(DANA, 'share_counts', ['security' => $betaSec])['error'], 'Dana reads Beta (its issuer and its share counts)');
ok(tool(PRIYA, 'get_issuer', ['issuer' => 'SMKB'])['code'] === 'not_found' && tool(ANN, 'share_counts', ['security' => $betaSec])['code'] === 'not_found' && tool(PRIYA, 'get_holder', ['holder' => $w['holder_beta']])['code'] === 'not_found', 'Priya and Ann get not_found for Beta');
ok(tool(MARCO, 'get_issuer', ['issuer' => 'SMKB'])['error'] === false, 'and the manager reads it');

echo "5. THE PII WALL: a Viewer never reads a natural person\n";
$persons = (int) one("SELECT count(*) FROM holders WHERE issuer_id = :i AND kind = 'person' AND deleted_at IS NULL", ['i' => $w['smok_issuer']]);
$aHold = tdata(ANN, 'find_holders', ['issuer' => 'SMOK', 'limit' => 100]);
$pHold = tdata(PRIYA, 'find_holders', ['issuer' => 'SMOK', 'limit' => 100]);
$annPersons = array_filter($aHold['rows'], fn ($r) => $r['kind'] === 'person');
ok($aHold['total'] === $pHold['total'] && count($annPersons) > 20 && count(array_filter($annPersons, fn ($r) => $r['display_name'] === 'Individual holder' && $r['pii_withheld'] === true && $r['city'] === null)) === count($annPersons),
    'Ann sees the same holders as Priya (' . $aHold['total'] . ') — and every natural person among them is "Individual holder", pii_withheld, with no city');
ok(count(array_filter($aHold['rows'], fn ($r) => $r['kind'] === 'person' && $r['zip'] !== null && strlen((string) $r['zip']) > 3)) === 0, '...the zip cut to three digits at most');
$pPersons = array_filter($pHold['rows'], fn ($r) => $r['kind'] === 'person');
ok(count($pPersons) > 20 && count(array_filter($pPersons, fn ($r) => $r['display_name'] !== 'Individual holder' && $r['pii_withheld'] === false)) === count($pPersons), 'Priya, an Analyst, sees their names (the control)');
$someName = array_values($pPersons)[0]['display_name'];
$firstWord = explode(' ', trim(strtolower($someName)))[0];
ok(count(tdata(ANN, 'find_holders', ['issuer' => 'SMOK', 'q' => $someName])['rows']) === 0 && count(tdata(PRIYA, 'find_holders', ['issuer' => 'SMOK', 'q' => $someName])['rows']) >= 1, 'a search by a person\'s name finds nothing for Ann and that holder for Priya');
$pid = (int) array_values($pPersons)[0]['holder_id'];
$entityId = (int) array_values(array_filter($aHold['rows'], fn ($r) => $r['kind'] !== 'person'))[0]['holder_id'];
foreach (['get_holder', 'holder_history'] as $t) {
    ok(tool(ANN, $t, ['holder' => $pid])['code'] === 'pii_withheld' && !tool(PRIYA, $t, ['holder' => $pid])['error'] && !tool(ANN, $t, ['holder' => $entityId])['error'], "$t: a natural person is pii_withheld for Ann, open for Priya; an entity is open to both");
}
$nobo = $w['nobo_import']; $reg = $w['register_import'];
ok(tool(ANN, 'import_rows', ['upload' => $nobo])['code'] === 'pii_withheld' && tool(ANN, 'import_rows', ['upload' => $reg])['code'] === 'pii_withheld' && !tool(PRIYA, 'import_rows', ['upload' => $nobo])['error'], 'import_rows: a NOBO list\'s and a register\'s rows are pii_withheld for Ann, open for Priya');
$sprRows = tool(ANN, 'import_rows', ['upload' => $w['upload_spr'], 'limit' => 3]);
ok(!$sprRows['error'] && count($sprRows['data']['rows']) === 3 && $sprRows['data']['kind'] === 'spr', 'an SPR\'s rows are brokers, not persons: open to Ann');
ok(tool(ANN, 'holders_unmatched')['code'] === 'pii_withheld' && tool(ANN, 'match_proposals')['code'] === 'pii_withheld' && !tool(PRIYA, 'holders_unmatched', ['issuer' => 'SMOK'])['error'] && !tool(PRIYA, 'match_proposals')['error'], 'holders_unmatched and match_proposals are an Analyst\'s');
$gi = tool(ANN, 'get_import', ['upload' => $nobo]);
$gp = tool(PRIYA, 'get_import', ['upload' => $nobo]);
ok(!$gi['error'] && $gi['data']['matcher'] === null && !empty($gi['data']['matcher_withheld']) && $gp['data']['matcher']['rows'] > 100, 'get_import: the matcher\'s counts are Priya\'s ('. ($gp['data']['matcher']['rows'] ?? 0) .' rows); Ann gets the totals without them');
$hc = tool(ANN, 'holders_changes', ['security' => '07831X206', 'channel' => 'register', 'date_a' => '2024-03-04', 'date_b' => '2024-04-04']);
$hp = tool(PRIYA, 'holders_changes', ['security' => '07831X206', 'channel' => 'register', 'date_a' => '2024-03-04', 'date_b' => '2024-04-04']);
ok(!$hc['error'] && !array_filter($hc['data']['rows'], fn ($r) => isset($r['holder_id'])) && array_sum(array_column($hc['data']['rows'], 'holders')) > 50 && !array_filter($hc['data']['entities'], fn ($r) => $r['kind'] === 'person'),
    'holders_changes: Ann gets the counts by state (no holder) and entities by name — never a person; Priya gets the holders (' . ($hp['data']['total'] ?? 0) . ')');
ok(isset($hp['data']['rows'][0]['holder_id']) && $hp['data']['total'] > 50, '...the control');
$ih = tool(ANN, 'insider_holdings', ['issuer' => 'SMOK']);
$ip = tool(PRIYA, 'insider_holdings', ['issuer' => 'SMOK']);
ok(!$ih['error'] && count($ih['data']['rows']) >= 1 && !array_filter($ih['data']['rows'], fn ($r) => $r['holder_id'] !== null || $r['register_shares'] !== null) && count($ip['data']['rows']) === count($ih['data']['rows']), 'insider_holdings: the Form 4 side is public record for Ann; the register link (holder_id, register_shares) is null for her');
$noteHolder = (int) one("SELECT record_id FROM notes WHERE body = 'p4 note on a person holder'");
ok(!tool(ANN, 'find_notes', ['kind' => 'holder', 'record' => $noteHolder])['error'] && count(tdata(ANN, 'find_notes', ['kind' => 'holder', 'record' => $noteHolder])['rows']) === 0 && count(tdata(PRIYA, 'find_notes', ['kind' => 'holder', 'record' => $noteHolder])['rows']) === 1, 'a note on a natural-person holder: invisible to Ann, Priya reads it');
$sr = tdata(ANN, 'search', ['q' => $someName, 'kinds' => ['holder'], 'limit' => 50]);
ok(count($sr['rows']) === 0 || !array_filter($sr['rows'], fn ($r) => stripos($r['title'], $firstWord) !== false && $r['title'] !== 'Individual holder'), 'search: a person\'s name finds no holder for Ann (a person reads as Individual holder with no headline)');
$sr2 = tdata(ANN, 'search', ['q' => 'SMOKE', 'kinds' => ['holder'], 'limit' => 50]);
ok(!array_filter($sr2['rows'], fn ($r) => $r['title'] === 'Individual holder' && $r['headline'] !== null), '...and an Individual holder row carries no headline');
$rs = tool(ANN, 'records_search', ['view' => 'mcp_register_rows']);
$rn = tool(ANN, 'records_search', ['view' => 'mcp_nobo_rows']);
$rp = tool(ANN, 'records_search', ['view' => 'mcp_holder_match_proposals']);
ok(count($rs['data']['rows']) === 0 && count($rn['data']['rows']) === 0 && count($rp['data']['rows']) === 0 && count(tdata(PRIYA, 'records_search', ['view' => 'mcp_register_rows'])['rows']) > 0, 'records_search over the raw views: empty for Ann (the view is the wall), full for Priya');
$rh = tdata(ANN, 'records_search', ['view' => 'mcp_holders', 'filters' => ['kind' => 'person'], 'limit' => 100]);
ok(count($rh['rows']) > 20 && !array_filter($rh['rows'], fn ($r) => $r['display_name'] !== 'Individual holder' || $r['address_line'] !== null || $r['city'] !== null), 'records_search over mcp_holders: the same nulled persons');

echo "6. A sweep: every tool, as Ann, against every natural person in the world\n";
$names = person_names();
$streets = street_lines();
ok(count($names) > 100 && count($streets) > 50, 'the world holds ' . count($names) . ' natural-person names and ' . count($streets) . ' street lines to look for');
$calls = array_merge(sweep($w), [['find_holders', ['issuer' => 'SMOK', 'limit' => 100]], ['holders_changes', ['security' => '07831X206', 'channel' => 'nobo', 'date_a' => '2024-03-01', 'date_b' => '2024-04-04']],
         ['search', ['q' => 'SMOKE', 'limit' => 100]], ['find_notes', ['kind' => 'holder', 'record' => $pid]], ['get_holder', ['holder' => $entityId]]]);
$asAnn = run_as(ANN, $calls);
$asPriya = run_as(PRIYA, $calls);
$actAnn = run_as(ANN, sweep_activity($w), ACT);
$blob = strtolower(texts($asAnn) . "\n" . texts($actAnn));
$found = [];
foreach (array_merge($names, $streets) as $n) { if (str_contains($blob, strtolower($n))) { $found[] = 1; foreach (array_merge($asAnn, $actAnn) as [$tn, , $rr]) { if (str_contains(strtolower($rr['text']), strtolower($n))) { $leakTools[$tn] = ($leakTools[$tn] ?? 0) + 1; } } } }
if ($found) { fwrite(STDERR, '   leaking tools: ' . json_encode($leakTools ?? []) . "\n"); }
ok(count($found) === 0, count($asAnn) + count($actAnn) . ' tool answers as Ann (' . strlen($blob) . ' bytes): not one of the ' . (count($names) + count($streets)) . ' names and street lines appears');
$blobP = strtolower(texts($asPriya));
$foundP = 0;
foreach ($names as $n) { if (str_contains($blobP, strtolower($n))) { $foundP++; } }
ok($foundP > 20, 'the control: Priya\'s answers to the same calls do carry names (' . $foundP . ' of them), so the sweep can see a leak');
$errs = array_filter($asAnn, fn ($r) => $r[2]['http'] !== 200 || ($r[2]['code'] === 'server_error'));
ok($errs === [], 'and no sweep call failed as Ann (the withheld ones answer pii_withheld, not an error)');

echo "7. The same walls for an agent: a run token is a member\n";
$g = agent_grants('analyst');
facts(runid(60), ANALYST, $g);
$tk = rtoken(ANALYST, runid(60));
$ah = mcp_tool(REC, $tk, 'find_holders', ['issuer' => 'SMOK', 'limit' => 5]);
ok(!$ah['error'] && count(array_filter($ah['data']['rows'], fn ($r) => $r['kind'] === 'person' && $r['display_name'] !== 'Individual holder')) >= 1, 'the Surveillance Analyst, an agent holding Analyst, reads holders as an Analyst does');
ok(mcp_tool(REC, $tk, 'get_issuer', ['issuer' => 'SMKD'])['code'] === 'not_found' && mcp_tool(REC, $tk, 'get_issuer', ['issuer' => 'SMKB'])['code'] === 'not_found', '...and meets the issuer wall like anyone off the list: Delta and Beta are not_found');
$ex = agent_grants('expert');
facts(runid(61), EXPERT, $ex);
ok(mcp_tool(REC, rtoken(EXPERT, runid(61)), 'get_issuer', ['issuer' => 'SMKD'])['code'] === 'not_found', 'and the Expert');
ok(mcp_tool(ACT, rtoken(ANALYST, runid(60)), 'record_history', ['kind' => 'issuer', 'record' => $w['delta_issuer']])['data']['count'] === 0, 'the activity server\'s wall is the same: Delta\'s trail is empty for the agent');
finish();
