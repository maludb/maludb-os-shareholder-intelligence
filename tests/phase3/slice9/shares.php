<?php
/**
 * Proof: the five K7 shares (agents-k7-api.md "Proof"; design §8) — answered as the application through db/018's functions by a connection that is the RECORDS role (no table of its own): the filings index, a
 * filing's text, the ownership summary, the open signals and the events, for a consumer the kernel names. A restricted issuer is `not_found` however it is asked for (by id, ticker or CIK); an empty consumer is refused
 * by the function; NO natural person is in any answer (the proof greps every answer for the fixture's people); each read is logged `share.read` {application, tool, issuer_id, rows, ms} — never the answer.
 */
require __DIR__ . '/lib.php';
$ids = seed9();
$alpha = $ids['alpha'];
$beta = $ids['beta'];
$since = last_activity_id();
$people = ['Frank', 'Person', 'Plain Holder', 'Priya', 'Marco', 'Dana', 'Zeta', 'Orion', 'Harbor', 'Beacon', 'FRANK'];
/** One share, called inside the application with a records-role connection (its environment: MCP_RECORDS_DB_*). */
$call = static function (string $tool, string $consumer, array $args): array {
    $code = '$rec = new PDO(sprintf("pgsql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT"), getenv("DB_NAME")), getenv("MCP_RECORDS_DB_USER"), getenv("MCP_RECORDS_DB_PASSWORD"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
             require_once ' . var_export(dirname(__DIR__, 3) . '/app/features/agents/queries.php', true) . ';
             echo json_encode(share_call($rec, ' . var_export($tool, true) . ', ' . var_export($consumer, true) . ', json_decode(' . var_export(json_encode($args), true) . ', true)));';
    $r = app_php($code);
    return is_array($r) ? $r : ['_raw' => $r];
};
$texts = [];

echo "1. The filings index and a filing's text\n";
$fi = $call('filings_index', 'helpdesk', ['issuer' => 'SMKA']);
$texts[] = json_encode($fi);
ok(($fi['schema'] ?? '') === 'os.si-filings/1' && ($fi['application'] ?? '') === 'shareholder_intelligence' && ($fi['issuer']['ticker'] ?? '') === 'SMKA' && ($fi['total'] ?? 0) === 2 && count($fi['filings'] ?? []) === 2, 'filings_index by ticker: the schema, the issuer, two filings — ' . substr(json_encode($fi), 0, 160));
$forms = array_column($fi['filings'] ?? [], 'form');
ok($forms === ['SC 13D', '8-K'] && ($fi['filings'][0]['has_text'] ?? null) === true && ($fi['filings'][1]['has_text'] ?? null) === false, 'newest first; the 13D has its text, the 8-K not');
$by = $call('filings_index', 'helpdesk', ['issuer' => (string) $alpha, 'forms' => ['8-K'], 'limit' => 5]);
$texts[] = json_encode($by);
ok(count($by['filings'] ?? []) === 1 && $by['filings'][0]['form'] === '8-K', 'by id, narrowed to the 8-K');
$byCik = $call('filings_index', 'helpdesk', ['issuer' => '0000999001', 'since' => date('Y-m-d', strtotime('-3 days'))]);
ok(count($byCik['filings'] ?? []) === 1 && $byCik['filings'][0]['form'] === 'SC 13D', 'by CIK, since a date: the 13D alone');
$ft = $call('filing_text', 'knowledge', ['filing' => $ids['filing_13d']]);
$texts[] = json_encode($ft);
ok(($ft['schema'] ?? '') === 'os.si-filing-text/1' && str_contains((string) ($ft['text'] ?? ''), 'intends to engage with the board') && ($ft['truncated'] ?? null) === false && ($ft['form'] ?? '') === 'SC 13D', 'filing_text: the whole text, whole');
$nt = $call('filing_text', 'knowledge', ['filing' => $ids['filing_8k']]);
ok(($nt['error'] ?? '') === 'no_text', 'a filing with no text kept: no_text');

echo "2. The ownership summary, the open signals, the events\n";
$os = $call('ownership_summary', 'gl', ['issuer' => 'SMKA']);
$texts[] = json_encode($os);
ok(($os['schema'] ?? '') === 'os.si-ownership/1' && isset($os['outstanding'], $os['dtc']['total']) && array_key_exists('reconciliation', $os) && (float) $os['dtc']['total'] === 10000000.0 && ($os['ticker'] ?? '') === 'SMKA', 'ownership_summary: outstanding, the DTC total, the reconciliation key (null until one is run)');
$sg = $call('signals_open', 'gl', ['issuer' => 'SMKA']);
$texts[] = json_encode($sg);
$r0 = $sg['signals'][0] ?? [];
ok(($sg['schema'] ?? '') === 'os.si-signals/1' && count($sg['signals'] ?? []) >= 1 && ($r0['rule'] ?? '') === 'si_over_float' && in_array($r0['evidence_class'] ?? '', ['established', 'contested', 'lore', 'data_defect'], true) && ($r0['severity'] ?? '') === 'high',
    'signals_open: the open signal with its rule, its evidence class and its severity');
$ev = $call('events_index', 'spaces', ['issuer' => 'SMKA', 'limit' => 10]);
$texts[] = json_encode($ev);
ok(($ev['schema'] ?? '') === 'os.si-events/1' && count($ev['events'] ?? []) >= 3 && $ev['events'][0]['on_date'] >= $ev['events'][count($ev['events']) - 1]['on_date'], 'events_index: the events, newest first');
$ev2 = $call('events_index', 'spaces', ['issuer' => 'SMKA', 'from' => date('Y-m-d', strtotime('-9 days')), 'to' => date('Y-m-d', strtotime('-7 days'))]);
ok(count($ev2['events'] ?? []) === 1 && str_contains($ev2['events'][0]['title'], 'answers the report'), 'narrowed by dates: the one event');

echo "3. A restricted issuer is never shared; an unknown consumer is refused\n";
foreach (['SMKB' => 'ticker', (string) $beta => 'id', '0000999002' => 'CIK'] as $ref => $how) {
    $bad = [];
    foreach (['filings_index', 'ownership_summary', 'signals_open', 'events_index'] as $tool) {
        $a = $call($tool, 'helpdesk', ['issuer' => $ref]);
        $texts[] = json_encode($a);
        if (($a['error'] ?? '') !== 'not_found') { $bad[] = $tool; }
    }
    ok($bad === [], "Beta (restricted) asked for by $how: not_found from every share" . ($bad ? ' — ' . implode(',', $bad) : ''));
}
$betaFiling = (int) q("INSERT INTO filings (issuer_id, filer_cik, accession_number, form, filed_on) VALUES (:i, '0000999002', '0000999002-24-000001', '8-K', CURRENT_DATE) RETURNING id", ['i' => $beta])[0]['id'];
q("INSERT INTO filing_text (filing_id, extractor, text) VALUES (:f, 'html', 'Beta text')", ['f' => $betaFiling]);
ok(($call('filing_text', 'knowledge', ['filing' => $betaFiling])['error'] ?? '') === 'not_found', 'and the text of a restricted issuer\'s filing: not_found');
ok(($call('filings_index', '', ['issuer' => 'SMKA'])['error'] ?? '') === 'consumer_unknown', 'an empty consumer (no X-OS-Consumer): consumer_unknown');
ok(($call('filings_index', 'helpdesk', ['issuer' => 'NOSUCH'])['error'] ?? '') === 'not_found', 'an unknown issuer: not_found');
ok(($call('signals_open', 'helpdesk', ['issuer' => 'SMKG'])['signals'] ?? null) === [], 'an issuer with nothing open: an empty list, not an error');
$bad = $call('holders_index', 'helpdesk', ['issuer' => 'SMKA']);
ok(str_contains(json_encode($bad), 'Not one of the shares'), 'a tool that is not one of the five: refused');

echo "4. No natural person in any answer; the records role has no tables\n";
$all = implode("\n", $texts);
$hit = array_values(array_filter($people, static fn ($n) => stripos($all, $n) !== false));
ok($hit === [], 'every answer searched for the fixture\'s people: none found' . ($hit ? ' — ' . implode(', ', $hit) : ''));
$err = '';
try { $rec = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', need('DB_HOST'), need('DB_PORT'), need('DB_NAME')), need('MCP_RECORDS_DB_USER'), need('MCP_RECORDS_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); $rec->query('SELECT count(*) FROM holders'); }
catch (Throwable $e) { $err = $e->getMessage(); }
ok(str_contains($err, 'permission denied'), 'the records role cannot read a base table: the shares are the only door (permission denied on holders)');

echo "5. Every read is logged, never the answer\n";
$rows = activity('share.read', $since);
$a = json_decode($rows[0]['after'] ?? '{}', true);
ok(count($rows) >= 14 && $rows[0]['source'] === 'application' && $rows[0]['actor_member_id'] === null && ($a['application'] ?? '') === 'helpdesk' && ($a['tool'] ?? '') === 'filings_index' && ($a['issuer_id'] ?? 0) === $alpha && ($a['rows'] ?? -1) === 2 && is_int($a['ms'] ?? null),
    'share.read: the consumer, the tool, the issuer, the rows, the milliseconds — source application, no actor: ' . count($rows) . ' reads');
$blob = (string) one("SELECT string_agg(coalesce(after::text, ''), ' ') FROM activity_log WHERE action = 'share.read' AND id > :s", ['s' => $since]);
ok(!str_contains($blob, 'intends to engage') && !str_contains($blob, 'SMOKE:'), 'and not a word of any answer is in the log');
finish();
