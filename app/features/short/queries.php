<?php
declare(strict_types=1);

/**
 * The short side's reads (short-side.md "Query functions", signatures fixed): the SQL functions of db/011 and db/023 behind the issuer wall —
 * the caller has the security from positions_context(), which reads it through mcp_securities, so a security of an issuer the member cannot
 * see never reaches these. Every number keeps its date and its source; nothing interpolates.
 */

/** The short-interest series, a settlement date a row (its latest observation), with the earlier observation of a revised date. */
function short_interest(PDO $pdo, int $security, string $from, string $to): array
{
    $st = $pdo->prepare('SELECT s.*, l.published_on, l.change_shares, l.previous_short_shares, l.market, l.source_id, l.observed_at::date AS observed_on,
                                p.short_shares AS earlier_short_shares, p.observed_at AS earlier_observed_at, p.days_to_cover AS earlier_days_to_cover,
                                (SELECT count(*) FROM short_interest x WHERE x.security_id = :s1 AND x.settlement_date = s.settlement_date) AS observations
                           FROM si_short_interest_series(:s2, CAST(:a AS date), CAST(:b AS date)) s
                           JOIN LATERAL (SELECT * FROM short_interest i WHERE i.security_id = :s3 AND i.settlement_date = s.settlement_date ORDER BY i.observed_at DESC LIMIT 1) l ON true
                           LEFT JOIN LATERAL (SELECT * FROM short_interest i WHERE i.security_id = :s4 AND i.settlement_date = s.settlement_date AND i.observed_at < l.observed_at ORDER BY i.observed_at DESC LIMIT 1) p ON true
                          ORDER BY s.settlement_date DESC');
    $st->execute(['s1' => $security, 's2' => $security, 's3' => $security, 's4' => $security, 'a' => $from, 'b' => $to]);
    return $st->fetchAll();
}

/** The latest short interest at a date as a share of float and outstanding, with days to cover; null when FINRA has none yet. */
function short_vs_float(PDO $pdo, int $security, ?string $asOf): ?array
{
    $st = $pdo->prepare('SELECT * FROM si_short_vs_float(:s, CAST(:d AS date))');
    $st->execute(['s' => $security, 'd' => $asOf ?? date('Y-m-d')]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** FINRA's daily off-exchange short volume of one market with the 5- and 20-day means (computed over the 40 days before the window too). */
function short_volume(PDO $pdo, int $security, string $market, string $from, string $to): array
{
    $st = $pdo->prepare('SELECT * FROM si_daily_short_volume_series(:s, :m, CAST(:a AS date), CAST(:b AS date)) WHERE trade_date >= CAST(:a2 AS date) ORDER BY trade_date DESC');
    $st->execute(['s' => $security, 'm' => $market, 'a' => $from, 'b' => $to, 'a2' => $from]);
    return $st->fetchAll();
}

/** The fails by settlement date with the share of outstanding and the 60-day median. */
function fails(PDO $pdo, int $security, string $from, string $to): array
{
    $st = $pdo->prepare('SELECT * FROM si_fails_series(:s, CAST(:a AS date), CAST(:b AS date)) ORDER BY settlement_date DESC');
    $st->execute(['s' => $security, 'a' => $from, 'b' => $to]);
    return $st->fetchAll();
}

/** Every run on a threshold list (list, from, to, list days, current), the newest first, and the status now. */
function threshold_runs(PDO $pdo, int $security): array
{
    $st = $pdo->prepare('SELECT * FROM si_threshold_runs(:s)');
    $st->execute(['s' => $security]);
    $runs = $st->fetchAll();
    $st = $pdo->prepare('SELECT * FROM si_threshold_status(:s)');
    $st->execute(['s' => $security]);
    $days = $pdo->query('SELECT list, max(list_date) AS latest, count(*) AS days FROM threshold_list_days GROUP BY list ORDER BY list')->fetchAll();
    return ['runs' => $runs, 'status' => $st->fetch() ?: null, 'lists_read' => $days];
}

/** The borrow observations, newest first (every one typed by a person until a licensed feed). */
function borrow(PDO $pdo, int $security, ?string $from, ?string $to): array
{
    $st = $pdo->prepare('SELECT b.*, (SELECT display_name FROM mcp_members m WHERE m.member_id = b.recorded_by) AS recorded_by_name FROM mcp_borrow_observations b
                          WHERE b.security_id = :s AND (CAST(:a AS date) IS NULL OR b.observed_on >= CAST(:a2 AS date)) AND (CAST(:b AS date) IS NULL OR b.observed_on <= CAST(:b2 AS date))
                          ORDER BY b.observed_on DESC, b.recorded_at DESC');
    $st->execute(['s' => $security, 'a' => $from, 'a2' => $from, 'b' => $to, 'b2' => $to]);
    return $st->fetchAll();
}

/**
 * The one chart's data: the overlay a trading day a row (price, volume, short interest as a share of float on its settlement dates, the fails,
 * the 5-day off-exchange ratio, a threshold day, the borrow fee) and the issuer's events in the window.
 */
function overlay(PDO $pdo, int $security, string $from, string $to): array
{
    $st = $pdo->prepare('SELECT * FROM si_short_side_overlay(:s, CAST(:a AS date), CAST(:b AS date))');
    $st->execute(['s' => $security, 'a' => $from, 'b' => $to]);
    $points = $st->fetchAll();
    $st = $pdo->prepare('SELECT e.event_id, e.on_date, e.kind, e.title, e.severity FROM mcp_events e JOIN mcp_securities s ON s.issuer_id = e.issuer_id
                          WHERE s.security_id = :s AND (e.security_id IS NULL OR e.security_id = :s2) AND e.on_date BETWEEN CAST(:a AS date) AND CAST(:b AS date) ORDER BY e.on_date, e.event_id');
    $st->execute(['s' => $security, 's2' => $security, 'a' => $from, 'b' => $to]);
    return ['points' => $points, 'events' => $st->fetchAll()];
}

/** The newest date each short-side series has for a security, and its source's last good pull — the source lines under the chart. */
function short_sources(PDO $pdo, int $security): array
{
    $one = static function (string $sql) use ($pdo, $security) {
        $st = $pdo->prepare($sql);
        $st->execute(str_contains($sql, ':s') ? ['s' => $security] : []);
        return $st->fetchColumn() ?: null;
    };
    $pulled = [];
    foreach ($pdo->query("SELECT connector, last_ok_at, paused_at, paused_reason FROM sources WHERE connector IN ('finra_short_interest', 'finra_daily_short_volume', 'sec_ftd', 'threshold_nasdaq', 'threshold_nyse', 'threshold_otc', 'prices_stooq', 'prices_alphavantage', 'prices_polygon')")->fetchAll() as $r) {
        $pulled[$r['connector']] = $r;
    }
    return [
        'short_interest' => $one('SELECT max(settlement_date) FROM short_interest WHERE security_id = :s'),
        'short_volume' => $one('SELECT max(trade_date) FROM daily_short_volume WHERE security_id = :s'),
        'fails' => $one('SELECT max(settlement_date) FROM fails_to_deliver WHERE security_id = :s'),
        'threshold' => $one('SELECT max(list_date) FROM threshold_list_days'),
        'prices' => $one('SELECT max(trade_date) FROM prices WHERE security_id = :s'),
        'prices_source' => $one('SELECT s.name FROM prices p JOIN sources s ON s.id = p.source_id WHERE p.security_id = :s ORDER BY p.trade_date DESC LIMIT 1'),
        'borrow' => $one('SELECT max(observed_on) FROM borrow_observations WHERE security_id = :s'),
        'pulled' => $pulled,
    ];
}

/** The short-side window from ?from=&to= — the default the last $days days (90 at 375, the screen decides; a year at most per request is not imposed). */
function short_window(int $days = 180): array
{
    $to = request_date('to');
    $from = request_date('from');
    $to = is_string($to) ? $to : date('Y-m-d');
    $from = is_string($from) ? $from : (new DateTimeImmutable($to))->modify('-' . $days . ' days')->format('Y-m-d');
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    return [$from, $to];
}
