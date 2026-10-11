<?php
/**
 * Proof — the five K7 shares and app_roles under the KERNEL'S token (docs/shareholder-intelligence-mcp-tool-surface.md "The shares"; design §8, D13): the kernel sends a share's arguments flat; each answers its versioned
 * document through the SECURITY DEFINER function of db/018 — as the application; an issuer with a restriction list is NEVER shared (not_found, by id, ticker or CIK alike), NO natural person crosses, the consumer comes from the
 * header the kernel adds (K26) and never an argument, and every call is logged share.read (db/028) — never the answer.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$people = array_merge(person_names(), street_lines(), ['Individual holder']);
/** A share as the kernel calls it: its own token, flat arguments, the consumer header. */
function kshare(string $tool, array $args, string $consumer = 'knowledge', array $more = []): array
{
    return mcp_tool(REC, kernel_token(), $tool, $args, true, array_merge($consumer === '' ? [] : ['X-OS-Consumer: ' . $consumer], $more));
}
function sans_clock($d)
{
    if (!is_array($d)) { return $d; }
    unset($d['generated_at']);
    foreach ($d as $k => $v) { if (is_array($v)) { $d[$k] = sans_clock($v); } }
    return $d;
}
function share_sql(string $call, array $args = []): array { $r = q("SELECT $call AS d", $args); return json_decode((string) $r[0]['d'], true); }
$texts = [];

echo "1. filings_index — os.si-filings/1 (Knowledge's feed)\n";
$s0 = (int) one("SELECT count(*) FROM activity_log WHERE action = 'share.read'");
$r = kshare('filings_index', ['issuer' => 'SMOK', 'limit' => 500, 'scope_id' => null, 'as_agent' => 99, 'consumer' => 'spoofed'], 'knowledge', ['X-Request-Id: req-p4-fi-1']);
$d = $r['data']; $texts[] = $r['text'];
ok(!$r['error'] && $d['schema'] === 'os.si-filings/1' && $d['application'] === 'shareholder_intelligence' && $d['issuer']['ticker'] === 'SMOK' && $d['total'] >= 5 && count($d['filings']) === $d['total'] && isset($d['filings'][0]['accession_number'], $d['filings'][0]['has_text'], $d['filings'][0]['about_us']),
    'the kernel\'s flat call answers the document (scope_id, as_agent and a consumer argument are ignored): ' . $d['total'] . ' filings');
ok(sans_clock($d) === sans_clock(share_sql("si_share_filings_index('knowledge', 'SMOK', NULL, NULL, 500, 0)")), 'and it is exactly the share function\'s document');
$l = last_event('share.read');
$a = json_decode((string) $l['after'], true);
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'share.read'") === $s0 + 1 && $l['source'] === 'application' && $l['actor_member_id'] === null && $l['entity_type'] === 'share' && $a['tool'] === 'filings_index' && $a['application'] === 'knowledge'
    && $a['issuer_id'] === $w['smok_issuer'] && $a['rows'] === $d['total'] && is_int($a['ms']) && $l['kernel_request_id'] === 'req-p4-fi-1' && (int) $l['issuer_id'] === $w['smok_issuer'], 'logged share.read: source application, no actor; the CONSUMER FROM THE HEADER ("knowledge", not "spoofed"), the tool, the issuer, the rows, the ms, the kernel\'s request id');
ok(!str_contains((string) $l['after'], '0000999') && !str_contains((string) $l['after'], 'Current report'), '...never a word of the answer');
$by = kshare('filings_index', ['issuer' => (string) $w['smok_issuer'], 'forms' => ['SC 13D'], 'limit' => 5]);
$texts[] = $by['text'];
ok(count($by['data']['filings']) >= 1 && !array_filter($by['data']['filings'], fn ($f) => $f['form'] !== 'SC 13D'), 'by id, narrowed to the 13D');
$bc = kshare('filings_index', ['issuer' => '0000999999', 'since' => '2024-03-01']);
ok($bc['data']['total'] >= 1 && !array_filter($bc['data']['filings'], fn ($f) => $f['filed_on'] < '2024-03-01'), 'by CIK, since a date');
$p2 = kshare('filings_index', ['issuer' => 'SMOK', 'limit' => 2, 'offset' => 2]);
ok(count($p2['data']['filings']) === 2 && $p2['data']['filings'][0]['filing_id'] === $d['filings'][2]['filing_id'] && $p2['data']['total'] === $d['total'], 'paged: limit and offset');
ok(kshare('filings_index', ['issuer' => 'SMOK', 'limit' => 501])['code'] === 'invalid_argument' && kshare('filings_index', [])['code'] === 'invalid_argument' && kshare('filings_index', ['issuer' => 'SMOK', 'since' => 'x'])['code'] === 'invalid_argument', 'limit 501, no issuer, a bad date: invalid_argument');

echo "2. filing_text — os.si-filing-text/1 (Knowledge's sectioner)\n";
$ft = kshare('filing_text', ['filing' => $w['filing_text']], 'knowledge', ['X-OS-Consumer-Agent: 1234']);
$texts[] = $ft['text'];
$dd = $ft['data'];
ok(!$ft['error'] && $dd['schema'] === 'os.si-filing-text/1' && $dd['filing_id'] === $w['filing_text'] && $dd['truncated'] === false && str_contains($dd['text'], 'MARKER-NEEDLE') && strlen($dd['text']) > 8000 && $dd['form'] === '8-K', 'the whole text of the 8-K, whole, not truncated');
ok(sans_clock($dd) === sans_clock(share_sql("si_share_filing_text('knowledge', :f)", ['f' => $w['filing_text']])), 'exactly the share function\'s document');
ok(kshare('filing_text', ['filing' => (int) one("SELECT id FROM filings WHERE issuer_id = :i AND fetched_text_at IS NULL LIMIT 1", ['i' => $w['smok_issuer']])])['data']['error'] === 'no_text', 'a filing whose text was not kept: no_text');
ok(kshare('filing_text', ['filing' => 999999999])['data']['error'] === 'not_found' && kshare('filing_text', ['filing' => 0])['code'] === 'invalid_argument', 'an unknown filing: not_found; id 0: invalid_argument');
$tl = json_decode((string) last_event('share.read')['after'], true);
$fo = kshare('filing_text', ['filing' => $w['filing_text']], 'knowledge');
ok(json_decode((string) last_event('share.read')['after'], true)['tool'] === 'filing_text' && json_decode((string) last_event('share.read')['after'], true)['rows'] === 1 && !str_contains((string) last_event('share.read')['after'], 'MARKER-NEEDLE'), 'logged without the text');

echo "3. ownership_summary — os.si-ownership/1\n";
$os = kshare('ownership_summary', ['issuer' => 'SMOK']);
$texts[] = $os['text'];
$d = $os['data'];
ok(!$os['error'] && $d['schema'] === 'os.si-ownership/1' && $d['ticker'] === 'SMOK' && $d['outstanding'] === 17100000 && $d['float'] === 12000000 && $d['dtc']['total'] > 0 && array_key_exists('reconciliation', $d) && $d['reconciliation'] !== null && isset($d['thirteen_f']['holders']) && is_array($d['beneficial_owners']) && isset($d['short']['pct_of_float']),
    'outstanding, float, the DTC total, the reconciliation, the 13F totals, the 5% holders, the short interest');
ok(isset($d['nobo']['holders']) && isset($d['obo']) && is_array($d['dtc']['by_side']), 'the NOBO and OBO counts and the DTC total by side');
ok(sans_clock($d) == sans_clock(share_sql("si_share_ownership_summary('knowledge', 'SMOK', NULL)")), 'exactly the share function\'s document');
$os2 = kshare('ownership_summary', ['issuer' => 'SMOK', 'as_of' => '2024-04-01']);
ok($os2['data']['as_of'] === '2024-04-01' && $os2['data']['outstanding'] === 17053900, 'as of an earlier day the older count');

echo "4. signals_open — os.si-signals/1, events_index — os.si-events/1\n";
$sg = kshare('signals_open', ['issuer' => 'SMOK']);
$texts[] = $sg['text'];
ok($sg['data']['schema'] === 'os.si-signals/1' && count($sg['data']['signals']) >= 12 && isset($sg['data']['signals'][0]['evidence_class'], $sg['data']['signals'][0]['rule'], $sg['data']['signals'][0]['summary']) && in_array($sg['data']['signals'][0]['severity'], ['high', 'watch', 'info'], true) && $sg['data']['signals'][0]['severity'] === 'high',
    'the open signals with rule, evidence class and severity, high first');
ok(kshare('signals_open', ['issuer' => 'SMKG'])['data']['signals'] === [], 'an issuer with nothing open: an empty list, not an error');
$ev = kshare('events_index', ['issuer' => 'SMOK', 'limit' => 10]);
$texts[] = $ev['text'];
ok($ev['data']['schema'] === 'os.si-events/1' && count($ev['data']['events']) >= 4 && $ev['data']['events'][0]['on_date'] >= $ev['data']['events'][count($ev['data']['events']) - 1]['on_date'], 'the events, newest first');
ok(count(kshare('events_index', ['issuer' => 'SMOK', 'from' => '2024-04-03', 'to' => '2024-04-03'])['data']['events']) === 1, 'narrowed by dates: the one event');

echo "5. A restricted issuer is never shared; an unknown consumer is refused\n";
$bad = [];
foreach (['SMKB' => 'Beta by ticker', (string) $w['beta'] => 'Beta by id', '0000999002' => 'Beta by CIK', 'SMKD' => 'Delta by ticker', (string) $w['delta_issuer'] => 'Delta by id', '0000999004' => 'Delta by CIK'] as $ref => $how) {
    foreach (['filings_index', 'ownership_summary', 'signals_open', 'events_index'] as $t) {
        $a = kshare($t, ['issuer' => (string) $ref]);
        $texts[] = $a['text'];
        if (($a['data']['error'] ?? '') !== 'not_found') { $bad[] = "$how:$t"; }
    }
}
ok($bad === [], 'Beta (restricted to Dana) and Delta (to Marco) by ticker, id and CIK: not_found from every share' . ($bad ? ' — ' . implode(', ', array_slice($bad, 0, 4)) : ''));
ok(kshare('filing_text', ['filing' => $w['delta_filing']])['data']['error'] === 'not_found', 'and the text of a restricted issuer\'s filing: not_found');
ok(!str_contains(implode("\n", $texts), 'DELTA SECRET'), 'no word of Delta in any answer');
ok(kshare('filings_index', ['issuer' => 'SMOK'], '')['data']['error'] === 'consumer_unknown' && kshare('signals_open', ['issuer' => 'SMOK'], '')['data']['error'] === 'consumer_unknown', 'no X-OS-Consumer header: consumer_unknown, nothing shared');
ok(kshare('filings_index', ['issuer' => 'NOSUCH'])['data']['error'] === 'not_found', 'an unknown issuer: not_found');
$hdr = kshare('filings_index', ['issuer' => 'SMOK'], 'Bad Consumer!');
ok($hdr['data']['error'] === 'consumer_unknown', 'a header that is not a catalog key is no consumer');

echo "6. No natural person in any answer\n";
$all = strtolower(implode("\n", $texts));
$hit = [];
foreach ($people as $n) { if (str_contains($all, strtolower($n))) { $hit[] = 1; } }
ok(count($hit) === 0, count($texts) . ' share answers searched for ' . count($people) . ' natural-person names and street lines: none found');
ok(!has_key($os['data'], 'display_name') && !has_key($os['data'], 'registration_text') && !has_key($os['data'], 'holder_id') && !has_key($os['data'], 'address_line'), 'and no holder key at all in the ownership summary');

echo "7. Every call is logged; the server holds no table\n";
$n = (int) one("SELECT count(*) FROM activity_log WHERE action = 'share.read' AND id > :s", ['s' => 0]);
ok($n >= 30, "share.read rows: $n");
$blob = (string) one("SELECT string_agg(coalesce(after::text, ''), ' ') FROM activity_log WHERE action = 'share.read'");
ok(!str_contains($blob, 'MARKER-NEEDLE') && !str_contains($blob, 'SMOKE ISSUER INC') && !str_contains($blob, 'Current report'), 'and not a word of any answer is in the log');
$err = '';
try { $rec = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', need('DB_HOST'), need('DB_PORT'), need('DB_NAME')), need('MCP_RECORDS_DB_USER'), need('MCP_RECORDS_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); $rec->query('SELECT count(*) FROM holders'); }
catch (Throwable $e) { $err = $e->getMessage(); }
ok(str_contains($err, 'permission denied'), 'the records role cannot read a base table (permission denied on holders): the views and the functions are its only doors');
$err = '';
try { $rec->query("INSERT INTO activity_log (action) VALUES ('x')"); } catch (Throwable $e) { $err = $e->getMessage(); }
ok(str_contains($err, 'permission denied'), '...nor write the trail directly: si_log_mcp_read() is the one write, and it refuses any other action');
$err = '';
try { $rec->query("SELECT si_log_mcp_read('holder.merge', '{}'::jsonb)"); } catch (Throwable $e) { $err = $e->getMessage(); }
ok(str_contains($err, 'Not an action the read servers log'), 'si_log_mcp_read refuses an action other than share.read and records.search');

echo "8. app_roles under the kernel's token\n";
$ar = mcp_tool(REC, kernel_token(), 'app_roles', [], true);
ok(!$ar['error'] && count($ar['data']['roles']) === 4 && $ar['data']['roles'][3]['is_admin'] === true, 'the kernel reads the four roles and their rights');
finish();
