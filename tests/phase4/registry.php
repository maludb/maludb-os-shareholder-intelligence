<?php
/**
 * Proof — the registry wrapper and the manifest, against the KERNEL's own code (read only): deploy/kernel-registry-shareholder_intelligence.json + mcp/action_registry.json, composed as the installer writes
 * mcp/registries/shareholder_intelligence.json, are loaded by the kernel's loader (application_actions.load_registries) and registered on a FastMCP exactly as the kernel's actions server does
 * (application_actions.register): a tool per built action, approval categories the kernel knows, the `confirmed` guard on destructive ones; every resolve entity is answered by THIS application's live records server through
 * the kernel's own resolver (application_actions.resolve_via_mcp) with the caller's token; and whole actions are driven through the kernel's tool (the approval hook and the handler stubbed — nothing is written): an agent's
 * report_send is a paused action. Then maludb-os.json: the agents' grants name only tools and actions that exist, and the Analyst's duty is declared. K55: our action names that other registries also use are listed.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$S = surface();
$root = dirname(__DIR__, 2);
$wrapper = json_decode((string) file_get_contents($root . '/deploy/kernel-registry-shareholder_intelligence.json'), true);
$reg = json_decode((string) file_get_contents($root . '/mcp/action_registry.json'), true);
$manifest = json_decode((string) file_get_contents($root . '/maludb-os.json'), true);
$actions = $reg['actions'];
$KNOWN_CATEGORIES = ['money_out', 'deletion', 'external_send', 'other'];
$KERNEL_TOOLS = ['message_send', 'inbox_read'];

echo "1. The wrapper, the registry and the manifest read right\n";
ok(($wrapper['schema'] ?? '') === 'maludb-os.registry/1' && $wrapper['app_key'] === 'shareholder_intelligence' && count($wrapper['resolve']) === 17, 'the wrapper: schema maludb-os.registry/1, app_key shareholder_intelligence, seventeen resolve entities');
$php = (int) shell_exec('cd ' . escapeshellarg($root) . ' && php bin/build_action_registry.php --check >/dev/null 2>&1; echo $?');
ok($php === 0, 'the registry and maludb-os.json approvals[] are current with the action manifest (bin/build_action_registry.php --check)');
ok(count($actions) === 83 && count(array_filter($actions, fn ($x) => !empty($x['built']))) === 83, 'the registry holds 83 actions, every one built');
$cats = array_unique(array_filter(array_column($actions, 'approval')));
$withCat = count(array_filter($actions, fn ($x) => $x['approval'] !== null));
ok(count(array_diff($cats, $KNOWN_CATEGORIES)) === 0 && $withCat > 20, 'every approval category is one the kernel knows (' . implode(', ', $cats) . '); ' . $withCat . ' actions carry one');
$missing = [];
foreach ($actions as $key => $x) { $p = $root . '/html' . parse_url($x['endpoint'], PHP_URL_PATH); if (!is_file($p) && !is_file(preg_replace('/\{[a-z_]+\}/', '1', $p))) { $missing[] = $key . ' → ' . $x['endpoint']; } }
ok($missing === [], 'every action\'s endpoint exists under html/' . ($missing ? ' — missing: ' . implode(', ', array_slice($missing, 0, 5)) : ''));
$phantom = [];
foreach ($actions as $key => $x) { foreach ($x['params'] as $p) { if (($p['name'] ?? null) === null) { $phantom[] = $key; } } }
ok($phantom === [], 'no action carries a phantom parameter');
ok(array_filter($actions, fn ($x) => empty($x['log_event'])) === [], 'every action names the log event the approval hook asks about');

echo "2. Every resolve entity: its tool exists on the records server and answers the list envelope\n";
foreach ($wrapper['resolve'] as $entity => $spec) {
    ok(in_array($spec['tool'], $S['person'], true) && ($spec['query_param'] ?? '') === 'q' && !empty($spec['id_field']) && !empty($spec['label_field']) && !empty($spec['params']), "$entity: tool {$spec['tool']} is on the records server; id {$spec['id_field']}, label {$spec['label_field']}, params " . implode(', ', $spec['params']));
}
$pm = [];
foreach ($wrapper['resolve'] as $e => $spec) { foreach ($spec['params'] as $p) { $pm[$p][] = $e; } }
ok(count(array_filter($pm, fn ($e) => count($e) > 1)) === 0, 'no param is claimed by two entities');
$used = [];
foreach ($actions as $x) { foreach ($x['params'] as $p) { if (isset($pm[$p['name']])) { $used[$p['name']] = true; } } }
ok(count($used) >= 15, 'the resolved params are really used by actions (' . count($used) . ' distinct names)');
$ent = array_column(q("SELECT display_name, count(*) OVER (PARTITION BY display_name) AS n FROM holders WHERE issuer_id = :i AND kind = 'entity' AND deleted_at IS NULL AND merged_into_id IS NULL ORDER BY id", ['i' => $w['smok_issuer']]), 'n', 'display_name');
$uniq = array_keys(array_filter($ent, fn ($n) => (int) $n === 1));
$instName = (string) one('SELECT i.name FROM institutional_holdings h JOIN institutions i ON i.id = h.institution_id WHERE h.security_id = :s ORDER BY h.id LIMIT 1', ['s' => $w['smok_security']]);
$sourceName = (string) one("SELECT name FROM sources WHERE connector = 'finra_short_interest' LIMIT 1");
$acc = (string) one('SELECT accession_number FROM filings WHERE id = :f', ['f' => $w['filing_text']]);
$sigSummary = (string) one('SELECT summary FROM signals WHERE id = :i', ['i' => $w['smok_signal_disposed']]);
$evTitle = (string) one('SELECT title FROM events WHERE id = :i', ['i' => $w['ev_short']]);
$resolve = [
    ['issuer', 'SMOKE ISSUER INC', ptoken(PRIYA)], ['issuer', 'SMOK', ptoken(PRIYA)], ['security', '07831X206', ptoken(PRIYA)], ['participant', '0164', ptoken(PRIYA)], ['holder', $uniq[0], ptoken(PRIYA)], ['holder', $uniq[1], ptoken(PRIYA)], ['institution', $instName, ptoken(PRIYA)],
    ['filing', $acc, ptoken(PRIYA)], ['event', $evTitle, ptoken(PRIYA)], ['signal', $sigSummary, ptoken(PRIYA)], ['rule', 'si_over_float', ptoken(PRIYA)], ['import', 'register_stc.xlsx', ptoken(PRIYA)],
    ['report', 'p4 approved monthly pack', ptoken(PRIYA)], ['source', $sourceName, ptoken(PRIYA)], ['instrument', 'SMOKE EPSILON CAPITAL LLC', ptoken(PRIYA)], ['tag', 'p4 watch', ptoken(PRIYA)], ['api_key', 'p4 priya key', ptoken(PRIYA)],
    ['member', 'SMOKE Marco', ptoken(PRIYA)], ['department', 'Investor Relations', ptoken(PRIYA)],
];
$drive = [
    ['signal_acknowledge', ['signal' => $sigSummary, 'reason' => 'known', 'note' => 'p4 never acknowledged'], ptoken(PRIYA)],
    ['signal_dismiss', ['signal' => $sigSummary, 'reason' => 'explained_by_event', 'explaining_event' => $evTitle], ptoken(PRIYA)],
    ['report_send', ['report' => 'p4 approved monthly pack', 'recipients' => '27', 'confirmed' => true], rtoken(EXPERT, runid(90)), 'pending_approval'],
    ['report_send', ['report' => 'p4 approved monthly pack', 'recipients' => '27'], ptoken(MARCO)],
    ['holders_merge', ['survivor' => $uniq[0], 'loser' => $uniq[1], 'confirmed' => true], ptoken(MARCO), 'pending_approval'],
    ['issuer_save', ['issuer' => 'SMOKE ISSUER INC', 'name' => 'p4 never renamed'], ptoken(MARCO), 'pending_approval'],
    ['signal_acknowledge', ['signal' => 'a signal that was never raised', 'reason' => 'known'], ptoken(PRIYA)],
    ['issuer_edgar_sync', ['issuer' => 'SMOKE'], ptoken(MARCO)],
    ['share_count_save', ['security' => '07831X206', 'kind' => 'outstanding', 'value' => '1', 'as_of' => '2024-04-05', 'origin' => 'manual'], ptoken(MARCO), 'pending_approval'],
    ['signal_propose', ['signal' => $sigSummary, 'reason' => 'known'], rtoken(ANALYST, runid(91))],
    ['holder_match_propose', ['holder' => $uniq[0], 'row_kind' => 'register', 'row' => '1'], ptoken(PRIYA)],
    ['participant_class_set', ['participant' => (string) one("SELECT id FROM participants WHERE number = '0164'"), 'class' => 'retail'], ptoken(MARCO)],
    ['borrow_observation_create', ['security' => '07831X206', 'observed_on' => '2024-04-05', 'origin' => 'manual', 'fee_rate_percent' => '12'], ptoken(PRIYA)],
];
facts(runid(90), EXPERT, agent_grants('expert'));
facts(runid(91), ANALYST, agent_grants('analyst'));
$kpy = '/var/www/mcp/venv/bin/python';
if (!is_executable($kpy)) { $kpy = trim((string) shell_exec('command -v python3')); }
$cmd = 'PYTHONDONTWRITEBYTECODE=1 ' . escapeshellarg($kpy) . ' -I ' . escapeshellarg(__DIR__ . '/registry_check.py') . ' ' . escapeshellarg($root) . ' ' . escapeshellarg('http://127.0.0.1:' . REC . '/mcp') . ' ' . escapeshellarg(json_encode(['resolve' => $resolve, 'drive' => $drive, 'env' => need('SI_DEV_ENV')])) . ' 2>/tmp/si-registry-check.err';
$out = (string) shell_exec($cmd);
$kr = json_decode($out, true);
ok(is_array($kr), 'the kernel\'s loader and tool factory ran against the composed registry' . (is_array($kr) ? '' : ' — ' . substr((string) @file_get_contents('/tmp/si-registry-check.err'), -600)));
if (!is_array($kr)) { finish(); }
ok($kr['loaded'] === ['shareholder_intelligence'] && $kr['registered'] === 83, 'application_actions.load_registries() accepts it and register() adds 83 tools');
ok(count($kr['tools']) === 83 && count(array_diff(array_keys($actions), array_keys($kr['tools']))) === 0, 'one tool per action, by the action\'s name');
$noApp = [];
foreach ($actions as $key => $x) { if ($x['approval'] !== null && !str_contains($kr['tools'][$key]['description'], 'category: ' . $x['approval'])) { $noApp[] = $key; } }
ok($noApp === [], 'a tool\'s description states its approval category (' . $withCat . ' of them)');
$noConfirm = [];
foreach ($actions as $key => $x) { $txt = json_encode($kr['tools'][$key]['schema']); if (!empty($x['confirm']) && !str_contains($txt, 'confirmed')) { $noConfirm[] = $key; } }
ok($noConfirm === [], 'every destructive action carries the `confirmed` guard (' . count(array_filter($actions, fn ($x) => !empty($x['confirm']))) . ' of them)');
$noParam = [];
foreach ($actions as $key => $x) { $props = array_keys($kr['tools'][$key]['schema']['$defs'][array_key_first($kr['tools'][$key]['schema']['$defs'] ?? [])]['properties'] ?? []); foreach ($x['params'] as $p) { if (($p['name'] ?? null) && !in_array($p['name'], $props, true)) { $noParam[] = "$key.{$p['name']}"; } } }
ok($noParam === [], 'every manifest parameter is a field of its tool' . ($noParam ? ' — missing: ' . implode(', ', array_slice($noParam, 0, 5)) : ''));
echo "   resolving through the kernel's resolver, as the person who asks:\n";
foreach ($resolve as [$entity, $value]) {
    $r = $kr['resolved'][$entity . '|' . $value] ?? null;
    $spec = $wrapper['resolve'][$entity];
    ok($r !== null && !isset($r['error']) && $r['matches'] === 1 && $r['id'] !== null && $r['label'] !== null && in_array($spec['id_field'], $r['keys'], true) && in_array($spec['label_field'], $r['keys'], true),
        "resolver: $entity '" . substr((string) $value, 0, 30) . "' → 1 match" . ($r && isset($r['label']) ? ', ' . $spec['id_field'] . ' ' . substr((string) $r['id'], 0, 8) . ', "' . substr((string) $r['label'], 0, 40) . '"' : ' — ' . json_encode($r)));
}
$rid = fn ($e, $v) => (string) $kr['resolved'][$e . '|' . $v]['id'];
ok($rid('signal', $sigSummary) === (string) $w['smok_signal_disposed'], 'a signal in any state resolves by its summary (the disposed one — the resolver finds a record whatever its state)');
$d = $kr['driven'];
echo "3. Whole actions through the kernel's tool\n";
ok(($d[0]['answer']['status'] ?? '') === 'success' && count($d[0]['posts']) === 1 && $d[0]['posts'][0]['path'] === '/signals/acknowledge.php' && $d[0]['posts'][0]['fields']['signal'] === (string) $w['smok_signal_disposed'] && $d[0]['posts'][0]['fields']['reason'] === 'known',
    'signal_acknowledge (no approval): the signal resolved by its summary to its id; the handler is posted the id and the kernel\'s hook is not asked');
ok(($d[1]['answer']['status'] ?? '') === 'success' && $d[1]['posts'][0]['fields']['explaining_event'] === (string) $w['ev_short'], 'signal_dismiss: the explaining event resolved by its title');
ok(($d[2]['answer']['status'] ?? '') === 'pending_approval' && count($d[2]['posts']) === 1 && $d[2]['posts'][0]['path'] === '/approvals/hook.php' && $d[2]['posts'][0]['fields']['action_key'] === 'report_send' && $d[2]['posts'][0]['fields']['log_event'] === 'report.send',
    'THE EXPERT AGENT\'S report_send PAUSES: the hook is asked about report.send (external_send) and answers pending_approval — the pack is not sent');
ok(($d[3]['answer']['status'] ?? '') === 'needs_confirmation' && $d[3]['posts'] === [], 'report_send without `confirmed`: needs_confirmation, nothing posted — not even the hook');
ok(($d[4]['answer']['status'] ?? '') === 'pending_approval' && $d[4]['posts'][0]['fields']['action_key'] === 'holders_merge' && str_contains($d[4]['posts'][0]['fields']['parameters'], '"survivor"') && ($d[4]['answer']['resolved']['survivor'] ?? '') === $uniq[0] && ($d[4]['answer']['resolved']['loser'] ?? '') === $uniq[1],
    'holders_merge (deletion) pauses; survivor and loser were resolved by name to ids first');
ok(($d[5]['answer']['status'] ?? '') === 'pending_approval' && $d[5]['posts'][0]['fields']['log_event'] === 'issuer.save', 'issuer_save (other) pauses');
ok(($d[6]['answer']['status'] ?? '') === 'error' && str_contains($d[6]['answer']['message'], "No signal matching 'a signal that was never raised'") && $d[6]['posts'] === [], 'an unknown signal: "No signal matching …" and nothing posted');
ok(($d[7]['answer']['status'] ?? '') === 'error' && str_contains($d[7]['answer']['message'], 'Several match') && $d[7]['posts'] === [], 'an ambiguous name (SMOKE, six issuers): the kernel lists the candidates and posts nothing');
ok(($d[8]['answer']['status'] ?? '') === 'pending_approval' && (json_decode((string) ($d[8]['posts'][0]['fields']['parameters'] ?? ''), true)['origin'] ?? '') === 'manual',
    'share_count_save with origin \'manual\' pauses with its origin as written (the manifest\'s `source` became `origin`: the kernel resolves every param named `source` as a fetcher)');
ok(($d[11]['answer']['status'] ?? '') === 'success' && ($d[11]['posts'][0]['fields']['class'] ?? '') === 'retail', 'participant_class_set with class \'retail\' is posted as written (`class` is no longer an alias of a security)');
ok(($d[12]['answer']['status'] ?? '') === 'success' && ($d[12]['posts'][0]['fields']['origin'] ?? '') === 'manual' && ($d[12]['posts'][0]['fields']['security'] ?? '') === (string) one("SELECT id FROM securities WHERE cusip = '07831X206'"),
    'borrow_observation_create: the security resolved by its CUSIP, the origin posted as written');
ok(($d[9]['answer']['status'] ?? '') === 'success' && $d[9]['posts'][0]['path'] === '/signals/propose.php', 'signal_propose by the Analyst agent runs unpaused: it is a proposal, a person decides');
ok(($d[10]['answer']['status'] ?? '') === 'success' && $d[10]['posts'][0]['fields']['holder'] === (string) $kr['resolved']['holder|' . $uniq[0]]['id'], 'holder_match_propose: the holder resolved by name (an entity — a person is an Analyst\'s)');
$wrote = (int) one("SELECT count(*) FROM notes WHERE body LIKE 'p4 never%'") + (int) one("SELECT count(*) FROM issuers WHERE name LIKE 'p4 never%'");
ok($wrote === 0, 'and nothing was written to the application: the handler posts were stubs');

echo "4. maludb-os.json is in step with what the servers offer\n";
$agents = array_column($manifest['agents'], null, 'key');
ok(array_keys($agents) === ['expert', 'analyst'], 'two agents hired on install: the expert and the Surveillance Analyst');
$badTool = [];
foreach ($manifest['agents'] as $ag) {
    foreach ($ag['tool_grants']['Records MCP'] ?? [] as $t) { if (!in_array($t, $S['person'], true)) { $badTool[] = $ag['key'] . ' Records ' . $t; } }
    foreach ($ag['tool_grants']['Activity MCP'] ?? [] as $t) { if (!in_array($t, $S['activity'], true)) { $badTool[] = $ag['key'] . ' Activity ' . $t; } }
    foreach ($ag['tool_grants']['Actions MCP'] ?? [] as $t) { if (!isset($actions[$t]) && !in_array($t, $KERNEL_TOOLS, true)) { $badTool[] = $ag['key'] . ' Actions ' . $t; } }
}
ok($badTool === [], 'every tool an agent is granted exists: a records or activity tool of the surface, an action of the registry (or the kernel\'s message tools)' . ($badTool ? ' — ' . implode(', ', $badTool) : ''));
$live = mcp_tools(REC, ptoken(MARCO));
$liveAct = mcp_tools(ACT, ptoken(MARCO));
$offered = [];
foreach ($manifest['agents'] as $ag) {
    foreach ($ag['tool_grants']['Records MCP'] ?? [] as $t) { if (!in_array($t, $live, true)) { $offered[] = $ag['key'] . ' ' . $t; } }
    foreach ($ag['tool_grants']['Activity MCP'] ?? [] as $t) { if (!in_array($t, $liveAct, true)) { $offered[] = $ag['key'] . ' ' . $t; } }
}
ok($offered === [], 'and every one of them is a tool the running servers really list');
$aAct = $agents['analyst']['tool_grants']['Actions MCP'];
$risky = array_values(array_filter($aAct, fn ($t) => in_array($actions[$t]['approval'] ?? null, ['money_out', 'deletion', 'external_send'], true) || !empty($actions[$t]['confirm'])));
ok($risky === [] && !in_array('report_send', $aAct, true), 'the Surveillance Analyst holds no action that pays, deletes or sends, and none that needs confirming: it drafts and proposes (' . implode(', ', $aAct) . ')');
ok(in_array('report_send', $agents['expert']['tool_grants']['Actions MCP'], true) && ($actions['report_send']['approval'] ?? '') === 'external_send' && $actions['report_send']['confirm'] === true, 'the expert may ask to send a pack — a paused, confirmed action (external_send)');
$eAct = $agents['expert']['tool_grants']['Actions MCP'];
$weighty = [];
foreach ($eAct as $t) { if (preg_match('/delete|purge|credential|send|merge|revoke/', $t) && ($actions[$t]['approval'] ?? null) === null && empty($actions[$t]['confirm']) && !in_array($t, $KERNEL_TOOLS, true)) { $weighty[] = $t; } }
ok($weighty === [], 'every action of the expert that sends, deletes, merges or revokes is a paused or confirmed one');
$grantedShares = array_intersect($S['shares'], array_merge($agents['expert']['tool_grants']['Records MCP'], $agents['analyst']['tool_grants']['Records MCP']));
ok($grantedShares === [] && !in_array('app_roles', $agents['expert']['tool_grants']['Records MCP'], true), 'no agent is granted a share or app_roles: those are the kernel\'s');
$duty = $agents['analyst']['duty'] ?? null;
ok($duty !== null && preg_match('/^(\S+\s+){4}\S+$/', $duty['schedule_cron']) === 1 && $duty['timezone'] === 'America/New_York' && in_array('skills/morning-note', $agents['analyst']['skills'], true) && is_file($root . '/skills/morning-note/SKILL.md'), 'the Analyst\'s duty is declared as the design says — market days at 07:00 New York, its runbook (morning-note) a skill it holds');
ok(!isset($agents['expert']['duty']) && !isset($agents['expert']['duties']), 'and the expert has none');
$shares = array_column($manifest['shares'], 'tool');
sort($shares);
$want = $S['shares']; sort($want);
ok($shares === $want, 'shares[] names exactly the five tools');
$bad = [];
foreach ($manifest['approvals'] as $x) { if (!isset($actions[$x['action']]) || $actions[$x['action']]['approval'] !== $x['category']) { $bad[] = $x['action']; } }
ok($bad === [] && count($manifest['approvals']) === $withCat, 'approvals[] is the manifest\'s ' . $withCat . ' pauses, each an action of the registry with that category');
foreach ($manifest['skills'] as $sk) { $p = is_array($sk) ? ($sk['path'] ?? '') : $sk; ok(is_file($root . '/' . $p . '/SKILL.md'), "skill $p has its SKILL.md"); }
foreach ($manifest['agents'] as $ag) { ok(is_file($root . '/' . $ag['job_description']), "the job description {$ag['job_description']} exists"); }
$known = array_merge($S['records'], $S['activity'], array_keys($actions), $KERNEL_TOOLS);
$vocab = array_merge(array_column(q('SELECT key FROM signal_rules'), 'key'), ['upload_done', 'short_volume_ratio', 'threshold_list', 'borrow_observations', 'borrow_fee', 'dilution_overlap', 'holder_5pct', 'insider_sales', 'participant_jump', 'record_date']);   // rule keys and the words of the prose
$unknown = [];
foreach (array_merge(glob($root . '/skills/*/SKILL.md'), glob($root . '/os/*.md')) as $f) {
    preg_match_all('/`([a-z]+(?:_[a-z0-9]+)+)`/', (string) file_get_contents($f), $m);
    foreach (array_unique($m[1]) as $name) {
        if (!in_array($name, $known, true) && !in_array($name, $vocab, true) && preg_match('/^(find|get|my|signal|short|holder|holders|insider|filing|filings|watch|dilution|events|share|position|participant|reconciliation|record|activity|actor|recent|agent|rules|members|source|spr|obo|institutional|holdings|beneficial|threshold|borrow|daily|fails|key|issuer|match|report|upload|import|fetch|tag|note|event|rule|api|token|export)_/', $name)) { $unknown[] = basename(dirname($f)) . '/' . basename($f) . ': ' . $name; }
    }
}
ok($unknown === [], 'every tool or action a skill or a job description names exists' . ($unknown ? ' — ' . implode('; ', array_slice($unknown, 0, 12)) : ''));

echo "5. K55: our action names against the other registries on this host (information)\n";
$ours = array_keys($actions);
$collide = [];
foreach (glob('/var/www/mcp/registries/*.json') as $f) {
    if (basename($f) === 'shareholder_intelligence.json') { continue; }
    $r = json_decode((string) file_get_contents($f), true);
    foreach (array_keys($r['registry']['actions'] ?? []) as $name) { if (in_array($name, $ours, true)) { $collide[$name][] = basename($f, '.json'); } }
}
echo '   ' . count($collide) . ' of our ' . count($ours) . ' action names are also another registry\'s: ' . ($collide ? implode(', ', array_map(fn ($n, $a) => "$n (" . implode('/', $a) . ')', array_keys($collide), $collide)) : 'none') . "\n";
ok(true, 'listed (the kernel\'s installer gives a colliding action a tool_name "<app_key>_<action>"; no action is renamed here)');
finish();
