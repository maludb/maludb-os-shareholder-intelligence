<?php
declare(strict_types=1);

/**
 * The price connectors (edgar-and-finra-fetchers.md §2; D13): one daily series per security, upserted into `prices` by (security, date) —
 * a changed bar is rewritten, the same one changes nothing; `price.load {days}` per security with rows written.
 *   - `prices_stooq`: <base_url>?s=<ticker><suffix>&i=d, the full daily CSV "Date,Open,High,Low,Close,Volume". **Found 2026-10-10: stooq's
 *     robots.txt disallows every agent but Google's and Bing's and its pages answer a JavaScript proof-of-work** — the client never asks a
 *     disallowed path and reads a proof-of-work page as a bot wall, so the pull answers blocked and nothing is read; db/023 pauses the source
 *     with that reason. The connector stays for a host that opens again.
 *   - `prices_alphavantage`: TIME_SERIES_DAILY (`outputsize=full` for a security never loaded, `compact` — 100 days — after; a key whose plan
 *     refuses `full` is asked `compact`), the key from the source's credential (credentials.php), never in a log or an error.
 *   - `prices_polygon`: /v2/aggs/ticker/<T>/range/1/day/<from>/<to> unadjusted, the key as a bearer header; five years for a security never
 *     loaded, else from ten days before its last price.
 * A price upload (`prices_csv`) is the fourth door: the upload pipeline (app/si/parse/prices_csv.php), the source `prices_manual`.
 */

require_once __DIR__ . '/credentials.php';

function si_fetch_prices_stooq(PDO $pdo, array $source, SiHttp $client, array $ctx): array
{
    $settings = json_decode((string) $source['settings'], true) ?: [];
    $base = (string) ($source['base_url'] ?: 'https://stooq.com/q/d/l/');
    $suffix = (string) ($settings['suffix'] ?? '.us');
    return si_prices_each($pdo, $source, $ctx, static function (array $s) use ($client, $base, $suffix): array {
        $r = $client->get($base . '?s=' . rawurlencode(strtolower((string) $s['ticker']) . $suffix) . '&i=d', ['accept' => 'text/csv', 'no_cache' => true]);
        if ($r['blocked']) {
            throw new SiPricesBlocked('stooq refused us (' . ($r['reason'] ?? $r['status']) . ') — a bot wall: prices come from a keyed provider or an upload.');
        }
        if ($r['skipped']) {
            throw new SiPricesBlocked('stooq\'s robots.txt disallows the price path for our user-agent: prices come from a keyed provider or an upload.');
        }
        if (!$r['ok']) {
            throw new SiFetchError('stooq answered ' . $r['status'] . ' for ' . $s['ticker'] . '.');
        }
        return si_prices_from_csv($r['body']) ?? throw new SiFetchError('stooq\'s answer for ' . $s['ticker'] . ' is not its price CSV' . (stripos($r['body'], 'no data') !== false ? ' (no data for the symbol)' : '') . '.');
    });
}

function si_fetch_prices_alphavantage(PDO $pdo, array $source, SiHttp $client, array $ctx): array
{
    $key = si_prices_key($pdo, $source, 'Alpha Vantage');
    $base = (string) ($source['base_url'] ?: 'https://www.alphavantage.co/query');
    return si_prices_each($pdo, $source, $ctx, static function (array $s) use ($client, $base, $key): array {
        foreach ($s['rows'] === 0 ? ['full', 'compact'] : ['compact'] as $size) {
            $r = $client->get($base . '?function=TIME_SERIES_DAILY&symbol=' . rawurlencode((string) $s['ticker']) . '&outputsize=' . $size . '&datatype=json&apikey=' . rawurlencode($key), ['accept' => 'application/json', 'no_cache' => true]);
            if ($r['blocked']) {
                throw new SiPricesBlocked('Alpha Vantage refused us (' . $r['status'] . ').');
            }
            if (!$r['ok']) {
                throw new SiFetchError('Alpha Vantage answered ' . $r['status'] . ' for ' . $s['ticker'] . '.');
            }
            $doc = json_decode($r['body'], true) ?: [];
            if (isset($doc['Time Series (Daily)']) && is_array($doc['Time Series (Daily)'])) {
                $out = [];
                foreach ($doc['Time Series (Daily)'] as $d => $bar) {
                    if (preg_match('~^\d{4}-\d{2}-\d{2}$~', (string) $d) && is_numeric($bar['4. close'] ?? null)) {
                        $out[] = [$d, $bar['1. open'] ?? null, $bar['2. high'] ?? null, $bar['3. low'] ?? null, $bar['4. close'], $bar['5. volume'] ?? null];
                    }
                }
                return $out;
            }
            $words = (string) ($doc['Error Message'] ?? $doc['Note'] ?? $doc['Information'] ?? 'an answer without a series');
            if ($size === 'full' && stripos($words, 'premium') !== false) {
                continue;                                                   // the free plan answers compact only
            }
            if (isset($doc['Note']) || stripos($words, 'rate limit') !== false || stripos($words, 'requests per') !== false) {
                throw new SiPricesBlocked('Alpha Vantage\'s limit: ' . mb_substr($words, 0, 120));
            }
            throw new SiFetchError('Alpha Vantage for ' . $s['ticker'] . ': ' . mb_substr(str_replace($key, '…', $words), 0, 140));
        }
        return [];
    });
}

function si_fetch_prices_polygon(PDO $pdo, array $source, SiHttp $client, array $ctx): array
{
    $key = si_prices_key($pdo, $source, 'Polygon');
    $base = rtrim((string) ($source['base_url'] ?: 'https://api.polygon.io/v2/aggs/ticker/'), '/') . '/';
    $now = ($ctx['now'] ?? new DateTimeImmutable('now'))->setTimezone(si_market_tz($pdo));
    return si_prices_each($pdo, $source, $ctx, static function (array $s) use ($pdo, $client, $base, $key, $now): array {
        $last = $s['rows'] === 0 ? null : one_value($pdo, 'SELECT max(trade_date) FROM prices WHERE security_id = :s', ['s' => $s['security_id']]);
        $from = $last ? (new DateTimeImmutable((string) $last))->modify('-10 days')->format('Y-m-d') : $now->modify('-5 years')->format('Y-m-d');
        $r = $client->get($base . rawurlencode((string) $s['ticker']) . '/range/1/day/' . $from . '/' . $now->format('Y-m-d') . '?adjusted=false&sort=asc&limit=50000',
            ['accept' => 'application/json', 'no_cache' => true, 'headers' => ['Authorization' => 'Bearer ' . $key]]);
        if ($r['blocked'] || $r['status'] === 401) {
            throw new SiPricesBlocked('Polygon refused us (' . $r['status'] . ') — the key or the plan.');
        }
        if (!$r['ok']) {
            throw new SiFetchError('Polygon answered ' . $r['status'] . ' for ' . $s['ticker'] . '.');
        }
        $doc = json_decode($r['body'], true) ?: [];
        $out = [];
        foreach ((array) ($doc['results'] ?? []) as $bar) {
            if (isset($bar['t'], $bar['c']) && is_numeric($bar['c'])) {
                $out[] = [(new DateTimeImmutable('@' . intdiv((int) $bar['t'], 1000)))->setTimezone(new DateTimeZone('America/New_York'))->format('Y-m-d'), $bar['o'] ?? null, $bar['h'] ?? null, $bar['l'] ?? null, $bar['c'], $bar['v'] ?? null];
            }
        }
        return $out;
    });
}

/** A keyed provider refused us or its limit is reached (the pull answers blocked). */
final class SiPricesBlocked extends RuntimeException {}

function si_prices_key(PDO $pdo, array $source, string $who): string
{
    try {
        $key = si_source_secret($pdo, $source);
    } catch (SiCredentialError $e) {
        throw new SiFetchError($e->getMessage());
    }
    if ($key === null || trim($key) === '') {
        throw new SiFetchError($who . ' needs a key: the admin sets it on the source.');
    }
    return trim($key);
}

/** A price CSV's bars [[date, open, high, low, close, volume]] (Date,Open,High,Low,Close,Volume; dates as 2024-01-11), null when it is not one. */
function si_prices_from_csv(string $body): ?array
{
    $lines = preg_split('~\r?\n~', trim($body));
    $head = array_map(static fn ($h) => strtolower(trim($h, " \t\"\xEF\xBB\xBF")), str_getcsv((string) ($lines[0] ?? ''), ',', '"', ''));
    $at = array_flip($head);
    if (!isset($at['date'], $at['close'])) {
        return null;
    }
    $out = [];
    foreach (array_slice($lines, 1) as $line) {
        $c = str_getcsv($line, ',', '"', '');
        $d = trim((string) ($c[$at['date']] ?? ''));
        $close = trim((string) ($c[$at['close']] ?? ''));
        if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $d) || !is_numeric($close)) {
            continue;
        }
        $v = static fn (string $k): ?string => isset($at[$k]) && is_numeric(trim((string) ($c[$at[$k]] ?? ''))) ? trim((string) $c[$at[$k]]) : null;
        $out[] = [$d, $v('open'), $v('high'), $v('low'), $close, $v('volume')];
    }
    return $out;
}

/** Bars into `prices` for one security; answers [new, changed]. */
function si_prices_upsert(PDO $pdo, int $security, int $issuer, ?int $source, array $bars): array
{
    $up = $pdo->prepare('INSERT INTO prices (security_id, trade_date, open, high, low, close, volume, source_id) VALUES (:s, :d, :o, :h, :l, :c, :v, :src)
                         ON CONFLICT (security_id, trade_date) DO UPDATE SET open = EXCLUDED.open, high = EXCLUDED.high, low = EXCLUDED.low, close = EXCLUDED.close, volume = EXCLUDED.volume, source_id = EXCLUDED.source_id, loaded_at = now()
                          WHERE (prices.open, prices.high, prices.low, prices.close, prices.volume) IS DISTINCT FROM (EXCLUDED.open, EXCLUDED.high, EXCLUDED.low, EXCLUDED.close, EXCLUDED.volume)
                         RETURNING (xmax = 0) AS inserted');
    $new = 0; $changed = 0;
    foreach ($bars as [$d, $o, $h, $l, $c, $v]) {
        $up->execute(['s' => $security, 'd' => $d, 'o' => $o, 'h' => $h, 'l' => $l, 'c' => $c, 'v' => $v === null ? null : (string) (int) round((float) $v), 'src' => $source]);
        $row = $up->fetch();
        if ($row !== false) {
            $row['inserted'] ? $new++ : $changed++;
        }
    }
    if ($new + $changed > 0) {
        log_activity($pdo, 'price.load', 'security', $security, ['issuer_id' => $issuer, 'security_id' => $security, 'after' => ['days' => $new + $changed, 'new' => $new, 'changed' => $changed]]);
    }
    return [$new, $changed];
}

/** Each tracked security's series through $fetch(security) → bars, upserted in one transaction a security. */
function si_prices_each(PDO $pdo, array $source, array $ctx, callable $fetch): array
{
    $secs = si_tracked_securities($pdo, $source, $ctx, 'ticker', 'prices');
    $seen = 0; $new = 0; $changed = 0; $detail = [];
    foreach ($secs as $s) {
        try {
            $bars = $fetch($s);
        } catch (SiPricesBlocked $e) {
            return ['status' => 'blocked', 'seen' => $seen, 'new' => $new, 'changed' => $changed, 'error' => $e->getMessage(), 'detail' => $detail];
        }
        $seen += count($bars);
        $pdo->beginTransaction();
        try {
            [$n, $c] = si_prices_upsert($pdo, $s['security_id'], $s['issuer_id'], (int) $source['id'], $bars);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $new += $n; $changed += $c;
        $detail[$s['security_id']] = count($bars);
    }
    return ['seen' => $seen, 'new' => $new, 'changed' => $changed, 'detail' => $detail];
}
