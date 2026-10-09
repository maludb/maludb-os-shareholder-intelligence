<?php
declare(strict_types=1);

/**
 * A source's pull, from start to finish (docs/build-specs/edgar-and-finra-fetchers.md §1–§3): the user-agent the policy demands, the client,
 * the source_pulls row, the connector, si_pull_finish() (the back-off ladder lives in SQL), the log line, and the admin's notice when a host
 * refuses us or a source fails four times running. Every connector is a function registered in si_fetchers():
 *     fetch_x(PDO $pdo, array $source, SiHttp $client, array $context): array{seen?, new?, changed?, status?, error?, detail?}
 * $context: pull (id), kind (scheduled | manual), by (member or null), issuer (an issuer id to restrict to, or null), now (DateTimeImmutable).
 * A connector throws on a failure it cannot name; the words of a SiFetchError reach the pull's `error` (200 characters, never a secret).
 * Slices add their connectors to si_fetchers(): slice 1 the directory and the issuers' EDGAR entity; slice 4 the filing index, the
 * companyfacts counts, the full-text watch and the text; slice 5 the ownership forms; slice 6 the short side and prices.
 */

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/calendar.php';

/** A failure the connector can name in words (the pull's error). */
final class SiFetchError extends RuntimeException {}

/** The connectors this build runs, connector => [file, function]. The worker skips a due source whose connector is not here yet. */
function si_fetchers(): array
{
    return [
        'dtcc_participants' => [__DIR__ . '/dtcc_participants.php', 'si_fetch_dtcc_participants'],
        'edgar_submissions' => [__DIR__ . '/edgar_submissions.php', 'si_fetch_edgar_submissions'],
    ];
}

function si_source(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM sources WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** The source row of a connector (the first active one). */
function si_source_by_connector(PDO $pdo, string $connector): ?array
{
    $st = $pdo->prepare('SELECT * FROM sources WHERE connector = :c ORDER BY active DESC, id LIMIT 1');
    $st->execute(['c' => $connector]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/**
 * The user-agent a source must send: an EDGAR connector the SEC's "<business name> <contact email>" (si_edgar_user_agent()) — NULL when the
 * settings lack it, and then nothing is fetched; any other the source's own, else "<business> Shareholder Intelligence (+contact)".
 */
function si_fetch_user_agent(PDO $pdo, array $source): ?string
{
    $edgar = (string) one_value($pdo, 'SELECT si_edgar_user_agent()');
    if (str_starts_with((string) $source['connector'], 'edgar_')) {
        return $edgar !== '' ? $edgar : null;
    }
    if (trim((string) ($source['user_agent'] ?? '')) !== '') {
        return trim((string) $source['user_agent']);
    }
    $s = $pdo->query('SELECT business_name, edgar_contact_email FROM si_settings WHERE id = 1')->fetch() ?: [];
    $name = trim((string) ($s['business_name'] ?? '')) ?: 'A business';
    $contact = trim((string) ($s['edgar_contact_email'] ?? ''));
    return $name . ' Shareholder Intelligence' . ($contact !== '' ? ' (+' . $contact . ')' : '');
}

/** The members told when a source is refused or keeps failing: people holding sources.manage. */
function si_source_keepers(PDO $pdo): array
{
    return array_map('intval', $pdo->query("SELECT m.id FROM members m WHERE m.member_kind = 'human' AND m.status = 'active' AND m.capability IS NOT NULL
        AND EXISTS (SELECT 1 FROM si_role_rights rr WHERE rr.right_key = 'sources.manage' AND rr.role_key = ANY (si_member_roles(m.id))) ORDER BY m.id")->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Run one source now. $o: kind (scheduled | manual), by (member id), issuer (restrict to one issuer), client (an SiHttp, tests), now.
 * Answers ['status' => ok|partial|failed|blocked|skipped, 'pull_id', 'seen', 'new', 'changed', 'error', 'detail'].
 */
function si_run_source(PDO $pdo, array $source, array $o = []): array
{
    $fetchers = si_fetchers();
    $connector = (string) $source['connector'];
    if (!isset($fetchers[$connector])) {
        return ['status' => 'skipped', 'pull_id' => null, 'seen' => 0, 'new' => 0, 'changed' => 0, 'error' => 'This source is read by a later slice.', 'detail' => []];
    }
    $kind = ($o['kind'] ?? 'scheduled') === 'manual' ? 'manual' : 'scheduled';
    $by = $o['by'] ?? null;
    $st = $pdo->prepare('INSERT INTO source_pulls (source_id, kind, started_by) VALUES (:s, :k, :b) RETURNING id');
    $st->execute(['s' => (int) $source['id'], 'k' => $kind, 'b' => $by]);
    $pull = (int) $st->fetchColumn();
    log_activity($pdo, 'source.pull_start', 'source', (int) $source['id'], ['actor_member_id' => $by, 'after' => ['pull_id' => $pull, 'connector' => $connector, 'kind' => $kind]]);
    $ua = si_fetch_user_agent($pdo, $source);
    $policy = ['user_agent' => $ua, 'rate_per_second' => (float) $source['rate_per_second']];
    if ($ua === null) {
        $error = 'EDGAR needs the business name and a contact email in Settings (the SEC\'s rule).';
        $pdo->prepare('SELECT si_pull_finish(:p, :st, :e, 0, 0, 0, 0, 0, CAST(:pol AS jsonb))')->execute(['p' => $pull, 'st' => 'failed', 'e' => $error, 'pol' => json_encode($policy + ['refused_before_request' => true])]);
        log_activity($pdo, 'source.pull_fail', 'source', (int) $source['id'], ['actor_member_id' => $by, 'after' => ['pull_id' => $pull, 'error' => $error, 'requests' => 0]]);
        foreach (si_source_keepers($pdo) as $m) {
            $pdo->prepare('SELECT si_notify(:m, :k, :t, :b, :rt, :rid, NULL, NULL, NULL, :d)')->execute(['m' => $m, 'k' => 'source_failed', 't' => 'EDGAR is waiting for the contact email',
                'b' => $error, 'rt' => 'source', 'rid' => (int) $source['id'], 'd' => 'ua-missing:' . date('Y-m-d') . ':' . $m]);
        }
        return ['status' => 'failed', 'pull_id' => $pull, 'seen' => 0, 'new' => 0, 'changed' => 0, 'error' => $error, 'detail' => []];
    }
    $client = $o['client'] ?? si_http_client(['user_agent' => $ua, 'rate_per_second' => (float) $source['rate_per_second']]);
    [$file, $fn] = $fetchers[$connector];
    require_once $file;
    $status = 'ok';
    $error = null;
    $r = ['seen' => 0, 'new' => 0, 'changed' => 0, 'detail' => []];
    try {
        $r = $fn($pdo, $source, $client, ['pull' => $pull, 'kind' => $kind, 'by' => $by, 'issuer' => $o['issuer'] ?? null, 'now' => $o['now'] ?? new DateTimeImmutable('now')]) + $r;
        $status = (string) ($r['status'] ?? 'ok');
        $error = $r['error'] ?? null;
    } catch (SiFetchError $e) {
        $status = 'failed';
        $error = $e->getMessage();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('fetch ' . $connector . ': ' . $e->getMessage());
        $status = 'failed';
        $error = 'The pull stopped on an unexpected error; the server log has it.';
    }
    $facts = $client->facts();
    if ($facts['blocked'] > 0 && $status !== 'ok') {
        $status = 'blocked';
    }
    $policy += ['robots' => $facts['robots'], 'cached' => $facts['cached'], 'blocked' => $facts['blocked'], 'robots_skipped' => $facts['robots_skipped'],
                'requests' => array_slice($facts['requests'], -20)];
    $pdo->prepare('SELECT si_pull_finish(:p, :st, :e, :seen, :new, :ch, :req, :bytes, CAST(:pol AS jsonb))')->execute([
        'p' => $pull, 'st' => $status, 'e' => $error === null ? null : mb_substr((string) $error, 0, 200), 'seen' => (int) $r['seen'], 'new' => (int) $r['new'], 'ch' => (int) $r['changed'],
        'req' => (int) $facts['http_requests'], 'bytes' => (int) $facts['bytes'], 'pol' => json_encode($policy)]);
    $event = match ($status) { 'ok', 'partial' => 'source.pull_done', 'blocked' => 'source.pull_blocked', default => 'source.pull_fail' };
    log_activity($pdo, $event, 'source', (int) $source['id'], ['actor_member_id' => $by, 'after' => ['pull_id' => $pull, 'connector' => $connector, 'seen' => (int) $r['seen'], 'new' => (int) $r['new'],
        'changed' => (int) $r['changed'], 'requests' => (int) $facts['http_requests'], 'bytes' => (int) $facts['bytes'], 'error' => $error === null ? null : mb_substr((string) $error, 0, 200)]]);
    $fails = (int) one_value($pdo, 'SELECT consecutive_failures FROM sources WHERE id = :s', ['s' => (int) $source['id']]);
    if ($status === 'blocked' || ($status === 'failed' && $fails >= 4)) {
        foreach (si_source_keepers($pdo) as $m) {
            $pdo->prepare('SELECT si_notify(:m, :k, :t, :b, :rt, :rid, NULL, NULL, NULL, :d)')->execute(['m' => $m, 'k' => $status === 'blocked' ? 'source_blocked' : 'source_failed',
                't' => ($status === 'blocked' ? 'Refused by ' : 'Failing: ') . $source['name'], 'b' => (string) $error, 'rt' => 'source', 'rid' => (int) $source['id'],
                'd' => $status . ':' . $source['id'] . ':' . date('Y-m-d') . ':' . $m]);
        }
    }
    return ['status' => $status, 'pull_id' => $pull, 'seen' => (int) $r['seen'], 'new' => (int) $r['new'], 'changed' => (int) $r['changed'], 'error' => $error, 'detail' => $r['detail'] ?? []];
}

/** The worker's fetch pass: every due source whose connector this build has, on its calendar. Answers one line per source run. */
function si_fetch_pass(PDO $pdo, ?DateTimeImmutable $now = null): array
{
    $now ??= new DateTimeImmutable('now');
    $out = [];
    foreach ($pdo->query('SELECT * FROM si_sources_due()')->fetchAll() as $s) {
        if (!isset(si_fetchers()[$s['connector']]) || !si_source_on_calendar($pdo, $s, $now)) {
            continue;
        }
        $r = si_run_source($pdo, $s, ['kind' => 'scheduled', 'now' => $now]);
        $out[] = $s['connector'] . ': ' . $r['status'] . ' (' . $r['seen'] . ' seen, ' . $r['new'] . ' new, ' . $r['changed'] . ' changed)' . ($r['error'] ? ' — ' . $r['error'] : '');
    }
    return $out;
}

/** An issuer's sources and their state, for the issuer's Sources tab: the EDGAR sources (they serve every issuer unless restricted). */
function si_issuer_sources(PDO $pdo, int $issuer): array
{
    $rows = $pdo->query("SELECT * FROM si_source_health() WHERE connector LIKE 'edgar_%' OR connector IN ('finra_short_interest', 'finra_daily_short_volume', 'sec_ftd', 'prices_stooq') ORDER BY source_id")->fetchAll();
    $served = [];
    foreach ($rows as $r) {
        $set = json_decode((string) one_value($pdo, 'SELECT settings FROM sources WHERE id = :id', ['id' => (int) $r['source_id']]), true) ?: [];
        $only = $set['issuers'] ?? null;
        if (!is_array($only) || $only === [] || in_array($issuer, array_map('intval', $only), true)) {
            $served[] = $r;
        }
    }
    return $served;
}
