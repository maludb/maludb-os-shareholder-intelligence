<?php
declare(strict_types=1);

/**
 * The agents' reads (agents-k7-api.md "Query functions", signatures fixed): the dispatches (mcp_agent_dispatches — every agent's for a holder of agents.settings, an agent's own
 * for the agent), the agents with their last dispatch, the settings the Analyst runs under, and the door the proof (and Phase 4's records server) calls the five K7 shares through.
 * The shares are db/018's functions: they run as the application, name an issuer by id, ticker or CIK, never share a restricted issuer, and let no natural person cross.
 */

const DISPATCH_PAGE = 25;
const DISPATCH_KINDS = ['signal' => 'A high signal', 'filing' => 'A filing', 'upload_done' => 'An upload read', 'match' => 'A holder match', 'pack' => 'The weekly pack', 'ask' => 'A question', 'duty_proposal' => 'A proposal'];
const DISPATCH_STATUSES = ['sent' => 'Sent', 'answered' => 'Answered', 'refused' => 'Refused', 'failed' => 'Failed', 'awaiting_approval' => 'Awaiting approval', 'cancelled' => 'Cancelled'];
/** The chips of the status column (agents-k7-api.md "Status vocabulary"); `sent` is a turn still to be made or in flight. */
const DISPATCH_STATUS_CHIPS = ['sent' => 'text-bg-secondary', 'answered' => 'text-bg-success', 'refused' => 'text-bg-secondary', 'failed' => 'text-bg-danger', 'awaiting_approval' => 'text-bg-warning', 'cancelled' => 'text-bg-light'];
/** A dispatch that can still be cancelled: it has not been answered. */
const DISPATCH_CANCELLABLE = ['sent', 'failed', 'awaiting_approval'];
/** The K7 shares this application provides (maludb-os.json shares[]): tool => the db/018 function and its arguments. */
const SHARE_TOOLS = ['filings_index', 'filing_text', 'ownership_summary', 'signals_open', 'events_index'];

/**
 * A page of dispatches: filters status, kind, agent (a member). ['rows', 'total', 'page', 'pages', 'counts' => status => n]. The view shows a holder of agents.settings every
 * dispatch and an agent its own; nothing else answers.
 */
function find_dispatches(PDO $pdo, array $filters, int $page): array
{
    $w = ['true'];
    $args = [];
    if (isset(DISPATCH_STATUSES[$filters['status'] ?? ''])) {
        $w[] = 'd.status = :st';
        $args['st'] = $filters['status'];
    }
    if (isset(DISPATCH_KINDS[$filters['kind'] ?? ''])) {
        $w[] = 'd.kind = :k';
        $args['k'] = $filters['kind'];
    }
    if (($filters['agent'] ?? null) !== null) {
        $w[] = 'd.agent_member_id = :a';
        $args['a'] = (int) $filters['agent'];
    }
    $where = implode(' AND ', $w);
    $total = (int) one_value($pdo, "SELECT count(*) FROM mcp_agent_dispatches d WHERE $where", $args);
    $pages = max(1, (int) ceil($total / DISPATCH_PAGE));
    $page = min(max(1, $page), $pages);
    $st = $pdo->prepare("SELECT d.*, (SELECT display_name FROM mcp_members m WHERE m.member_id = d.agent_member_id) AS agent_name,
                                (SELECT display_name FROM mcp_members m WHERE m.member_id = d.acting_member_id) AS acting_name
                           FROM mcp_agent_dispatches d WHERE $where ORDER BY d.dispatch_id DESC LIMIT " . DISPATCH_PAGE . ' OFFSET ' . (($page - 1) * DISPATCH_PAGE));
    $st->execute($args);
    $counts = array_fill_keys(array_keys(DISPATCH_STATUSES), 0);
    foreach ($pdo->query('SELECT status, count(*) AS n FROM mcp_agent_dispatches GROUP BY status')->fetchAll() as $c) {
        $counts[$c['status']] = (int) $c['n'];
    }
    return ['rows' => $st->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages, 'counts' => $counts];
}

/**
 * The agents screen's other reads, in one: ['agents' => each agent admitted here with its roles and its last dispatch, 'analyst' => the Surveillance Analyst's member id (or null),
 * 'settings' => the morning note, proposals, the pack's day, the Analyst's issuers and the escalation member (a name beside the id), 'issuers' => the issuers the caller may see]. Call after agents.settings.
 */
function agents_overview(PDO $pdo): array
{
    $agents = $pdo->query("SELECT m.member_id, m.display_name, m.job_title, m.roles_resolved FROM mcp_members m WHERE m.member_kind = 'agent' ORDER BY m.display_name")->fetchAll();
    $last = $pdo->prepare('SELECT dispatch_id, kind, status, created_at, answered_at FROM mcp_agent_dispatches WHERE agent_member_id = :a ORDER BY dispatch_id DESC LIMIT 1');
    $recent = $pdo->prepare("SELECT status, count(*) AS n FROM mcp_agent_dispatches WHERE agent_member_id = :a AND created_at > now() - interval '7 days' GROUP BY status");
    foreach ($agents as &$a) {
        $last->execute(['a' => (int) $a['member_id']]);
        $a['last'] = $last->fetch() ?: null;
        $recent->execute(['a' => (int) $a['member_id']]);
        $a['week'] = array_column($recent->fetchAll(), 'n', 'status');
        $a['roles'] = pg_text_array((string) $a['roles_resolved']);
    }
    unset($a);
    $s = $pdo->query('SELECT s.morning_note_enabled, s.morning_note_hour, s.analyst_proposals, s.pack_day, s.analyst_issuer_ids, s.escalate_high_to, s.market_timezone,
                             (SELECT display_name FROM members m WHERE m.id = s.escalate_high_to) AS escalate_name FROM si_settings s WHERE s.id = 1')->fetch();
    $analyst = one_value($pdo, 'SELECT si_analyst_agent()');
    return ['agents' => $agents, 'analyst' => $analyst === null ? null : (int) $analyst,
            'settings' => ['morning_note_enabled' => (bool) $s['morning_note_enabled'], 'morning_note_hour' => (int) $s['morning_note_hour'], 'analyst_proposals' => (bool) $s['analyst_proposals'],
                           'pack_day' => (int) $s['pack_day'], 'analyst_issuer_ids' => pg_int_array((string) $s['analyst_issuer_ids']), 'escalate_high_to' => $s['escalate_high_to'] === null ? null : (int) $s['escalate_high_to'],
                           'escalate_name' => $s['escalate_name'], 'market_timezone' => $s['market_timezone']],
            'issuers' => my_issuers($pdo)];
}

/** One dispatch the caller may see (the view), or null. */
function find_dispatch(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_agent_dispatches WHERE dispatch_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/**
 * One of the five K7 shares, called as a consumer would through the kernel: $tool is filings_index, filing_text, ownership_summary, signals_open or events_index; $consumer the catalog
 * key the kernel names in X-OS-Consumer (K26 — an empty one is refused by the function itself); $args the tool's arguments. The caller's $pdo should be the records role (the proof's is):
 * the function is SECURITY DEFINER and answers the same to anyone granted it. Answers the function's JSON as an array; an unknown or restricted issuer is {error: not_found}. Logs `share.read`
 * {application, tool, issuer_id, rows, ms} on the application's own connection, never the answer.
 */
function share_call(PDO $pdo, string $tool, string $consumer, array $args): array
{
    $t0 = microtime(true);
    $issuer = isset($args['issuer']) ? (string) $args['issuer'] : '';
    $limit = isset($args['limit']) ? (int) $args['limit'] : null;
    $sql = match ($tool) {
        'filings_index' => ['SELECT si_share_filings_index(:c, :i, CAST(:since AS date), CAST(:forms AS text[]), COALESCE(CAST(:lim AS integer), 100), COALESCE(CAST(:off AS integer), 0))',
            ['c' => $consumer, 'i' => $issuer, 'since' => $args['since'] ?? null, 'forms' => isset($args['forms']) && is_array($args['forms']) ? pg_array_literal(array_map('strval', $args['forms'])) : null,
             'lim' => $limit, 'off' => isset($args['offset']) ? (int) $args['offset'] : null]],
        'filing_text' => ['SELECT si_share_filing_text(:c, :f)', ['c' => $consumer, 'f' => (int) ($args['filing'] ?? 0)]],
        'ownership_summary' => ['SELECT si_share_ownership_summary(:c, :i, CAST(:as_of AS date))', ['c' => $consumer, 'i' => $issuer, 'as_of' => $args['as_of'] ?? null]],
        'signals_open' => ['SELECT si_share_signals_open(:c, :i)', ['c' => $consumer, 'i' => $issuer]],
        'events_index' => ['SELECT si_share_events_index(:c, :i, CAST(:from AS date), CAST(:to AS date), COALESCE(CAST(:lim AS integer), 100))',
            ['c' => $consumer, 'i' => $issuer, 'from' => $args['from'] ?? null, 'to' => $args['to'] ?? null, 'lim' => $limit]],
        default => throw new InvalidArgumentException('Not one of the shares: ' . $tool),
    };
    $st = $pdo->prepare($sql[0]);
    $st->execute($sql[1]);
    $out = json_decode((string) $st->fetchColumn(), true);
    $out = is_array($out) ? $out : ['error' => 'no_answer'];
    $rows = isset($out['error']) ? 0 : match ($tool) {
        'filings_index' => count($out['filings'] ?? []), 'signals_open' => count($out['signals'] ?? []), 'events_index' => count($out['events'] ?? []), default => 1,
    };
    $issuerId = isset($out['issuer_id']) ? (int) $out['issuer_id'] : (isset($out['issuer']['issuer_id']) ? (int) $out['issuer']['issuer_id'] : null);
    log_activity(db(), 'share.read', 'share', null, ['source' => 'application', 'actor_member_id' => null, 'issuer_id' => $issuerId,
        'after' => ['application' => $consumer, 'tool' => $tool, 'issuer_id' => $issuerId, 'rows' => $rows, 'error' => $out['error'] ?? null, 'ms' => (int) round((microtime(true) - $t0) * 1000)]]);
    return $out;
}
