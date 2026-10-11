<?php
/**
 * Helpers for the Phase 4 proofs (docs/shareholder-intelligence-mcp-tool-surface.md). Run through tests/phase4/run.sh (under the shared lock): a SCRATCH database (si_dev40 — never the installed one), the application
 * on :8901 (under Apache :8901 is the PUBLIC vhost and :8907 the internal one), a fake kernel on :8902 (run-facts, the chat and text doors), a fake MaluDB + MaluMail on :8903, and the TWO MCP SERVERS on :8904 (records)
 * and :8905 (activity). Everything a proof makes is named "SMOKE …" or "p4 …".
 * Builds on slice 9's library (so 8's … 1's and Phase 2's). THE CAST (bin/dev_directory.json): 1 the owner (super-admin, SI admin), 26 Priya (Analyst), 27 Marco (IR Manager), 28 Bea (Analyst by capability), 29 Ann
 * (a VIEWER, external), 30 Dana (Analyst, on Beta's list), 31 Lee (admitted, no role), 40 the Expert, 41 the Watcher (an agent, not admitted), 42 the Surveillance Analyst (an agent).
 * THE WORLD (p4_world()): seed9()'s SMOKE Alpha/Beta/Gamma; seed7()'s SMOKE ISSUER INC (SMOK — the public record, the short side, an SPR of twenty dates, a NOBO list, a register of 842 rows, a reconciliation) and
 * SMOKE SECOND CORP; and tests/phase4/world.sql: SMOKE Delta (SMKD, restricted to Marco), the holders the matcher makes from the register and the NOBO rows, the rules run, the packs, an export, a key, dispatches.
 */
require_once dirname(__DIR__) . '/phase3/slice9/lib.php';

const REC = 8904;
const ACT = 8905;
const VARIANT = 8906;
const OWNER = 1; const PRIYA = 26; const MARCO = 27; const BEA = 28; const ANN = 29; const DANA = 30; const LEE = 31; const EXPERT = 40; const WATCHER = 41; const ANALYST = 42;
/** The tools that take no arguments (the client sends no `params`). */
const NO_ARGS = ['app_roles', 'get_settings'];

/** A person's own mcp_ token (the servers resolve it through mcp_resolve_token). Cached per member. */
function ptoken(int $member): string
{
    static $t = [];
    if (isset($t[$member])) { return $t[$member]; }
    $raw = 'mcp_' . bin2hex(random_bytes(24));
    q("INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope) VALUES (:m, 'SMOKE p4', :h, 'mcp')", ['m' => $member, 'h' => hash('sha256', $raw)]);
    return $t[$member] = $raw;
}
/** A person's token of scope api (a records-API key's shape: not for the MCP servers). */
function api_token(int $member): string
{
    $raw = 'sik_' . bin2hex(random_bytes(24));
    q("INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope) VALUES (:m, 'SMOKE p4 api', :h, 'api')", ['m' => $member, 'h' => hash('sha256', $raw)]);
    return $raw;
}
/** The tenant's signed RUN token an agent presents ('mid.exp.run.hmac' over run:mid.exp.run). */
function rtoken(int $member, int $run, int $ttl = 300): string { return run_token($member, $run, $ttl); }
/** A person's action token (the command bar's): 'mid.exp.hmac'. */
function atoken(int $member, int $ttl = 300): string { return person_token($member, $ttl); }
/** The kernel's own 60-second token: 'kernel.exp.app.nonce.hmac' over kernel:exp.app.nonce. */
function kernel_token(string $app = 'shareholder_intelligence', int $ttl = 60): string
{
    $p = (time() + $ttl) . '.' . $app . '.' . bin2hex(random_bytes(16));
    return 'kernel.' . $p . '.' . hash_hmac('sha256', 'kernel:' . $p, need('ACTION_TOKEN_KEY'));
}
/** A run id for this execution of a proof (the server caches a run's facts for 300 s, so a rerun must not reuse one). */
function runid(int $n): int { static $b = null; $b ??= (time() % 100000) * 100; return $b + $n; }
/** Tell the fake kernel what the run-facts call answers for a run id. $endpoints: [name => [tool => constraints]] or [] for another application's. */
function facts(int $run, int $member, array $endpoints, array $o = []): void
{
    kernel_state(function ($s) use ($run, $member, $endpoints, $o) {
        $eps = [];
        foreach ($endpoints as $name => $tools) { $eps[] = ['id' => 1, 'name' => $name, 'tools' => (object) $tools]; }
        $s['facts'][(string) $run] = ['valid' => $o['valid'] ?? true, 'is_agent' => $o['is_agent'] ?? true, 'member_id' => $member, 'run_id' => $run, 'request_id' => "req-run-$run",
            'trigger' => $o['trigger'] ?? 'chat', 'is_eval' => ($o['trigger'] ?? '') === 'eval', 'endpoints' => $eps];
        return $s;
    });
}

// ---- the MCP client (the kernel's own: initialize, initialized, then the call) ------------------------------------
function mcp_post(int $port, string $token, array $msg, ?string $session = null, string $path = '/mcp', array $extra = []): array
{
    $h = array_merge(['Content-Type: application/json', 'Accept: application/json, text/event-stream', 'MCP-Protocol-Version: 2025-06-18'], $extra);
    if ($token !== '') { $h[] = 'Authorization: Bearer ' . $token; }
    if ($session) { $h[] = 'Mcp-Session-Id: ' . $session; }
    $sess = $session;
    $ch = curl_init("http://127.0.0.1:$port$path");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($msg), CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$sess) { if (stripos($line, 'mcp-session-id:') === 0) { $sess = trim(substr($line, 15)); } return strlen($line); }]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    if (!is_string($body) || $body === '') { return [null, $sess, $status]; }
    if (stripos($type, 'text/event-stream') !== false) {
        foreach (preg_split('/\r?\n/', $body) as $line) {
            if (str_starts_with($line, 'data:')) { $m = json_decode(trim(substr($line, 5)), true); if (is_array($m) && ($m['id'] ?? null) === ($msg['id'] ?? null)) { return [$m, $sess, $status]; } }
        }
        return [null, $sess, $status];
    }
    return [json_decode($body, true), $sess, $status];
}
function mcp_open(int $port, string $token, string $path = '/mcp', array $extra = []): array
{
    [$init, $sess, $st] = mcp_post($port, $token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'p4', 'version' => '1']]], null, $path, $extra);
    if ($st === 200) { mcp_post($port, $token, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $sess, $path, $extra); }
    return [$sess, $st, $init];
}
/** The tool names a bearer is offered, or null when the server refused it (401). */
function mcp_tools(int $port, string $token, array $extra = [], string $path = '/mcp'): ?array
{
    [$sess, $st] = mcp_open($port, $token, $path, $extra);
    if ($st !== 200) { return null; }
    [$a] = mcp_post($port, $token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $sess, $path, $extra);
    $names = array_map(fn ($t) => $t['name'], $a['result']['tools'] ?? []);
    sort($names);
    return $names;
}
/** The full tools/list answer (names, descriptions, annotations, input schemas). */
function mcp_tool_defs(int $port, string $token): array
{
    [$sess] = mcp_open($port, $token);
    [$a] = mcp_post($port, $token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $sess);
    return $a['result']['tools'] ?? [];
}
/**
 * Call a tool as a bearer. Answers ['http', 'error' => bool (the tool refused), 'text', 'data' => decoded JSON or null, 'code' => the error code when the tool answered {ok:false,error:{code}}]. Arguments are
 * wrapped as {"params": ...} the way FastMCP's models take them, except the kernel's flat call ($flat) and the tools that take none (app_roles, get_settings).
 */
function mcp_tool(int $port, string $token, string $tool, array $args = [], bool $flat = false, array $extra = [], string $path = '/mcp'): array
{
    @file_put_contents(need('SI_DEV_STATE') . '/p4-called.txt', $tool . "\n", FILE_APPEND);
    [$sess, $st] = mcp_open($port, $token, $path, $extra);
    if ($st !== 200) { return ['http' => $st, 'error' => true, 'text' => '', 'data' => null, 'code' => null]; }
    $arguments = $flat || ($args === [] && in_array($tool, NO_ARGS, true)) ? (object) $args : ['params' => (object) $args];
    [$a] = mcp_post($port, $token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]], $sess, $path, $extra);
    $res = $a['result'] ?? [];
    $text = (string) ($res['content'][0]['text'] ?? ($a['error']['message'] ?? ''));
    $data = json_decode($text, true);
    $code = is_array($data) && ($data['ok'] ?? null) === false ? ($data['error']['code'] ?? null) : null;
    return ['http' => 200, 'error' => !empty($res['isError']) || isset($a['error']) || $code !== null, 'text' => $text, 'data' => is_array($data) ? $data : null, 'code' => $code];
}
/** A tool as a person (their own token): the whole answer, or just the decoded data. */
function tool(int $member, string $name, array $args = [], int $port = REC): array { return mcp_tool($port, ptoken($member), $name, $args); }
function tdata(int $member, string $name, array $args = [], int $port = REC) { return tool($member, $name, $args, $port)['data']; }
/** The rows of a list answer, or []. */
function trows(int $member, string $name, array $args = [], int $port = REC): array { $d = tdata($member, $name, $args, $port); return is_array($d) ? ($d['rows'] ?? []) : []; }
/** Every key anywhere in a decoded answer. */
function has_key($data, string $key): bool
{
    if (!is_array($data)) { return false; }
    foreach ($data as $k => $v) { if ($k === $key || has_key($v, $key)) { return true; } }
    return false;
}
function find_row(?array $rows, string $key, $value): ?array { foreach ($rows ?? [] as $r) { if ((string) ($r[$key] ?? '') === (string) $value) { return $r; } } return null; }
function ids(?array $rows, string $key): array { return array_values(array_map(fn ($r) => $r[$key], $rows ?? [])); }
/** SQL over the views AS a member (the same rows the server hands that member), on the application's own role — the views are readable by it. */
function sql_as(int $member, string $sql, array $args = []): array
{
    pdo()->exec("SELECT set_config('app.member_id', '$member', false)");
    $rows = q($sql, $args);
    pdo()->exec("SELECT set_config('app.member_id', '', false)");
    return $rows;
}


/** Slice 7's crafted fixtures (tests/phase3/slice7/seed.sql, run once a database; its lib.php redeclares slice 9's form_of(), so the loader is repeated here): ['issuer', 'security', 'issuer2', 'security2', 'ev_short', 'eloc']. */
function seed7(): array
{
    static $ids = null;
    if ($ids !== null) { return $ids; }
    $out = (string) shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 2)) . ' && sudo -n -u postgres psql -v ON_ERROR_STOP=1 -At -F "|" -d ' . escapeshellarg(need('SI_DEV_DB')) . ' -f tests/phase3/slice7/seed.sql 2>&1');
    $line = '';
    foreach (explode("\n", trim($out)) as $l) { if (str_starts_with($l, 'seeded|')) { $line = $l; } }
    if ($line === '') { fwrite(STDERR, "the slice 7 seed failed:\n" . $out . "\n"); exit(1); }
    $v = explode('|', $line);
    return $ids = ['issuer' => (int) $v[1], 'security' => (int) $v[2], 'issuer2' => (int) $v[3], 'security2' => (int) $v[4], 'ev_short' => (int) $v[5], 'eloc' => (int) $v[6]];
}

/**
 * The Phase 4 world, once per run: slice 9's store (Alpha, Beta restricted to Dana, Gamma) and slice 7's crafted fixtures (SMOK, SMOK2) composed, plus tests/phase4/world.sql. Answers the ids.
 */
function p4_world(): array
{
    static $w = null;
    if ($w !== null) { return $w; }
    $cache = need('SI_DEV_STATE') . '/world-p4.json';
    if (is_file($cache)) { return $w = json_decode((string) file_get_contents($cache), true); }
    $s7 = seed7();          // first: its script takes "the" 8-K, 144 and 13D of its own issuer, so no other issuer may have filed yet
    $w = seed9();
    $w['smok_issuer'] = $s7['issuer']; $w['smok_security'] = $s7['security']; $w['smok2_issuer'] = $s7['issuer2']; $w['smok2_security'] = $s7['security2']; $w['ev_short'] = $s7['ev_short']; $w['eloc'] = $s7['eloc'];
    $out = (string) shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 2)) . ' && sudo -n -u postgres psql -v ON_ERROR_STOP=1 -At -F "|" -d ' . escapeshellarg(need('DB_NAME')) . ' -f tests/phase4/world.sql 2>&1');
    $line = '';
    foreach (explode("\n", trim($out)) as $l) { if (str_starts_with($l, 'p4world|')) { $line = $l; } }
    if ($line === '') { fwrite(STDERR, "the Phase 4 world failed:\n" . $out . "\n"); exit(1); }
    $v = explode('|', $line);
    foreach (['delta_issuer', 'delta_security', 'delta_holder_person', 'delta_holder_entity', 'delta_signal', 'delta_filing', 'delta_event', 'delta_report', 'smok_signal_open', 'smok_signal_proposed', 'smok_signal_disposed', 'report_draft',
              'report_approved', 'export_priya', 'key_priya', 'nobo_import', 'register_import', 'register2_import', 'upload_spr', 'filing_text'] as $i => $k) { $w[$k] = (int) ($v[$i + 1] ?? 0); }
    file_put_contents($cache, json_encode($w));
    return $w;
}
/** The tool surface (docs/shareholder-intelligence-mcp-tool-surface.md) as the proofs read it: the named tools of each server, sorted. */
function surface(): array
{
    static $s = null;
    if ($s !== null) { return $s; }
    $t = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/shareholder-intelligence-mcp-tool-surface.md');
    $a = strpos($t, '## Records server'); $b = strpos($t, '## The shares'); $c = strpos($t, '## Activity server'); $d = strpos($t, '### The activity vocabulary');
    preg_match_all('/^\| `([a-z_0-9]+)`/m', substr($t, $a, $b - $a), $rec);
    preg_match_all('/^\| `([a-z_0-9]+)`/m', substr($t, $c, $d - $c), $act);
    $shares = ['filings_index', 'filing_text', 'ownership_summary', 'signals_open', 'events_index'];
    $records = array_values(array_unique(array_merge($rec[1], ['app_roles', 'records_search'], $shares))); sort($records);
    $activity = $act[1]; sort($activity);
    $person = array_values(array_diff($records, $shares));
    return ['records' => $records, 'activity' => $activity, 'shares' => $shares, 'person' => $person, 'kernel_tools' => array_merge(['app_roles'], $shares)];
}
/** An agent's grants as maludb-os.json declares them: ['Records MCP' => [tool => []], 'Activity MCP' => ..., 'Actions MCP' => ...]. */
function agent_grants(string $key): array
{
    $m = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/maludb-os.json'), true);
    foreach ($m['agents'] as $a) { if ($a['key'] === $key) { return array_map(fn ($tools) => array_fill_keys($tools, []), $a['tool_grants']); } }
    return [];
}
/** The newest activity row of an action (any actor), or null. */
function last_event(string $action): ?array { $r = q('SELECT * FROM activity_log WHERE action = :a ORDER BY id DESC LIMIT 1', ['a' => $action]); return $r[0] ?? null; }
/** Names of natural persons in the world (holders of kind person: their display names, the registration names of their register rows, the first line of their NOBO rows): for the PII sweeps. Counts only are ever printed. */
function person_names(int $limit = 400): array
{
    $n = array_column(q("SELECT display_name FROM holders WHERE kind = 'person' AND length(display_name) > 6 ORDER BY id LIMIT $limit"), 'display_name');
    $r = array_column(q("SELECT DISTINCT g.registration_name FROM register_rows g JOIN holders h ON h.id = g.holder_id WHERE h.kind = 'person' AND length(g.registration_name) > 6 ORDER BY 1 LIMIT $limit"), 'registration_name');
    $x = array_column(q("SELECT DISTINCT n.line1 FROM nobo_rows n JOIN holders h ON h.id = n.holder_id WHERE h.kind = 'person' AND length(n.line1) > 6 ORDER BY 1 LIMIT $limit"), 'line1');
    return array_values(array_unique(array_merge($n, $r, $x)));
}
/** The street lines of natural persons' register rows: the address strings a Viewer must never read. */
function street_lines(int $limit = 200): array
{
    return array_values(array_unique(array_filter(array_column(q("SELECT DISTINCT g.address_line FROM register_rows g JOIN holders h ON h.id = g.holder_id WHERE h.kind = 'person' AND length(g.address_line) > 8 ORDER BY 1 LIMIT $limit"), 'address_line'))));
}
/** The fake kernel is stopped (its port closed) for a moment, then started again by the caller: the fail-closed proofs. */
function kernel_ctl(string $what): void { shell_exec(escapeshellarg(__DIR__ . '/kernel_ctl.sh') . ' ' . $what); }
function mcp_ctl(string $what): void { shell_exec(escapeshellarg(__DIR__ . '/mcp_servers.sh') . ' ' . $what); }

/**
 * Every records tool of a person (the 90) with arguments that make it answer in the world — SMOK's security (CUSIP 07831X206), the ids world.sql made. Answers [[tool, args], …] in the surface's order of groups.
 * The activity tools are in sweep_activity(). Used by reads.php (each answers), wall.php (what a Viewer and a restricted caller get) and coverage.php.
 */
function sweep(array $w): array
{
    static $c = [];
    $key = $w['smok_issuer'];
    if (isset($c[$key])) { return $c[$key]; }
    $sec = '07831X206';
    $ent = (int) one("SELECT id FROM holders WHERE issuer_id = :i AND kind = 'entity' AND deleted_at IS NULL AND merged_into_id IS NULL ORDER BY id LIMIT 1", ['i' => $w['smok_issuer']]);
    $inst = (int) one('SELECT institution_id FROM institutional_holdings WHERE security_id = :s ORDER BY id LIMIT 1', ['s' => $w['smok_security']]);
    $ben = (int) one('SELECT id FROM beneficial_ownership WHERE security_id = :s ORDER BY id LIMIT 1', ['s' => $w['smok_security']]);
    $win = ['from' => '2024-03-22', 'to' => '2024-04-04'];
    $s = ['security' => $sec];
    return $c[$key] = [
        ['search', ['q' => 'SMOKE', 'limit' => 5]], ['my_notifications', []], ['get_settings', []], ['find_members', ['q' => 'SMOKE']], ['find_departments', []], ['find_tags', ['q' => 'p4']],
        ['find_notes', ['kind' => 'issuer', 'record' => $w['smok_issuer']]],
        ['find_issuers', ['q' => 'SMOKE']], ['get_issuer', ['issuer' => 'SMOK']], ['find_securities', ['issuer' => 'SMOK']], ['share_counts', $s + ['history' => true]], ['issuer_restrictions', []],
        ['find_participants', ['q' => 'Schwab']], ['get_participant', ['participant' => '0164'] + $s], ['find_imports', ['issuer' => 'SMOK']], ['get_import', ['upload' => $w['nobo_import']]],
        ['import_rows', ['upload' => $w['register_import'], 'limit' => 5]], ['positions_on', $s + ['date' => '2024-04-04']], ['position_changes', $s + $win], ['participants_new_lost', $s + $win], ['participant_flow', $s + $win],
        ['concentration', $s], ['participant_series', $s + $win + ['participant' => '0164']], ['spr_total', $s + ['date' => '2024-04-04']], ['reconciliation_latest', $s], ['reconciliation_history', $s],
        ['find_holders', ['issuer' => 'SMOK', 'limit' => 5]], ['get_holder', ['holder' => $ent]], ['holder_history', ['holder' => $ent]],
        ['holders_changes', $s + ['channel' => 'register', 'date_a' => '2024-03-04', 'date_b' => '2024-04-04']], ['holders_by_range', $s + ['channel' => 'register']], ['holders_by_geography', $s + ['channel' => 'nobo']],
        ['holders_by_custodian', $s], ['holders_by_type', $s], ['holders_unmatched', ['issuer' => 'SMOK', 'limit' => 5]], ['match_proposals', ['limit' => 5]],
        ['obo_remainder', $s + ['record_date' => '2024-04-04']], ['participant_nobo_summary', $s + ['participant' => '0164']], ['share_ranges', $s], ['obo_totals', $s], ['insider_holdings', ['issuer' => 'SMOK']],
        ['find_institutions', ['limit' => 5]], ['get_institution', ['institution' => $inst, 'security' => $sec]], ['institution_history', $s + ['institution' => $inst]], ['institutional_holders', $s], ['holdings_changes', $s],
        ['holdings_summary', $s], ['holdings_put_call', $s], ['beneficial_owners', $s], ['get_beneficial_filing', ['beneficial_id' => $ben]],
        ['insider_transactions', ['issuer' => 'SMOK', 'from' => '2024-01-01', 'to' => '2024-12-31']], ['form144_notices', ['issuer' => 'SMOK', 'from' => '2024-01-01', 'to' => '2024-12-31']],
        ['find_filings', ['issuer' => 'SMOK', 'limit' => 5]], ['get_filing', ['filing' => $w['filing_text']]], ['filing_read', ['filing' => $w['filing_text'], 'length' => 200]], ['filings_search', ['issuer' => 'SMOK', 'q' => 'equity line']],
        ['watch_hits', ['issuer' => 'SMOK']], ['dilution_instruments', ['issuer' => 'SMOK']], ['dilution_proposals', ['issuer' => 'SMOK']],
        ['short_interest_series', $s + ['from' => '2024-01-01', 'to' => '2024-04-30']], ['short_vs_float', $s + ['as_of' => '2024-04-05']], ['daily_short_volume_series', $s + ['from' => '2024-03-01', 'to' => '2024-04-30']],
        ['fails_series', $s + ['from' => '2024-01-01', 'to' => '2024-04-30']], ['threshold_status', $s], ['threshold_history', $s], ['borrow_series', $s], ['short_side_overlay', $s + ['from' => '2024-03-01', 'to' => '2024-04-30']],
        ['find_signals', ['issuer' => 'SMOK']], ['get_signal', ['signal' => $w['smok_signal_open']]], ['signals_history', ['issuer' => 'SMOK', 'from' => '2024-01-01', 'to' => '2024-12-31']], ['rules_catalogue', ['issuer' => 'SMOK']],
        ['find_rules', ['q' => 'short']], ['signal_outcomes', []],
        ['events_on', ['issuer' => 'SMOK', 'date' => '2024-04-03', 'days' => 3]], ['find_events', ['issuer' => 'SMOK']], ['events_upcoming', ['issuer' => 'SMOK']], ['find_reports', ['issuer' => 'SMOK']],
        ['get_report', ['report' => $w['report_approved']]], ['find_exports', []],
        ['analyst_proposals', []], ['agent_dispatches', []], ['members_with_roles', []], ['find_keys', []], ['key_usage_summary', ['api_key' => 'p4 priya key']], ['find_sources', []], ['source_health', ['pulls' => 1]],
        ['find_templates', []], ['tombstones', []],
        ['app_roles', []], ['records_search', ['view' => 'mcp_issuers', 'limit' => 5]],
    ];
}
/** The seven activity tools with arguments that answer in the world. */
function sweep_activity(array $w): array
{
    return [
        ['activity_search', ['q' => 'signal']], ['record_history', ['kind' => 'signal', 'record' => $w['smok_signal_open']]], ['actor_history', ['member' => 'me']],
        ['record_episodes', ['kind' => 'signal', 'record' => $w['smok_signal_open']]], ['recent_activity', ['since' => '2020-01-01']], ['agent_activity', []],
        ['activity_timeline', ['issuer' => 'SMOK', 'from' => date('Y-01-01'), 'to' => date('Y-12-31')]],
    ];
}
