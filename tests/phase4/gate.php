<?php
/**
 * Proof — the gate: the three token shapes and what each is offered (docs/shareholder-intelligence-mcp-tool-surface.md "Who may call"). A person's own mcp_ token sees every tool but the five shares (the kernel's alone);
 * a token of scope api (a records-API key) is refused; the tenant's signed action/run tokens name a member the mirror admits (an unknown id is refused, never created); an agent's run token is offered exactly the tools
 * the (fake) kernel's run-facts names for THIS endpoint, fail closed when the kernel is down, does not vouch, or the application's token is missing; the kernel's own token reaches app_roles and the five shares only.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$S = surface();
$root = dirname(__DIR__, 2);

echo "1. The surface: every tool the document names, offered to a person, annotated read-only\n";
$all = mcp_tools(REC, ptoken(PRIYA));
$acts = mcp_tools(ACT, ptoken(PRIYA));
ok(count($S['records']) === 95 && count($S['activity']) === 7 && count($S['shares']) === 5, 'the surface names 95 records tools (88 + app_roles + records_search + the five shares) and 7 activity tools');
ok($all !== null && $all === $S['person'] && count($all) === 90, 'a person is offered the 90 records tools of the surface — everything but the five shares, which are the kernel\'s alone');
ok($acts === $S['activity'], 'and the seven activity tools');
$defs = mcp_tool_defs(REC, ptoken(PRIYA));
$ro = array_filter($defs, fn ($t) => ($t['annotations']['readOnlyHint'] ?? false) === true);
ok(count($ro) === count($defs), 'every records tool carries readOnlyHint true');
$open = array_filter($defs, fn ($t) => ($t['annotations']['openWorldHint'] ?? true) !== false);
ok($open === [], 'and openWorldHint false: nothing reaches outside the business');
$actDefs = mcp_tool_defs(ACT, ptoken(PRIYA));
ok(count(array_filter($actDefs, fn ($t) => ($t['annotations']['readOnlyHint'] ?? false) === true && ($t['annotations']['openWorldHint'] ?? true) === false)) === 7, 'the activity tools are all read-only and closed-world');
$noDesc = array_filter(array_merge($defs, $actDefs), fn ($t) => strlen((string) ($t['description'] ?? '')) < 40);
ok($noDesc === [], 'every tool says what it is for (a description an agent can choose by)');
$undescribed = [];
$noExtra = [];
foreach (array_merge($defs, $actDefs) as $t) {
    $schemas = $t['inputSchema']['$defs'] ?? [];
    foreach ($schemas as $name => $d) {
        foreach (($d['properties'] ?? []) as $pn => $pv) { if (empty($pv['description'])) { $undescribed[] = "$name.$pn"; } }
        if (($d['additionalProperties'] ?? true) !== false) { $noExtra[] = $name; }
    }
}
ok($undescribed === [], 'every field of every parameter model has a description' . ($undescribed ? ': ' . implode(', ', array_slice($undescribed, 0, 5)) : ''));
ok($noExtra === [], 'every parameter model forbids extra fields (extra="forbid")');
$bad = tool(PRIYA, 'find_signals', ['limit' => 0, 'bogus' => 'SECRET-ECHO-TEST']);
ok($bad['code'] === 'invalid_argument' && $bad['data']['error']['retry'] === false && in_array('limit', $bad['data']['error']['fields'], true) && in_array('bogus', $bad['data']['error']['fields'], true) && !str_contains($bad['text'], 'SECRET-ECHO-TEST'),
    'a parameter that fails validation is an ANSWER: invalid_argument naming the fields (limit, bogus), and what was sent is not echoed');
$bad2 = tool(PRIYA, 'positions_on', ['security' => '07831X206', 'date' => 'not-a-date']);
ok($bad2['code'] === 'invalid_argument' && in_array('date', $bad2['data']['error']['fields'] ?? [], true), 'a date that is not a date: invalid_argument naming it');

echo "2. Who is refused\n";
ok(mcp_tools(REC, '') === null, 'no bearer: 401');
ok(mcp_tools(REC, 'mcp_' . str_repeat('0', 48)) === null, 'a token nobody minted: 401');
ok(mcp_tools(REC, api_token(PRIYA)) === null && mcp_tools(ACT, api_token(PRIYA)) === null, 'a key of scope api (a records-API key) is not a token for the MCP servers: 401 on both');
ok(mcp_tools(REC, atoken(PRIYA, -5)) === null, 'an expired action token: 401');
$p = PRIYA . '.' . (time() + 300);
ok(mcp_tools(REC, $p . '.' . hash_hmac('sha256', $p, 'not-the-key')) === null, 'an action token signed with another key: 401');
ok(mcp_tools(REC, atoken(9999)) === null, 'an action token for a member the mirror does not know: 401 (an unknown id is refused, never created)');
ok((int) one('SELECT count(*) FROM members WHERE id = 9999') === 0, '...and was not created');
ok(mcp_tools(REC, rtoken(9999, runid(5))) === null && (int) one('SELECT count(*) FROM members WHERE id = 9999') === 0, 'a run token for an unknown member: 401, nobody created');
ok(mcp_tools(REC, kernel_token('someoneelse')) === null, 'a kernel token bound to another application: 401');
ok(mcp_tools(ACT, ptoken(PRIYA) . 'x') === null, 'a token with a letter changed: 401');
q("UPDATE members SET status = 'inactive' WHERE id = :m", ['m' => LEE]);
ok(mcp_tools(REC, ptoken(LEE)) === null && mcp_tools(REC, atoken(LEE)) === null, 'a member the directory made inactive: both token shapes are refused');
q("UPDATE members SET status = 'active' WHERE id = :m", ['m' => LEE]);

echo "3. A person: their own token, or the signed action token of the command bar\n";
ok(mcp_tools(REC, atoken(DANA)) === $S['person'], 'Dana\'s action token is offered the same tools');
ok(mcp_tools(REC, ptoken(ANN)) === $S['person'], 'the Viewer Ann\'s token is offered them too (the views decide what she sees, not the list)');
$roles = tool(ANN, 'app_roles');
ok($roles['data']['schema'] === 'os.app-roles/1' && $roles['data']['application'] === 'shareholder_intelligence' && count($roles['data']['roles']) === 4, 'app_roles answers os.app-roles/1 with the four roles');
ok(array_column($roles['data']['roles'], 'key') === ['viewer', 'user', 'manager', 'admin'] && count(array_filter($roles['data']['roles'], fn ($r) => $r['is_admin'])) === 1 && count($roles['data']['rights']) === (int) one('SELECT count(*) FROM si_rights'), 'Viewer, Analyst (user), IR Manager, SI admin — one is_admin — and every right of the catalogue');
$manifest = json_decode((string) file_get_contents($root . '/maludb-os.json'), true);
$mr = array_column($manifest['roles'], 'rights', 'key');
$same = true;
foreach ($roles['data']['roles'] as $r) { $a = $r['rights']; $b = $mr[$r['key']] ?? []; sort($a); sort($b); $same = $same && $a === $b; }
ok($same, 'and each role\'s rights are the ones maludb-os.json declares');

echo "4. An agent: only what the kernel's run-facts names for THIS endpoint\n";
$grants = ['Records MCP' => ['find_issuers' => [], 'positions_on' => [], 'get_signal' => []], 'Activity MCP' => ['record_history' => []]];
facts(runid(1), ANALYST, $grants);
$t = rtoken(ANALYST, runid(1));
ok(mcp_tools(REC, $t) === ['find_issuers', 'get_signal', 'positions_on'], 'the Surveillance Analyst (run 1) is offered exactly the three Records tools the kernel granted');
ok(mcp_tools(ACT, $t) === ['record_history'], '...and on the Activity server exactly its one');
$ok = mcp_tool(REC, $t, 'find_issuers', ['q' => 'SMOKE']);
ok(!$ok['error'] && $ok['data']['count'] >= 1, 'a granted tool answers');
$no = mcp_tool(REC, $t, 'find_holders', ['q' => 'x']);
ok($no['error'] && str_contains($no['text'], 'not among the tools this agent was granted'), 'a tool it was not granted is refused in words');
ok(mcp_tool(REC, $t, 'app_roles')['error'], 'app_roles was not granted either: refused');
ok(mcp_tool(REC, $t, 'filings_index', ['issuer' => 'SMKA'])['error'], 'filings_index (a share) is not callable by an agent, granted or not');
facts(runid(2), ANALYST, ['Records MCP' => ['find_issuers' => []]], ['valid' => false]);
ok(mcp_tools(REC, rtoken(ANALYST, runid(2))) === [], 'the kernel says valid:false for run 2: nothing is offered (fail closed)');
ok(mcp_tool(REC, rtoken(ANALYST, runid(2)), 'find_issuers', ['q' => 'x'])['error'], '...and nothing can be called');
ok(mcp_tools(REC, rtoken(ANALYST, runid(9))) === [], 'a run the kernel has never heard of (9): nothing is offered');
facts(runid(3), ANALYST, ['Another MCP' => ['find_issuers' => []]]);
ok(mcp_tools(REC, rtoken(ANALYST, runid(3))) === [], 'grants on another endpoint are not grants here');
foreach (['expert' => [EXPERT, 83, 4], 'analyst' => [ANALYST, 50, 3]] as $key => [$mid, $nRec, $nAct]) {
    $g = agent_grants($key);
    $recTools = array_keys($g['Records MCP']); sort($recTools);
    $actTools = array_keys($g['Activity MCP']); sort($actTools);
    facts(runid(4 + $mid), $mid, $g);
    ok(mcp_tools(REC, rtoken($mid, runid(4 + $mid))) === $recTools && count($recTools) === $nRec, "the shipped $key's grants (maludb-os.json): $nRec Records tools offered, exactly");
    ok(mcp_tools(ACT, rtoken($mid, runid(4 + $mid))) === $actTools && count($actTools) === $nAct, "...and its $nAct Activity tools");
    ok(array_values(array_intersect($recTools, $S['shares'])) === [] && !in_array('app_roles', $recTools, true), "...and none of the five shares nor app_roles among them");
}

echo "5. First contact: an agent the mirror has but has not admitted\n";
ok((string) one('SELECT capability FROM members WHERE id = :m', ['m' => WATCHER]) === '', 'the Watcher is in the mirror and not admitted yet');
ok(mcp_tools(REC, rtoken(WATCHER, runid(10))) === null, 'a run token the kernel does not vouch for: 401');
facts(runid(11), WATCHER, []);
ok(mcp_tools(REC, rtoken(WATCHER, runid(11))) === null, 'vouched but with no endpoints of this application: still 401');
ok((string) one('SELECT capability FROM members WHERE id = :m', ['m' => WATCHER]) === '', '...and not admitted');
facts(runid(12), WATCHER, ['Records MCP' => ['find_issuers' => []]]);
ok(mcp_tools(REC, rtoken(WATCHER, runid(12))) === ['find_issuers'], 'vouched with endpoints here: admitted on first contact and offered its one tool');
ok((string) one('SELECT capability FROM members WHERE id = :m', ['m' => WATCHER]) === 'write', 'the mirror now admits the Watcher (mcp_admit_agent)');
q('UPDATE members SET capability = NULL WHERE id = :m', ['m' => WATCHER]);

echo "6. The kernel down, or the application's token missing: fail closed\n";
facts(runid(20), ANALYST, ['Records MCP' => ['find_issuers' => []]]);
kernel_ctl('stop');
ok(mcp_tools(REC, rtoken(ANALYST, runid(20))) === [], 'with the kernel unreachable an agent run is offered nothing');
ok(mcp_tool(REC, rtoken(ANALYST, runid(20)), 'find_issuers', ['q' => 'x'])['error'], '...and can call nothing');
ok(mcp_tools(REC, rtoken(WATCHER, runid(21))) === null, 'a first contact cannot be vouched for: 401');
ok(mcp_tools(REC, ptoken(PRIYA)) === $S['person'], 'a PERSON is not the kernel\'s to judge: their token still works');
kernel_ctl('start');
ok(mcp_tools(REC, rtoken(ANALYST, runid(20))) === ['find_issuers'], 'the kernel back: the same run token is offered its tool again (the failure was not cached)');
mcp_ctl('variant notoken');
facts(runid(22), ANALYST, ['Records MCP' => ['find_issuers' => []]]);
ok(mcp_tools(VARIANT, rtoken(ANALYST, runid(22))) === [], 'a server with no OS_APPLICATION_TOKEN cannot ask the kernel: an agent run is offered nothing');
ok(mcp_tool(VARIANT, rtoken(ANALYST, runid(22)), 'find_issuers', ['q' => 'x'])['error'], '...and can call nothing');
ok(mcp_tools(VARIANT, rtoken(WATCHER, runid(23))) === null, '...and a first contact is refused');
ok(mcp_tools(VARIANT, ptoken(PRIYA)) === $S['person'], '...while a person\'s token works as before');
mcp_ctl('variant stop');

echo "7. An evaluation run reads (these servers never write)\n";
facts(runid(30), ANALYST, ['Records MCP' => ['find_issuers' => [], 'find_signals' => []]], ['trigger' => 'eval']);
$e = mcp_tool(REC, rtoken(ANALYST, runid(30)), 'find_signals', ['issuer' => 'SMOK']);
ok(!$e['error'] && $e['data']['count'] >= 1, 'an eval run reads (reads are reads)');

echo "8. The kernel's own token: app_roles and the five shares, nothing else\n";
$kt = kernel_token();
ok(mcp_tools(REC, $kt) === ['app_roles', 'events_index', 'filing_text', 'filings_index', 'ownership_summary', 'signals_open'], 'it is offered app_roles and the five shares');
ok(mcp_tools(ACT, $kt) === [], 'and nothing on the activity server');
$ar = mcp_tool(REC, $kt, 'app_roles', [], true);
ok(!$ar['error'] && $ar['data']['schema'] === 'os.app-roles/1', 'app_roles answers it');
foreach (['find_issuers' => ['q' => 'x'], 'get_signal' => ['signal' => 1], 'records_search' => ['view' => 'mcp_issuers'], 'find_holders' => ['q' => 'x'], 'search' => ['q' => 'x']] as $tn => $a) {
    $r = mcp_tool(REC, kernel_token(), $tn, $a);
    ok($r['error'] && str_contains($r['text'], 'reaches'), "the kernel's token cannot call $tn");
}

echo "9. A person cannot call a share, nor list one\n";
$r = mcp_tool(REC, ptoken(MARCO), 'ownership_summary', ['issuer' => 'SMOK']);
ok($r['error'] && str_contains($r['text'], "kernel's own token"), 'ownership_summary as the IR Manager: refused — "for the Business OS kernel\'s own token only"');
ok(!in_array('filing_text', mcp_tools(REC, ptoken(OWNER)) ?? [], true), 'and not even the super-admin is offered filing_text');

if (getenv('SI_APP') === 'apache') {
    echo "10. Through the vhost's own proxy lines (Apache)\n";
    ok(mcp_tools(8901, ptoken(PRIYA), [], '/mcp/records') === $S['person'], '/mcp/records on the public vhost reaches the records server');
    ok(mcp_tools(8901, ptoken(PRIYA), [], '/mcp/activity') === $S['activity'], '/mcp/activity reaches the activity server');
    ok(mcp_tools(8901, '', [], '/mcp/records') === null, '...and a call with no bearer is refused there too');
}
finish();
