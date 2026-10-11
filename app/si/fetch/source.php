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
        'edgar_companyfacts' => [__DIR__ . '/edgar_companyfacts.php', 'si_fetch_edgar_companyfacts'],
        'edgar_fulltext' => [__DIR__ . '/edgar_fulltext.php', 'si_fetch_edgar_fulltext'],
        'edgar_13f_dataset' => [__DIR__ . '/edgar_13f_dataset.php', 'si_fetch_edgar_13f_dataset'],
        'edgar_13dg' => [__DIR__ . '/edgar_13dg.php', 'si_fetch_edgar_13dg'],
        'edgar_ownership' => [__DIR__ . '/edgar_ownership.php', 'si_fetch_edgar_ownership'],
        'edgar_144' => [__DIR__ . '/edgar_144.php', 'si_fetch_edgar_144'],
        'finra_short_interest' => [__DIR__ . '/finra_short_interest.php', 'si_fetch_finra_short_interest'],
        'finra_daily_short_volume' => [__DIR__ . '/finra_daily_short_volume.php', 'si_fetch_finra_daily_short_volume'],
        'sec_ftd' => [__DIR__ . '/sec_ftd.php', 'si_fetch_sec_ftd'],
        'threshold_nasdaq' => [__DIR__ . '/threshold.php', 'si_fetch_threshold_nasdaq'],
        'threshold_nyse' => [__DIR__ . '/threshold.php', 'si_fetch_threshold_nyse'],
        'threshold_otc' => [__DIR__ . '/threshold.php', 'si_fetch_threshold_otc'],
        'prices_stooq' => [__DIR__ . '/prices.php', 'si_fetch_prices_stooq'],
        'prices_alphavantage' => [__DIR__ . '/prices.php', 'si_fetch_prices_alphavantage'],
        'prices_polygon' => [__DIR__ . '/prices.php', 'si_fetch_prices_polygon'],
    ];
}

/**
 * The securities a short-side or price source serves (slice 6): the active securities of the issuers the application tracks — the source's
 * `settings.issuers` when set, or the one issuer of a manual pull — with the field the connector matches on (`ticker` or `cusip`) present.
 * A restricted issuer is fetched like any other (the wall is on reading). Answers [{security_id, issuer_id, ticker, cusip, rows}], `rows`
 * the count of the connector's table for the security (0 = never loaded: the connector back-fills it).
 */
function si_tracked_securities(PDO $pdo, array $source, array $ctx, string $field, ?string $table = null): array
{
    $settings = json_decode((string) $source['settings'], true) ?: [];
    $only = array_map('intval', (array) ($settings['issuers'] ?? []));
    $field = $field === 'cusip' ? 'cusip' : 'ticker';
    $count = $table === null ? '0' : '(SELECT count(*) FROM ' . preg_replace('~[^a-z_]~', '', $table) . ' t WHERE t.security_id = s.id)';
    $sql = "SELECT s.id AS security_id, s.issuer_id, s.ticker, s.cusip, $count AS rows FROM securities s JOIN issuers i ON i.id = s.issuer_id
             WHERE i.deleted_at IS NULL AND s.active AND s.$field IS NOT NULL";
    $args = [];
    if (($ctx['issuer'] ?? null) !== null) {
        $sql .= ' AND i.id = :one';
        $args['one'] = (int) $ctx['issuer'];
    } elseif ($only !== []) {
        $sql .= ' AND i.id = ANY (CAST(:only AS bigint[]))';
        $args['only'] = '{' . implode(',', $only) . '}';
    }
    $st = $pdo->prepare($sql . ' ORDER BY s.id');
    $st->execute($args);
    return array_map(static fn (array $r): array => ['security_id' => (int) $r['security_id'], 'issuer_id' => (int) $r['issuer_id'], 'ticker' => $r['ticker'], 'cusip' => $r['cusip'], 'rows' => (int) $r['rows']], $st->fetchAll());
}

/** The market days from $from to $to inclusive, oldest first (si_is_market_day: weekdays not in the exchange's holidays). */
function si_market_days(PDO $pdo, string $from, string $to): array
{
    $st = $pdo->prepare("SELECT d::date FROM generate_series(CAST(:a AS date), CAST(:b AS date), interval '1 day') d WHERE si_is_market_day(d::date) ORDER BY 1");
    $st->execute(['a' => $from, 'b' => $to]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/** A source's settings with keys merged in (the connectors' bookkeeping: the files read, the securities back-filled). */
function si_source_settings_merge(PDO $pdo, int $source, array $merge): void
{
    $pdo->prepare('UPDATE sources SET settings = settings || CAST(:m AS jsonb) WHERE id = :id')->execute(['m' => json_encode($merge), 'id' => $source]);
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
 * The user-agent a source must send: an EDGAR or SEC connector (`edgar_*`, `sec_ftd`) the SEC's "<business name> <contact email>"
 * (si_edgar_user_agent()) — NULL when the settings lack it, and then nothing is fetched; any other the source's own, else "<business> Shareholder Intelligence (+contact)".
 */
function si_fetch_user_agent(PDO $pdo, array $source): ?string
{
    $edgar = (string) one_value($pdo, 'SELECT si_edgar_user_agent()');
    if (str_starts_with((string) $source['connector'], 'edgar_') || str_starts_with((string) $source['connector'], 'sec_')) {
        return $edgar !== '' ? $edgar : null;                           // the SEC's fair-access rule covers www.sec.gov too (the fails)
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
            if (one_value($pdo, "SELECT 1 FROM notifications WHERE member_id = :m AND kind = 'source_failed' AND title = 'EDGAR is waiting for the contact email' AND created_at >= date_trunc('day', now())", ['m' => $m])) {
                continue;                                              // once a day, whichever EDGAR source asked first
            }
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
        $r = $fn($pdo, $source, $client, ['pull' => $pull, 'kind' => $kind, 'by' => $by, 'issuer' => $o['issuer'] ?? null, 'now' => $o['now'] ?? new DateTimeImmutable('now'), 'listing_only' => $o['listing_only'] ?? false]) + $r;
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
