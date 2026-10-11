<?php
declare(strict_types=1);

/**
 * The signals' reads (signals-rules.md "Query functions", signatures fixed). Every read goes through mcp_signals — the issuer wall — so a
 * signal of an issuer the member may not see does not exist to them; the rules' catalogue through mcp_signal_rules. The page never
 * recomputes a rule: it reads what the rule wrote (`evidence`), and the chart reads the window's own rows.
 */

/** The status filter's values: `live` (open and escalated — what still needs a person) is the default. */
const SIGNAL_STATUS_FILTERS = ['live' => 'Needs a person', 'open' => 'Open', 'escalated' => 'Escalated', 'acknowledged' => 'Acknowledged', 'dismissed' => 'Dismissed', 'expired' => 'Expired', 'all' => 'All'];
const SIGNAL_PAGE_SIZE = 30;

/** The WHERE of a signals list from its filters (status, severity, rule, security, class, from, to); $args filled. */
function signals_where(int $issuer, array $f, array &$args, string $defaultStatus): string
{
    $w = ['s.issuer_id = :iss'];
    $args = ['iss' => $issuer];
    $status = (string) ($f['status'] ?? $defaultStatus);
    if ($status === 'live') {
        $w[] = "s.status IN ('open', 'escalated')";
    } elseif ($status !== 'all' && isset(SIGNAL_STATUS_FILTERS[$status])) {
        $w[] = 's.status = :st';
        $args['st'] = $status;
    }
    if (in_array($f['severity'] ?? null, ['info', 'watch', 'high'], true)) {
        $w[] = 's.severity = :sev';
        $args['sev'] = $f['severity'];
    }
    if (is_string($f['rule'] ?? null) && $f['rule'] !== '') {
        $w[] = 's.rule_key = :rule';
        $args['rule'] = $f['rule'];
    }
    if (isset($f['security']) && (int) $f['security'] > 0) {
        $w[] = 's.security_id = :sec';
        $args['sec'] = (int) $f['security'];
    }
    if (in_array($f['class'] ?? null, ['established', 'contested', 'lore', 'data_defect'], true)) {
        $w[] = 's.evidence_class = :cls';
        $args['cls'] = $f['class'];
    }
    if (is_string($f['from'] ?? null)) {
        $w[] = 's.raised_on >= CAST(:from AS date)';
        $args['from'] = $f['from'];
    }
    if (is_string($f['to'] ?? null)) {
        $w[] = 's.raised_on <= CAST(:to AS date)';
        $args['to'] = $f['to'];
    }
    return implode(' AND ', $w);
}

/** The columns every signals list shows: the signal, its rule's basis, its security, who proposed and who disposed. */
const SIGNAL_LIST_COLUMNS = "s.*, r.basis, r.description AS rule_description, sec.name AS security_name, sec.ticker, sec.is_primary,
       (SELECT display_name FROM mcp_members m WHERE m.member_id = s.proposal_by) AS proposal_by_name,
       (SELECT member_kind FROM mcp_members m WHERE m.member_id = s.proposal_by) AS proposal_by_kind,
       (SELECT display_name FROM mcp_members m WHERE m.member_id = s.disposed_by) AS disposed_by_name,
       (SELECT title FROM mcp_events e WHERE e.event_id = s.event_id) AS event_title,
       (SELECT on_date FROM mcp_events e WHERE e.event_id = s.event_id) AS event_on,
       (SELECT title FROM mcp_events e WHERE e.event_id = s.proposal_event_id) AS proposal_event_title,
       (SELECT on_date FROM mcp_events e WHERE e.event_id = s.proposal_event_id) AS proposal_event_on";

/** A page of an issuer's signals by severity (high first), then the newest: ['rows', 'total', 'page', 'pages', 'counts' => [severity => n]]. */
function find_signals(PDO $pdo, int $issuer, array $filters, int $page): array
{
    $args = [];
    $where = signals_where($issuer, $filters, $args, 'live');
    $total = (int) one_value($pdo, "SELECT count(*) FROM mcp_signals s WHERE $where", $args);
    $pages = max(1, (int) ceil($total / SIGNAL_PAGE_SIZE));
    $page = min(max(1, $page), $pages);
    $st = $pdo->prepare('SELECT ' . SIGNAL_LIST_COLUMNS . " FROM mcp_signals s JOIN mcp_signal_rules r ON r.rule_id = s.rule_id JOIN mcp_securities sec ON sec.security_id = s.security_id
                          WHERE $where ORDER BY CASE s.severity WHEN 'high' THEN 1 WHEN 'watch' THEN 2 ELSE 3 END, s.raised_on DESC, s.signal_id DESC
                          LIMIT " . SIGNAL_PAGE_SIZE . ' OFFSET ' . (($page - 1) * SIGNAL_PAGE_SIZE));
    $st->execute($args);
    $rows = $st->fetchAll();
    $st = $pdo->prepare("SELECT s.severity, count(*) AS n FROM mcp_signals s WHERE $where GROUP BY s.severity");
    $st->execute($args);
    $counts = ['high' => 0, 'watch' => 0, 'info' => 0];
    foreach ($st->fetchAll() as $c) { $counts[$c['severity']] = (int) $c['n']; }
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'counts' => $counts];
}

/** The window a signal's explaining event is chosen from: two weeks before the window to a week after it (or after the day it was raised). */
function signal_event_window(array $s): array
{
    $to = max((string) $s['window_to'], (string) $s['raised_on']);
    return [(new DateTimeImmutable((string) $s['window_from']))->modify('-14 days')->format('Y-m-d'), (new DateTimeImmutable($to))->modify('+7 days')->format('Y-m-d')];
}

/** The issuer's events in a signal's window, the explaining event's candidates (the picker's `event` source reads the same). */
function signal_events(PDO $pdo, array $s): array
{
    [$from, $to] = signal_event_window($s);
    $st = $pdo->prepare('SELECT event_id, on_date, kind, title, severity, filing_id, url FROM mcp_events WHERE issuer_id = :i AND (security_id IS NULL OR security_id = :s)
                          AND on_date BETWEEN CAST(:a AS date) AND CAST(:b AS date) ORDER BY on_date DESC, event_id DESC');
    $st->execute(['i' => (int) $s['issuer_id'], 's' => (int) $s['security_id'], 'a' => $from, 'b' => $to]);
    return $st->fetchAll();
}

/** One signal whole: the signal, its rule, the evidence, the events of its window, the outcome, the notes — or null (not there, or behind the wall). */
function find_signal(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT ' . SIGNAL_LIST_COLUMNS . ', sec.issuer_name, sec.cusip FROM mcp_signals s JOIN mcp_signal_rules r ON r.rule_id = s.rule_id JOIN mcp_securities sec ON sec.security_id = s.security_id WHERE s.signal_id = :id');
    $st->execute(['id' => $id]);
    $s = $st->fetch();
    if ($s === false) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM mcp_signal_rules WHERE rule_id = :r');
    $st->execute(['r' => (int) $s['rule_id']]);
    $rule = $st->fetch();
    $rule['effective_params'] = json_decode((string) one_value($pdo, 'SELECT si_rule_params(:k, :i)', ['k' => $rule['key'], 'i' => (int) $s['issuer_id']]), true) ?: [];
    $st = $pdo->prepare('SELECT * FROM mcp_signal_outcomes WHERE signal_id = :id');
    $st->execute(['id' => $id]);
    return ['signal' => $s, 'rule' => $rule, 'evidence' => json_decode((string) $s['evidence'], true) ?: [], 'events' => signal_events($pdo, $s),
            'outcome' => $st->fetch() ?: null, 'notes' => find_notes($pdo, 'signal', $id)];
}

/** Whether a person took the agent's proposal: 'waiting' (still open), 'accepted' (the disposition matches it), 'rejected', or null (none). */
function proposal_fate(array $s): ?string
{
    if ($s['proposal_reason'] === null) {
        return null;
    }
    if (in_array($s['status'], ['open', 'expired'], true)) {
        return $s['status'] === 'open' ? 'waiting' : 'lapsed';
    }
    $p = (string) $s['proposal_reason'];
    $taken = match ($s['status']) {
        'escalated' => $p === 'escalate',
        'acknowledged' => in_array($p, ['known', 'watching'], true) && $p === $s['disposition_reason'],
        'dismissed' => $p === $s['disposition_reason'] && ($p !== 'explained_by_event' || (int) $s['event_id'] === (int) $s['proposal_event_id']),
        default => false,
    };
    return $taken ? 'accepted' : 'rejected';
}

/** Every signal of a period (raised in it; the last 90 days by default) with its disposition, who and why: as find_signals, every status by default. */
function signals_history(PDO $pdo, int $issuer, array $filters, int $page): array
{
    $args = [];
    $where = signals_where($issuer, $filters, $args, 'all');
    $total = (int) one_value($pdo, "SELECT count(*) FROM mcp_signals s WHERE $where", $args);
    $pages = max(1, (int) ceil($total / SIGNAL_PAGE_SIZE));
    $page = min(max(1, $page), $pages);
    $st = $pdo->prepare('SELECT ' . SIGNAL_LIST_COLUMNS . " FROM mcp_signals s JOIN mcp_signal_rules r ON r.rule_id = s.rule_id JOIN mcp_securities sec ON sec.security_id = s.security_id
                          WHERE $where ORDER BY s.raised_on DESC, s.signal_id DESC LIMIT " . SIGNAL_PAGE_SIZE . ' OFFSET ' . (($page - 1) * SIGNAL_PAGE_SIZE));
    $st->execute($args);
    $rows = $st->fetchAll();
    $fates = ['accepted' => 0, 'rejected' => 0, 'waiting' => 0, 'lapsed' => 0];
    $st = $pdo->prepare("SELECT s.status, s.disposition_reason, s.proposal_reason, s.event_id, s.proposal_event_id FROM mcp_signals s WHERE $where AND s.proposal_reason IS NOT NULL");
    $st->execute($args);
    foreach ($st->fetchAll() as $r) { $fates[proposal_fate($r)]++; }
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'proposals' => $fates];
}

/** The outcomes by rule (the Auditor's test of the lore): n with a 20-day return, its mean, the share negative — every rule, the empty ones too. */
function outcomes_by_rule(PDO $pdo, ?int $issuer): array
{
    $st = $pdo->prepare("SELECT r.rule_id, r.key, r.name, r.evidence_class, count(s.signal_id) AS signals, count(o.return_20d) AS n,
                                round(avg(o.return_20d) * 100, 2) AS mean_return_20d_pct,
                                round(100.0 * count(*) FILTER (WHERE o.return_20d < 0) / NULLIF(count(o.return_20d), 0), 1) AS share_negative_20d_pct
                           FROM mcp_signal_rules r
                           LEFT JOIN mcp_signals s ON s.rule_id = r.rule_id AND (CAST(:i AS bigint) IS NULL OR s.issuer_id = CAST(:i2 AS bigint))
                           LEFT JOIN mcp_signal_outcomes o ON o.signal_id = s.signal_id
                          GROUP BY r.rule_id, r.key, r.name, r.evidence_class, r.sort_order ORDER BY r.sort_order");
    $st->execute(['i' => $issuer, 'i2' => $issuer]);
    return $st->fetchAll();
}

/** The fewest outcomes worth a figure: below it a rule's outcome is "too few to say" (signals.md §6). */
const OUTCOMES_ENOUGH = 30;
