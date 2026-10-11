<?php
declare(strict_types=1);

/**
 * The proposals' reads (agents-k7-api.md "Query functions", signature fixed): everything an agent proposed and nobody has taken, across the issuers the caller may see — a signal's disposition (the
 * rule, the reason, the explaining event), a holder match (the row beside its candidate, the evidence in words) and a holder's kind. si_analyst_proposals() (db/018) is the one read: the issuer wall is
 * its own, and the holder rows answer only the PII roles (a Viewer is left with the signals). The enrichment reads through the mcp_* views, so a row the views will not show is not shown.
 */
require_once dirname(__DIR__) . '/signals/write.php';
require_once dirname(__DIR__) . '/signals/present.php';
require_once dirname(__DIR__) . '/holders/queries.php';

const PROPOSAL_KINDS = ['signal' => 'Signal dispositions', 'holder_match' => 'Holder matches', 'holder_kind' => 'Holder kinds'];
const PROPOSAL_ICONS = ['signal' => 'bi-flag', 'holder_match' => 'bi-people', 'holder_kind' => 'bi-tag'];
const PROPOSAL_LIMIT = 100;

/**
 * The open proposals, newest first, for an issuer (null = every issuer the caller may see) and a kind (signal, holder_match, holder_kind; null = all). Each row: kind, proposal_id, issuer_id, issuer_name,
 * title, reason, note, proposed_by, proposed_by_name (null = the worker), proposed_at and `detail` — the kind's own facts (the signal's rule and window; the row and the candidate; the holder).
 */
function proposals(PDO $pdo, ?int $issuer, ?string $kind): array
{
    $kind = $kind !== null && isset(PROPOSAL_KINDS[$kind]) ? $kind : null;
    $st = $pdo->prepare('SELECT * FROM si_analyst_proposals(CAST(:i AS bigint), CAST(:k AS text))');
    $st->execute(['i' => $issuer, 'k' => $kind]);
    $rows = array_slice($st->fetchAll(), 0, PROPOSAL_LIMIT);
    $who = $pdo->prepare('SELECT display_name FROM members WHERE id = :m');
    $iss = array_column(my_issuers($pdo), 'name', 'issuer_id');
    foreach ($rows as &$r) {
        $r['issuer_name'] = $iss[(int) $r['issuer_id']] ?? null;
        $r['proposed_by_name'] = null;
        if ($r['proposed_by'] !== null) {
            $who->execute(['m' => (int) $r['proposed_by']]);
            $r['proposed_by_name'] = $who->fetchColumn() ?: null;
        }
        $r['detail'] = match ($r['kind']) {
            'signal' => proposal_signal($pdo, (int) $r['proposal_id']),
            'holder_match' => proposal_match($pdo, (int) $r['proposal_id']),
            default => proposal_kind($pdo, (int) $r['proposal_id']),
        };
    }
    unset($r);
    return $rows;
}

/** The facts of a signal proposal: the signal, its rule and class, the security, the proposal's reason and the explaining event, and what "Take it" posts. */
function proposal_signal(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT s.signal_id, s.rule_key, s.rule_name, s.evidence_class, s.severity, s.raised_on, s.window_from, s.window_to, s.summary, s.status, s.proposal_reason, s.proposal_note, s.proposal_event_id,
                                (SELECT title FROM mcp_events e WHERE e.event_id = s.proposal_event_id) AS event_title, (SELECT on_date FROM mcp_events e WHERE e.event_id = s.proposal_event_id) AS event_on,
                                sec.name AS security_name, sec.ticker
                           FROM mcp_signals s JOIN mcp_securities sec ON sec.security_id = s.security_id WHERE s.signal_id = :id');
    $st->execute(['id' => $id]);
    $s = $st->fetch() ?: [];
    if ($s !== []) {
        $s['action'] = SIGNAL_PROPOSAL_REASONS[$s['proposal_reason']] ?? 'dismiss';      // the disposition "Take it" posts: acknowledge, dismiss or escalate
    }
    return $s;
}

/** The facts of a match proposal: the raw row (a NOBO or register row — PII roles only, by the view), the candidate holder, the score and the evidence in words. */
function proposal_match(PDO $pdo, int $id): array
{
    $st = $pdo->prepare("SELECT p.proposal_id, p.row_kind, p.row_id, p.holder_id, p.score, p.evidence, h.display_name, h.kind AS holder_kind, h.address_line, h.city, h.state, h.zip, h.accounts_count, h.last_seen_on, h.last_shares
                           FROM mcp_holder_match_proposals p JOIN mcp_holders h ON h.holder_id = p.holder_id WHERE p.proposal_id = :id");
    $st->execute(['id' => $id]);
    $p = $st->fetch();
    if ($p === false) {
        return [];
    }
    if ($p['row_kind'] === 'nobo') {
        $q = $pdo->prepare("SELECT n.shares, concat_ws(E'\\n', n.line1, n.line2, n.line3, n.line4, n.line5, n.line6, n.line7) AS registration, NULL::text AS address, j.record_date
                              FROM mcp_nobo_rows n JOIN mcp_nobo_jobs j ON j.nobo_job_id = n.nobo_job_id WHERE n.nobo_row_id = :r");
    } else {
        $q = $pdo->prepare("SELECT g.shares, g.registration_name AS registration, concat_ws(', ', g.address_line, concat_ws(' ', g.city, g.state, g.zip)) AS address, g.record_date FROM mcp_register_rows g WHERE g.register_row_id = :r");
    }
    $q->execute(['r' => (int) $p['row_id']]);
    $p['row'] = $q->fetch() ?: null;
    $p['evidence_words'] = match_evidence_words(json_decode((string) $p['evidence'], true) ?: [], (float) $p['score']);
    return $p;
}

/** The facts of a kind proposal: the holder, the kind and entity type proposed, the evidence sentence. */
function proposal_kind(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT k.proposal_id, k.holder_id, k.kind, k.entity_type, k.evidence, h.display_name, h.kind AS holder_kind, h.accounts_count FROM mcp_holder_kind_proposals k JOIN mcp_holders h ON h.holder_id = k.holder_id WHERE k.proposal_id = :id');
    $st->execute(['id' => $id]);
    return $st->fetch() ?: [];
}
