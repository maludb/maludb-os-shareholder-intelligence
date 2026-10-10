<?php
declare(strict_types=1);

/**
 * Filing writes (slice 4): the text fetched under the EDGAR source's policy, an event made from a filing, a watch hit confirmed into an
 * instrument (a person's) or dismissed, a pull now. Logged with ids, counts and the term — never a filing's text.
 */

require_once __DIR__ . '/queries.php';
require_once dirname(__DIR__, 2) . '/si/fetch/source.php';
require_once dirname(__DIR__, 2) . '/si/fetch/filing_text.php';
require_once dirname(__DIR__) . '/dilution/write.php';

/** The EDGAR source's client (the user-agent, the rate) — null when the business name or the EDGAR contact is missing. */
function edgar_client(PDO $pdo): ?SiHttp
{
    $src = si_source_by_connector($pdo, 'edgar_submissions');
    if ($src === null) {
        return null;
    }
    $ua = si_fetch_user_agent($pdo, $src);
    return $ua === null ? null : si_http_client(['user_agent' => $ua, 'rate_per_second' => (float) $src['rate_per_second']]);
}

/** Fetch (or fetch again) a filing's primary document as text. */
function filing_fetch_text(PDO $pdo, int $filing): array
{
    $client = edgar_client($pdo) ?? throw new DomainException('EDGAR needs the business name and a contact email in Settings (the SEC\'s rule).');
    $r = si_filing_text_fetch($pdo, $client, $filing);
    if (!$r['ok']) {
        throw new DomainException((string) $r['error']);
    }
    $iss = (int) one_value($pdo, 'SELECT issuer_id FROM filings WHERE id = :i', ['i' => $filing]);
    log_activity($pdo, 'filing.fetch_text', 'filing', $filing, ['issuer_id' => $iss, 'after' => ['filing_id' => $filing, 'chars' => (int) $r['chars'], 'extractor' => $r['kind'] ?? null]]);
    return $r;
}

/** An event made from a filing (one per filing of kind `filing`). Answers the event. */
function filing_event_make(PDO $pdo, int $filing, array $f, int $by): int
{
    $fi = find_filing($pdo, $filing) ?? throw new DomainException('Not found.');
    $existing = one_value($pdo, "SELECT event_id FROM mcp_events WHERE filing_id = :f AND kind IN ('filing', 'insider_transaction', 'exchange_notice') ORDER BY event_id LIMIT 1", ['f' => $filing]);
    if ($existing !== null) {
        throw new DomainException('This filing already has its event (event ' . $existing . ').|' . $existing);
    }
    $agent = (current_member()['member_kind'] ?? '') === 'agent';
    $st = $pdo->prepare("INSERT INTO events (issuer_id, on_date, kind, title, body, url, filing_id, severity, source, created_by) VALUES (:i, :d, 'filing', :t, :b, :u, :f, :s, :src, :by) RETURNING id");
    $st->execute(['i' => (int) $fi['issuer_id'], 'd' => $fi['filed_on'], 't' => $f['title'], 'b' => $f['body'], 'u' => $fi['url'], 'f' => $filing, 's' => $f['severity'], 'src' => $agent ? 'agent' : 'person', 'by' => $by]);
    $id = (int) $st->fetchColumn();
    log_activity($pdo, 'filing.event_make', 'event', $id, ['issuer_id' => (int) $fi['issuer_id'], 'after' => ['filing_id' => $filing, 'event_id' => $id, 'severity' => $f['severity']]]);
    return $id;
}

/** A watch hit confirmed into an instrument (the proposed one updated, or one made): a person's. Answers the instrument. */
function watch_hit_confirm(PDO $pdo, int $hit, array $f, int $by): int
{
    $w = find_watch_hit($pdo, $hit) ?? throw new DomainException('Not found.');
    if ($w['status'] !== 'new') {
        throw new DomainException('This hit was already ' . $w['status'] . '.');
    }
    $prop = one_value($pdo, "SELECT id FROM dilution_instruments WHERE issuer_id = :i AND filing_id = :f AND status = 'proposed' AND (watch_term = :t OR kind = :k) ORDER BY id LIMIT 1",
        ['i' => (int) $w['issuer_id'], 'f' => (int) $w['filing_id'], 't' => $w['term'], 'k' => $f['kind']]);
    $fields = $f + ['status' => 'active', 'filing' => (int) $w['filing_id'], 'filed_on' => $w['filed_on'], 'issuer' => (int) $w['issuer_id']];
    $id = dilution_store($pdo, $prop === null ? null : (int) $prop, $fields, 'watch', $w['term']);
    $pdo->prepare("UPDATE dilution_instruments SET confirmed_by = :by, confirmed_at = now() WHERE id = :d")->execute(['by' => $by, 'd' => $id]);
    $pdo->prepare("UPDATE watch_hits SET status = 'confirmed', decided_by = :by, decided_at = now() WHERE id = :h")->execute(['by' => $by, 'h' => $hit]);
    log_activity($pdo, 'watch.confirm', 'watch_hit', $hit, ['issuer_id' => (int) $w['issuer_id'], 'after' => ['term' => $w['term'], 'filing_id' => (int) $w['filing_id'], 'instrument_id' => $id, 'kind' => $f['kind']]]);
    return $id;
}

/** A watch hit dismissed; the instrument it proposed dismissed with it. */
function watch_hit_dismiss(PDO $pdo, int $hit, int $by): void
{
    $w = find_watch_hit($pdo, $hit) ?? throw new DomainException('Not found.');
    if ($w['status'] !== 'new') {
        throw new DomainException('This hit was already ' . $w['status'] . '.');
    }
    $pdo->prepare("UPDATE watch_hits SET status = 'dismissed', decided_by = :by, decided_at = now() WHERE id = :h")->execute(['by' => $by, 'h' => $hit]);
    $pdo->prepare("UPDATE dilution_instruments SET status = 'dismissed' WHERE issuer_id = :i AND filing_id = :f AND watch_term = :t AND status = 'proposed'")
        ->execute(['i' => (int) $w['issuer_id'], 'f' => (int) $w['filing_id'], 't' => $w['term']]);
    log_activity($pdo, 'watch.dismiss', 'watch_hit', $hit, ['issuer_id' => (int) $w['issuer_id'], 'after' => ['term' => $w['term'], 'filing_id' => (int) $w['filing_id']]]);
}

/**
 * A pull now (fetch_now) within the source's policy: a paused source is not pulled, a blocked or backed-off one answers when it may be
 * again; `issuer` narrows an EDGAR pull to one issuer. Answers si_run_source's result.
 */
function fetch_now(PDO $pdo, int $source, ?int $issuer): array
{
    $s = si_source($pdo, $source) ?? throw new DomainException('Not found.');
    if ($s['paused_at'] !== null) {
        throw new DomainException('This source is paused: an admin resumes it first.');
    }
    if ($s['backoff_until'] !== null && strtotime((string) $s['backoff_until']) > time()) {
        throw new DomainException('This source is backed off until ' . format_ts((string) $s['backoff_until'], member_timezone(), 'M j, g:i A') . ' (its last pull was refused or failed): it is not pulled before then.');
    }
    if (!isset(si_fetchers()[$s['connector']])) {
        throw new DomainException('This source is read by a later slice.');
    }
    log_activity($pdo, 'source.pull_now', 'source', $source, ['issuer_id' => $issuer, 'after' => ['source_id' => $source, 'connector' => $s['connector'], 'issuer_id' => $issuer]]);
    return si_run_source($pdo, $s, ['kind' => 'manual', 'by' => current_member_id(), 'issuer' => $issuer]);
}
