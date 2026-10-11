<?php
declare(strict_types=1);

/**
 * The admin's reads (admin-settings.md "Query functions", signatures fixed): the sources with their health and the calendar's next slot, a source with
 * its credential's label and last four and its pulls, the participant classes, the import templates, the settings form, everyone's tokens and the
 * tombstones. The walls are the database's (mcp_* views, si_source_health(), si_tombstones()); a credential is read as its label and last four —
 * the secret is opened in app/si/fetch/credentials.php and nowhere else. (find_source was built ahead of the slice for the credential door.)
 */
require_once dirname(__DIR__, 2) . '/si/fetch/source.php';
require_once dirname(__DIR__, 2) . '/si/fetch/calendar.php';

/** The kinds of import template (db/014, widened in db/017). */
const TEMPLATE_KINDS = ['spr' => 'DTC Security Position Report', 'nobo' => 'Broadridge NOBO list', 'register' => 'Transfer agent register', 'prices_csv' => 'Prices (CSV)', 'holders_csv' => 'Holders (CSV)',
                        'share_range' => 'Broadridge Share Range Analysis'];

/** The exchanges the settings admit (si_settings.default_exchange). */
const EXCHANGES = ['nasdaq' => 'Nasdaq', 'nyse' => 'NYSE', 'nyse_american' => 'NYSE American', 'otc' => 'OTC markets', 'cse' => 'CSE', 'tsx' => 'TSX', 'tsxv' => 'TSX Venture', 'other' => 'Another exchange'];

/** The price sources the default may name (the connectors that read prices). */
const PRICE_SOURCES = ['prices_alphavantage' => 'Alpha Vantage (a key)', 'prices_polygon' => 'Polygon (a key)', 'prices_stooq' => 'stooq (refuses machines; paused)', 'prices_manual' => 'Uploaded by hand'];

/** Who may export names and addresses (si_settings.pii_in_exports). */
const PII_IN_EXPORTS = ['analyst' => 'An Analyst and above', 'admin' => 'Only an administrator', 'nobody' => 'Nobody'];

/** One source as the admin sees it (mcp_sources) with its credential's label and last four (mcp_source_credentials — never the secret), or null. */
function find_source(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_sources WHERE source_id = :id');
    $st->execute(['id' => $id]);
    $s = $st->fetch();
    if ($s === false) {
        return null;
    }
    $st = $pdo->prepare('SELECT c.credential_id, c.kind, c.label, c.last4, c.rotated_at, c.created_at FROM mcp_source_credentials c WHERE c.source_id = :id ORDER BY c.credential_id DESC LIMIT 1');
    $st->execute(['id' => $id]);
    $s['credential'] = $st->fetch() ?: null;
    return $s;
}

/** The source row as the edit form needs it (the user-agent override included), or null. */
function find_source_base(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id, name, connector, role, base_url, settings, credential_id, schedule_minutes, rate_per_second, user_agent, robots_state, active, paused_at, paused_reason, last_ok_at, backoff_until FROM sources WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/**
 * When the worker next pulls a source: after its schedule has run (the last good pull + the minutes), after any back-off, on the connector's own calendar
 * (market days and hours; a manual pull ignores it). The worker's timer runs every five minutes, so a slot is a five-minute mark; the walk looks ahead
 * in half-hours. null = never by itself: inactive, paused, manual (no schedule), read by a later slice, or nothing within three weeks.
 */
function source_next_slot(PDO $pdo, array $s, ?DateTimeImmutable $now = null): ?DateTimeImmutable
{
    $minutes = (int) ($s['schedule_minutes'] ?? 0);
    if (!$s['active'] || $s['paused_at'] !== null || $minutes <= 0 || !isset(si_fetchers()[$s['connector']])) {
        return null;
    }
    $now ??= new DateTimeImmutable('now');
    $t = $now;
    if ($s['last_ok_at'] !== null) {
        $t = max($t, (new DateTimeImmutable((string) $s['last_ok_at']))->modify('+' . $minutes . ' minutes'));
    }
    if ($s['backoff_until'] !== null) {
        $t = max($t, new DateTimeImmutable((string) $s['backoff_until']));
    }
    $t = $t->setTimestamp((int) (ceil($t->getTimestamp() / 300) * 300));
    $src = ['connector' => $s['connector'], 'settings' => is_array($s['settings']) ? json_encode($s['settings']) : (string) $s['settings'], 'last_ok_at' => $s['last_ok_at']];
    for ($i = 0; $i < 1000; $i++) {
        if (si_source_on_calendar($pdo, $src, $t)) {
            return $t;
        }
        $t = $t->setTimestamp((int) (floor($t->getTimestamp() / 1800) * 1800) + 1800);
    }
    return null;
}

/**
 * The fetchers with their health: name, connector, schedule, the last pull, failures, the back-off, paused, whether a credential is set, whether the EDGAR user-agent is missing,
 * and the calendar's next slot. $connector narrows to one connector. Ordered as the database keeps them.
 */
function sources_overview(PDO $pdo, ?string $connector = null): array
{
    $st = $pdo->prepare('SELECT s.source_id, s.name, s.connector, s.role, s.base_url, s.settings, s.schedule_minutes, s.rate_per_second, s.robots_state, s.last_ok_at, s.consecutive_failures, s.backoff_until,
                                s.paused_at, s.paused_reason, s.active, s.has_credential, h.due, h.last_status, h.last_error, h.needs_user_agent, h.user_agent_set,
                                (SELECT p.started_at FROM mcp_source_pulls p WHERE p.source_id = s.source_id ORDER BY p.pull_id DESC LIMIT 1) AS last_pull_at,
                                (SELECT p.pull_id FROM mcp_source_pulls p WHERE p.source_id = s.source_id ORDER BY p.pull_id DESC LIMIT 1) AS last_pull_id
                           FROM mcp_sources s LEFT JOIN si_source_health() h ON h.source_id = s.source_id
                          WHERE (CAST(:c AS text) IS NULL OR s.connector = CAST(:c AS text)) ORDER BY s.source_id');
    $st->execute(['c' => $connector === '' ? null : $connector]);
    $now = new DateTimeImmutable('now');
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['next_slot'] = source_next_slot($pdo, $r, $now);
        $r['edgar'] = str_starts_with((string) $r['connector'], 'edgar_') || $r['connector'] === 'sec_ftd';
    }
    unset($r);
    return $rows;
}

/** The connectors present, with a count each (the sources screen's filter). */
function source_connectors(PDO $pdo): array
{
    return $pdo->query('SELECT connector, count(*) AS n FROM mcp_sources GROUP BY connector ORDER BY connector')->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** The last $limit pulls of a source, newest first: status, counts, requests, bytes, the error and the policy followed. */
function source_pulls(PDO $pdo, int $id, int $limit = 50): array
{
    $st = $pdo->prepare('SELECT pull_id, source_id, kind, started_at, finished_at, status, items_seen, items_new, items_changed, http_requests, bytes, error, policy, started_by
                           FROM mcp_source_pulls WHERE source_id = :id ORDER BY pull_id DESC LIMIT ' . max(1, min(200, $limit)));
    $st->execute(['id' => $id]);
    return $st->fetchAll();
}

/** The participant classes in their order with the number of participants in each (all, and the ones a person classed): [{class_key, name, description, side, count, manual}]. */
function classes_overview(PDO $pdo): array
{
    return $pdo->query("SELECT k.class_key, k.name, k.description, k.side, k.sort_order,
                               (SELECT count(*) FROM mcp_participants p WHERE p.class_key = k.class_key) AS count,
                               (SELECT count(*) FROM mcp_participants p WHERE p.class_key = k.class_key AND p.class_source = 'manual') AS manual
                          FROM mcp_participant_classes k ORDER BY k.sort_order")->fetchAll();
}

/** The import templates by kind (all kinds when $kind is null or empty): [{template_id, name, kind, connector, mapping (as the text), updated_at}]. */
function find_templates(PDO $pdo, ?string $kind = null): array
{
    $st = $pdo->prepare('SELECT template_id, name, kind, connector, mapping, updated_at FROM mcp_import_templates WHERE (CAST(:k AS text) IS NULL OR kind = CAST(:k AS text)) ORDER BY kind, name');
    $st->execute(['k' => $kind === '' ? null : $kind]);
    return $st->fetchAll();
}

function find_template(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT template_id, name, kind, connector, mapping, updated_at FROM mcp_import_templates WHERE template_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** The settings as the form shows them: the whole si_settings row (the admin only), the EDGAR user-agent it makes (or null), the escalation member's name. */
function settings_form(PDO $pdo): array
{
    $s = $pdo->query('SELECT * FROM si_settings WHERE id = 1')->fetch() ?: [];
    $s['market_holidays'] = array_values(array_filter(array_map('trim', explode(',', trim((string) ($s['market_holidays'] ?? ''), '{}')))));
    $s['edgar_user_agent'] = one_value($pdo, 'SELECT si_edgar_user_agent()') ?: null;
    $s['escalate_name'] = $s['escalate_high_to'] === null ? null : one_value($pdo, 'SELECT display_name FROM mcp_members WHERE member_id = :m', ['m' => (int) $s['escalate_high_to']]);
    return $s;
}

/** Everyone's MCP tokens and API keys (the admin's view, mcp_all_tokens): live first, then newest; $member narrows to one person. Never a hash. */
function all_tokens(PDO $pdo, ?int $member = null): array
{
    $st = $pdo->prepare('SELECT token_id, member_id, member_name, member_kind, label, scope, rate_per_minute, rate_per_day, rotated_from, last_used_at, expires_at, revoked_at, created_at, calls_today
                           FROM mcp_all_tokens WHERE (CAST(:m AS bigint) IS NULL OR member_id = CAST(:m AS bigint)) ORDER BY revoked_at IS NOT NULL, created_at DESC, token_id DESC');
    $st->execute(['m' => $member]);
    return $st->fetchAll();
}

function find_token_any(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT token_id, member_id, member_name, member_kind, label, scope, revoked_at FROM mcp_all_tokens WHERE token_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** The people who hold a token (the Tokens screen's member filter). */
function token_holders(PDO $pdo): array
{
    return $pdo->query('SELECT member_id, member_name, count(*) AS n FROM mcp_all_tokens GROUP BY member_id, member_name ORDER BY member_name')->fetchAll();
}

/** What waits in the tombstone (si_tombstones(): a holder's name only for who may see it), with whether each is past its window; $kind narrows. */
function tombstones(PDO $pdo, ?string $kind = null): array
{
    $st = $pdo->prepare('SELECT kind, record_id, label, issuer_id, deleted_at, deleted_by, purge_after, purge_after <= now() AS due,
                                (SELECT m.display_name FROM mcp_members m WHERE m.member_id = t.deleted_by) AS deleted_by_name
                           FROM si_tombstones() t WHERE (CAST(:k AS text) IS NULL OR kind = CAST(:k AS text)) ORDER BY deleted_at DESC, kind, record_id');
    $st->execute(['k' => $kind === '' ? null : $kind]);
    return $st->fetchAll();
}
