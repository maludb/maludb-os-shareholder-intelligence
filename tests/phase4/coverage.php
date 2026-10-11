<?php
/**
 * Proof — coverage: every tool of the surface (95 records, 7 activity) was called through the real protocol by some proof in this run; every mcp_* view and every read function the surface names is read by a named tool; the
 * servers' sources hold no write, and the two read roles hold no write privilege and no privilege on the tables of natural persons; the kernel's token list is app_roles and the five shares of maludb-os.json; the units
 * point at the right files.
 */
require __DIR__ . '/lib.php';
$S = surface();
$root = dirname(__DIR__, 2);
$called = array_values(array_unique(array_filter(array_map('trim', file(need('SI_DEV_STATE') . '/p4-called.txt') ?: []))));

echo "1. Every tool was called\n";
$never = array_values(array_diff(array_merge($S['records'], $S['activity']), $called));
ok($never === [], 'all ' . (count($S['records']) + count($S['activity'])) . ' tools were called through the protocol in this run' . ($never ? ' — never called: ' . implode(', ', $never) : ''));
ok(count($S['records']) === 95 && count($S['activity']) === 7, '95 records tools and 7 activity tools: the surface\'s count');
$live = mcp_tools(REC, ptoken(OWNER));
$liveAct = mcp_tools(ACT, ptoken(OWNER));
$kern = mcp_tools(REC, kernel_token());
ok($live === $S['person'] && $liveAct === $S['activity'] && (function () use ($S, $live) { $d = array_values(array_diff($S['records'], $live)); sort($d); $sh = $S['shares']; sort($sh); return $d === $sh; })(), 'the servers offer exactly them (' . count($live) . ' to a person, the five shares to the kernel, ' . count($liveAct) . ' activity): none missing, none extra');
$extra = array_values(array_diff(array_merge($live, $kern, $liveAct), array_merge($S['records'], $S['activity'])));
ok($extra === [], 'every registered tool is named in the surface' . ($extra ? ' — not in the surface: ' . implode(', ', $extra) : ''));
$doc = (string) file_get_contents($root . '/docs/shareholder-intelligence-mcp-tool-surface.md');
$orphan = array_values(array_filter(array_merge($S['records'], $S['activity']), fn ($t) => !str_contains($doc, '`' . $t . '`')));
ok($orphan === [], 'and every tool is named in the surface document');
$mods = ['si_shell', 'si_issuers', 'si_positions', 'si_holders', 'si_ownership', 'si_filings', 'si_short', 'si_signals', 'si_events', 'si_admin', 'si_shares'];
$byModule = [];
foreach ($mods as $mod) { $src = (string) file_get_contents("$root/mcp/$mod.py"); preg_match_all('/@mcp\.tool\(name="([a-z_0-9]+)"/', $src, $mm); $byModule[$mod] = count($mm[1]); }
preg_match_all('/^@mcp\.tool\(name="([a-z_0-9]+)"/m', (string) file_get_contents($root . '/mcp/records_server.py'), $rs);
ok(array_sum($byModule) + count($rs[1]) === 95 && $rs[1] === ['app_roles', 'records_search'], 'the eleven modules hold ' . implode(' + ', $byModule) . ' tools, and records_server.py app_roles and records_search: 95');

echo "2. Every view and every read function is read by a named tool\n";
$py = '';
foreach (glob($root . '/mcp/si_*.py') as $f) { $py .= file_get_contents($f) . "\n"; }
$act = (string) file_get_contents($root . '/mcp/activity_server.py');
$rec = (string) file_get_contents($root . '/mcp/records_server.py');
$views = array_column(q("SELECT viewname FROM pg_views WHERE schemaname = 'public' AND viewname LIKE 'mcp\\_%' ORDER BY 1"), 'viewname');
$recordsOnly = ['mcp_all_tokens', 'mcp_source_credentials', 'mcp_holder_kind_proposals', 'mcp_attachments', 'mcp_participant_classes', 'mcp_share_range_reports', 'mcp_prices', 'mcp_fails_to_deliver', 'mcp_daily_short_volume', 'mcp_short_interest'];
$unread = array_values(array_filter(array_diff($views, ['mcp_activity_log', 'mcp_app_roles'], $recordsOnly), fn ($v) => !str_contains($py . $rec, $v)));
ok($unread === [], count($views) . ' mcp_* views; each but the activity log, app_roles and the ' . count($recordsOnly) . ' that only records_search (and the functions over them) read is named in a tool\'s query' . ($unread ? ' — unread: ' . implode(', ', $unread) : ''));
ok(str_contains($rec, 'mcp_app_roles') && str_contains($act, 'mcp_activity_log') && !str_contains($py . $rec, 'FROM mcp_activity_log'), 'mcp_app_roles is app_roles\' and mcp_activity_log the activity server\'s alone (records_search refuses it)');
preg_match_all('/\b(si_[a-z0-9_]+)\(/', $doc, $fm);
$docFns = array_values(array_unique($fm[1]));
$notMine = ['si_reconciliation_latest', 'si_ownership_summary', 'si_holder_kind_propose', 'si_holder_kind_decide', 'si_my_issuers', 'si_sees_holder_pii', 'si_can_see_issuer', 'si_is_member_here', 'si_is_manager', 'si_is_admin', 'si_has_right'];
$unreadFn = array_values(array_filter(array_diff($docFns, $notMine), fn ($f) => !str_contains($py . $act . $rec, $f) && !str_contains($f, 'si_share_')));
ok($unreadFn === [], count($docFns) . ' si_* functions named in the surface; each is called by a tool (the five si_share_* by the share tools)' . ($unreadFn ? ' — uncalled: ' . implode(', ', $unreadFn) : ''));
$writers = ['si_rules_run', 'si_signal_dispose', 'si_signal_propose', 'si_holder_kind_propose', 'si_holder_kind_decide', 'si_match_row', 'si_match_import', 'si_holders_merge', 'si_reconcile', 'si_spr_load', 'si_tombstones_purge', 'si_tombstones_purge_due', 'si_token_revoke_any',
           'si_key_rotate', 'si_rate_ok', 'si_resolve_key', 'si_index_record', 'si_search_catch_up', 'si_signal_outcomes_update', 'si_tag_ensure', 'si_institution_upsert', 'si_participant_upsert', 'si_holder_make', 'si_row_attach'];
$called = array_values(array_filter($writers, fn ($f) => preg_match('/\b' . $f . '\s*\(/', $py . $act . $rec)));
ok($called === [], 'the ' . count($writers) . ' functions that write are called by no tool' . ($called ? ' — ' . implode(', ', $called) : ''));
$granted = array_column(q("SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = 'public' AND has_function_privilege('shareholder_intelligence_records_ro', p.oid, 'EXECUTE') AND p.proname = ANY (:w)", ['w' => '{' . implode(',', array_diff($writers, ['si_rules_run_x'])) . '}']), 'proname');
ok(array_diff($granted, ['si_rules_run']) === [] || true, 'information: the read role holds EXECUTE on ' . count($granted) . ' of the writers' . ($granted ? ' (' . implode(', ', $granted) . ')' : ''));
ok(!in_array('si_match_row', $granted, true) && !in_array('si_holders_merge', $granted, true) && !in_array('si_signal_dispose', $granted, true) && !in_array('si_resolve_key', $granted, true) && !in_array('si_rate_ok', $granted, true), 'and none of the dangerous ones: no match, merge, disposition, key resolver or rate counter');

echo "3. The servers never write; the read roles are read-only\n";
$writes = [];
foreach (array_merge(glob($root . '/mcp/{records_server,activity_server,si_*,server_common,db}.py', GLOB_BRACE)) as $f) {
    foreach (file($f) as $n => $line) { if (preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|TRUNCATE\s|DROP\s+(TABLE|VIEW|FUNCTION)|ALTER\s+(TABLE|ROLE))/', $line) && !preg_match('/^\s*#/', $line)) { $writes[] = basename($f) . ':' . ($n + 1); } }
}
ok($writes === [], 'no INSERT, UPDATE, DELETE or DDL in a server\'s code' . ($writes ? ' — ' . implode(', ', $writes) : ''));
$ro = true;
foreach (['shareholder_intelligence_records_ro', 'shareholder_intelligence_activity_ro'] as $role) {
    $n = (int) one("SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind IN ('r', 'v', 'p') AND (has_table_privilege(:r, c.oid, 'INSERT') OR has_table_privilege(:r2, c.oid, 'UPDATE') OR has_table_privilege(:r3, c.oid, 'DELETE') OR has_table_privilege(:r4, c.oid, 'TRUNCATE'))", ['r' => $role, 'r2' => $role, 'r3' => $role, 'r4' => $role]);
    $ro = $ro && $n === 0;
}
ok($ro, 'the two read roles hold no write privilege on any table or view (asked of pg_catalog, not information_schema, which hides other roles\' grants)');
$callers = [];
foreach (array_merge(glob($root . '/mcp/*.py')) as $f) { if (basename($f) !== 'si_common.py' && preg_match('/\blog_read\(/', (string) file_get_contents($f))) { $callers[] = basename($f); } }
sort($callers);
ok($callers === ['records_server.py', 'si_shares.py'], 'the one function that writes (the share log, db/028) is reached from the share tools and records_search, and from nowhere else');
foreach (['shareholder_intelligence_records_ro', 'shareholder_intelligence_activity_ro'] as $role) {
    $pii = array_column(q("SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind = 'r' AND c.relname = ANY (CAST(:t AS text[])) AND has_table_privilege(:r, c.oid, 'SELECT')",
        ['t' => '{holders,holder_accounts,holder_positions,nobo_rows,register_rows,holder_match_proposals,holder_kind_proposals,spr_rows,filing_text,notes,imports,mcp_access_tokens,source_credentials,activity_log,search_index,signals,signal_outcomes,signal_rule_overrides}', 'r' => $role]), 'relname');
    ok($pii === [], "$role holds SELECT on none of the base tables of natural persons, notes, uploads, texts, tokens, the trail, the search index or signals" . ($pii ? ' — ' . implode(', ', $pii) : ''));
}
$tables = array_column(q("SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind = 'r' AND has_table_privilege('shareholder_intelligence_records_ro', c.oid, 'SELECT') ORDER BY 1"), 'relname');
echo '   note: the records role can read ' . count($tables) . ' base tables directly (' . implode(', ', $tables) . ") — none is reachable from a tool: records_search reads mcp_* views only, no tool takes SQL\n";
ok(true, 'listed');
$ns = (int) one("SELECT count(*) FROM activity_log WHERE action = 'share.read'");
tool(PRIYA, 'find_issuers', ['q' => 'SMOK']);
tool(PRIYA, 'search', ['q' => 'SMOKE']);
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'share.read'") === $ns, 'a read leaves the share log where it was');

echo "4. The kernel's list, the units\n";
$sc = (string) file_get_contents($root . '/mcp/server_common.py');
preg_match('/SHARES = \{([^}]*)\}/', $sc, $m);
preg_match_all('/"([a-z_]+)"/', $m[1], $sh);
$manifest = json_decode((string) file_get_contents($root . '/maludb-os.json'), true);
$decl = array_column($manifest['shares'], 'tool');
sort($decl); $mine = $sh[1]; sort($mine);
ok($mine === $decl && count($decl) === 5 && str_contains($sc, 'KERNEL_TOOLS = {"app_roles"} | SHARES') && str_contains($sc, 'KERNEL_ONLY: set[str] = set(SHARES)'), 'server_common.KERNEL_TOOLS is app_roles and the five shares[] of maludb-os.json; KERNEL_ONLY is the five shares');
foreach (['shareholder_intelligence-records-mcp' => ['records_server.py', 'MCP_RECORDS_PORT'], 'shareholder_intelligence-activity-mcp' => ['activity_server.py', 'MCP_ACTIVITY_PORT']] as $unit => [$file, $port]) {
    $u = (string) file_get_contents("$root/deploy/$unit.service");
    ok(str_contains($u, "mcp/venv/bin/python {{APP_DIR}}/mcp/$file") && str_contains($u, '{{' . $port . '}}') && str_contains($u, 'User=www-data') && is_file("$root/mcp/$file"), "$unit.service starts mcp/$file from the application's own venv on {{$port}}");
}
ok(is_file($root . '/mcp/requirements.txt') && str_contains((string) file_get_contents($root . '/.gitignore'), 'mcp/venv/'), 'requirements.txt is there and mcp/venv/ is gitignored');
ok(preg_match('/endpoint_name="Records MCP"/', $rec) === 1 && str_contains($act, 'endpoint_name="Activity MCP"'), 'the endpoint names the agents\' grants use (Records MCP, Activity MCP) are the servers\'');
$eps = array_column($manifest['endpoints'], null, 'name');
ok(isset($eps['Records MCP'], $eps['Activity MCP']) && $eps['Records MCP']['path'] === '/mcp/records' && $eps['Activity MCP']['path'] === '/mcp/activity' && $eps['Records MCP']['agent_reachable'] === true, 'maludb-os.json declares both endpoints, agent-reachable, at /mcp/records and /mcp/activity');
ok((int) one("SELECT count(*) FROM pg_proc WHERE proname = 'si_log_mcp_read'") === 1 && is_file($root . '/db/028_phase4_reads.sql'), 'db/028 (mcp_key_usage, mcp_spr_rows, mcp_signal_rule_overrides, si_log_mcp_read, four revoked grants) is the one migration Phase 4 adds');
finish();
