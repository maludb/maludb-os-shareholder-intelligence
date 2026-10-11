<?php
declare(strict_types=1);

/**
 * The events' reads (events-reports.md "Query functions", signatures fixed). Every read goes through mcp_events — the issuer wall — so an event of an
 * issuer the member may not see does not exist to them. `events_upcoming` adds the computed days the calendar knows (FINRA's short-interest publication,
 * the 13F deadline) and a dilution instrument's expiry: no row is stored for them.
 */
const EVENT_KINDS = ['filing' => 'Filing', 'financing' => 'Financing', 'dilution_instrument' => 'Dilution instrument', 'record_date' => 'Record date', 'earnings' => 'Earnings', 'press' => 'Press',
    'activist_short_report' => 'Short report', 'lawsuit' => 'Lawsuit', 'exchange_notice' => 'Exchange notice', 'insider_transaction' => 'Insider transaction', 'share_count' => 'Share count',
    'threshold' => 'Threshold list', 'note' => 'Note', 'other' => 'Other'];
const EVENT_SEVERITIES = ['info' => 'Info', 'watch' => 'Watch', 'high' => 'High'];
const EVENT_PAGE_SIZE = 50;

/** The columns an event list shows: the event, its filing's form and date, the security's ticker. */
const EVENT_COLUMNS = "e.event_id, e.issuer_id, e.security_id, e.on_date, e.kind, e.title, e.body, e.url, e.filing_id, e.severity, e.source, e.tags, e.created_by, e.created_at, e.updated_at,
       (SELECT f.form FROM mcp_filings f WHERE f.filing_id = e.filing_id) AS filing_form,
       (SELECT f.filed_on FROM mcp_filings f WHERE f.filing_id = e.filing_id) AS filing_filed_on,
       (SELECT s.ticker FROM mcp_securities s WHERE s.security_id = e.security_id) AS ticker";

/** The WHERE of an events list from its filters (kind, severity, from, to, q, security); $args filled. */
function events_where(int $issuer, array $f, array &$args): string
{
    $w = ['e.issuer_id = :iss'];
    $args = ['iss' => $issuer];
    $kinds = array_values(array_filter((array) ($f['kind'] ?? []), static fn ($k): bool => isset(EVENT_KINDS[$k])));
    if ($kinds !== []) {
        $in = [];
        foreach ($kinds as $i => $k) { $in[] = ':k' . $i; $args['k' . $i] = $k; }
        $w[] = 'e.kind IN (' . implode(', ', $in) . ')';
    }
    $sevs = array_values(array_filter((array) ($f['severity'] ?? []), static fn ($s): bool => isset(EVENT_SEVERITIES[$s])));
    if ($sevs !== []) {
        $in = [];
        foreach ($sevs as $i => $s) { $in[] = ':v' . $i; $args['v' . $i] = $s; }
        $w[] = 'e.severity IN (' . implode(', ', $in) . ')';
    }
    if (is_string($f['from'] ?? null) && $f['from'] !== '') { $w[] = 'e.on_date >= CAST(:from AS date)'; $args['from'] = $f['from']; }
    if (is_string($f['to'] ?? null) && $f['to'] !== '') { $w[] = 'e.on_date <= CAST(:to AS date)'; $args['to'] = $f['to']; }
    if (isset($f['security']) && (int) $f['security'] > 0) { $w[] = 'e.security_id = :sec'; $args['sec'] = (int) $f['security']; }
    if (is_string($f['q'] ?? null) && trim($f['q']) !== '') {
        $w[] = "(e.title ILIKE :q OR coalesce(e.body, '') ILIKE :q)";
        $args['q'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($f['q'])) . '%';
    }
    return implode(' AND ', $w);
}

/** A page of an issuer's events, the newest first: ['rows', 'total', 'page', 'pages']. */
function find_events(PDO $pdo, int $issuer, array $filters, int $page): array
{
    $args = [];
    $where = events_where($issuer, $filters, $args);
    $total = (int) one_value($pdo, "SELECT count(*) FROM mcp_events e WHERE $where", $args);
    $pages = max(1, (int) ceil($total / EVENT_PAGE_SIZE));
    $page = min(max(1, $page), $pages);
    $st = $pdo->prepare('SELECT ' . EVENT_COLUMNS . " FROM mcp_events e WHERE $where ORDER BY e.on_date DESC, e.event_id DESC LIMIT " . EVENT_PAGE_SIZE . ' OFFSET ' . (($page - 1) * EVENT_PAGE_SIZE));
    $st->execute($args);
    return ['rows' => $st->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
}

/** One event the caller may see (a tombstoned one is not there), with its filing, its security and its author — or null. */
function find_event(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT ' . EVENT_COLUMNS . ", (SELECT f.accession_number FROM mcp_filings f WHERE f.filing_id = e.filing_id) AS filing_accession,
                                (SELECT i.name FROM mcp_issuers i WHERE i.issuer_id = e.issuer_id) AS issuer_name,
                                (SELECT s.name FROM mcp_securities s WHERE s.security_id = e.security_id) AS security_name,
                                (SELECT m.display_name FROM members m WHERE m.id = e.created_by) AS created_by_name
                           FROM mcp_events e WHERE e.event_id = :id");
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** An event for the tombstone's restore: the base row of an issuer the caller may see, deleted or not. Null when absent or behind the wall. */
function event_base_row(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT e.*, e.id AS event_id FROM events e WHERE e.id = :id AND e.issuer_id IN (SELECT si_my_issuers())');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** What happened on a date, and within $days (0–14) of it either side. */
function events_on(PDO $pdo, int $issuer, string $date, int $days): array
{
    $days = max(0, min(14, $days));
    $st = $pdo->prepare('SELECT e.event_id, e.on_date, e.kind, e.title, e.severity, e.url, e.filing_id, e.source FROM mcp_events e
                          WHERE e.issuer_id = :i AND e.on_date BETWEEN CAST(:d AS date) - :n AND CAST(:d2 AS date) + :n2 ORDER BY e.on_date, e.event_id');
    $st->execute(['i' => $issuer, 'd' => $date, 'n' => $days, 'd2' => $date, 'n2' => $days]);
    return $st->fetchAll();
}

/** The signals an event explains (dismissed with it as the reason), through the signals' wall. */
function event_explains(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT signal_id, rule_name, severity, status, raised_on, summary FROM mcp_signals WHERE event_id = :id ORDER BY raised_on DESC, signal_id DESC');
    $st->execute(['id' => $id]);
    return $st->fetchAll();
}

/**
 * What is coming in the next $days (30): stored events from today on, a dilution instrument's expiry, and the computed days — FINRA's next
 * short-interest publications and the next 13F deadlines. Rows {on_date, kind, title, source: person|worker|agent|computed, severity, event_id?, url?}.
 */
function events_upcoming(PDO $pdo, int $issuer, int $days = 30): array
{
    require_once dirname(__DIR__) . '/calendar/queries.php';
    $days = max(1, min(366, $days));
    $today = (new DateTimeImmutable('now', si_market_tz($pdo)))->format('Y-m-d');
    $to = (new DateTimeImmutable($today))->modify('+' . $days . ' days')->format('Y-m-d');
    $rows = [];
    $st = $pdo->prepare('SELECT event_id, on_date, kind, title, severity, source, url FROM mcp_events WHERE issuer_id = :i AND on_date BETWEEN CAST(:a AS date) AND CAST(:b AS date) ORDER BY on_date, event_id');
    $st->execute(['i' => $issuer, 'a' => $today, 'b' => $to]);
    foreach ($st->fetchAll() as $r) { $rows[] = $r; }
    foreach (calendar_expiries($pdo, $issuer, $today, $to) as $r) { $rows[] = $r; }
    foreach (calendar_computed($pdo, $today, $to) as $r) { $rows[] = $r; }
    usort($rows, static fn (array $a, array $b): int => [$a['on_date'], $a['title']] <=> [$b['on_date'], $b['title']]);
    return $rows;
}
