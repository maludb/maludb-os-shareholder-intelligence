<?php
declare(strict_types=1);

/**
 * Connectors `threshold_nasdaq`, `threshold_nyse`, `threshold_otc` (edgar-and-finra-fetchers.md §2; short-side.md): the Reg SHO threshold
 * lists, one list a market day.
 *   - Nasdaq: <base_url>nasdaqthYYYYMMDD.txt — "Symbol|Security Name|Market Category|Reg SHO Threshold Flag|Rule 3210|Filler" and a trailer
 *     line (the file's time);
 *   - NYSE: <base_url>?selectedDate=YYYY-MM-DD&market=<NYSE | NYSE American | NYSE Arca> — the same pipe layout, one file per market;
 *   - OTC: FINRA's Query API dataset `thresholdList` (keyless), POSTed for one trade date: a row on Reg SHO's list (regShoThresholdFlag Y)
 *     or on FINRA Rule 4320's (rule4320Flag Y, the non-reporting OTC securities) — the flag says which ('Y' or 'R4320').
 * Which days: every market day after the newest list read (`settings.done`) up to yesterday — today too from 22:00 market time, when the lists
 * are out — and the last `settings.backfill_days` (10) for a security never loaded. A file that is not there, or an empty Nasdaq or OTC list, is
 * a day not yet published (asked again next pull); an empty NYSE market's list is a real day with no entry.
 *
 * Every list day read is recorded in `threshold_list_days` (db/023) with its entry count, tracked securities or not — the calendar
 * "consecutive list days" are counted on; the tracked symbols' entries are upserted into threshold_listings and a tracked security no longer on
 * a re-read day's list is removed from it. `threshold.list` when a security is on a list it was not on the list's previous day,
 * `threshold.delist` when it leaves.
 */

function si_fetch_threshold_nasdaq(PDO $pdo, array $source, SiHttp $client, array $ctx): array
{
    $base = rtrim((string) ($source['base_url'] ?: 'https://www.nasdaqtrader.com/dynamic/symdir/regsho/'), '/') . '/';
    return si_threshold_pull($pdo, $source, $ctx, ['nasdaq'], static function (string $list, string $date) use ($client, $base): ?array {
        $r = $client->get($base . 'nasdaqth' . str_replace('-', '', $date) . '.txt', ['accept' => 'text/plain, */*;q=0.8', 'no_cache' => true]);
        $entries = si_threshold_answer($r, 'Nasdaq', $date);
        return $entries === [] ? null : $entries;                          // Nasdaq's list is never empty: an empty file is not published yet
    });
}

function si_fetch_threshold_nyse(PDO $pdo, array $source, SiHttp $client, array $ctx): array
{
    $settings = json_decode((string) $source['settings'], true) ?: [];
    $base = (string) ($source['base_url'] ?: 'https://www.nyse.com/api/regulatory/threshold-securities/download');
    $markets = ['NYSE' => 'nyse', 'NYSE American' => 'nyse_american', 'NYSE Arca' => 'nyse_arca'];
    $lists = [];
    foreach ((array) ($settings['markets'] ?? array_keys($markets)) as $m) {
        if (isset($markets[$m])) { $lists[$markets[$m]] = $m; }
    }
    return si_threshold_pull($pdo, $source, $ctx, array_keys($lists), static function (string $list, string $date) use ($client, $base, $lists): ?array {
        $r = $client->get($base . '?selectedDate=' . $date . '&market=' . rawurlencode($lists[$list]), ['accept' => 'text/plain, */*;q=0.8', 'no_cache' => true]);
        $entries = si_threshold_answer($r, $lists[$list], $date);
        return $entries === null || ($entries === [] && !preg_match('~^\d{14}\s*$~m', $r['body'])) ? null : $entries;   // an empty market's list is real once the file is complete
    });
}

function si_fetch_threshold_otc(PDO $pdo, array $source, SiHttp $client, array $ctx): array
{
    $url = (string) ($source['base_url'] ?: 'https://api.finra.org/data/group/otcMarket/name/thresholdList');
    return si_threshold_pull($pdo, $source, $ctx, ['otc'], static function (string $list, string $date) use ($client, $url): ?array {
        $r = $client->post($url, json_encode(['limit' => 5000, 'compareFilters' => [['compareType' => 'equal', 'fieldName' => 'tradeDate', 'fieldValue' => $date]]]), ['accept' => 'application/json']);
        if ($r['blocked']) {
            throw new SiThresholdBlocked('FINRA refused us (' . $r['status'] . ') — the user-agent or the rate.');
        }
        if (!$r['ok']) {
            throw new SiFetchError('FINRA answered ' . $r['status'] . ' for the OTC threshold list of ' . $date . '.');
        }
        $rows = json_decode($r['body'], true);
        if (!is_array($rows)) {
            throw new SiFetchError('FINRA\'s OTC threshold answer is not a list of records.');
        }
        $out = [];
        foreach ($rows as $row) {
            $sym = strtoupper(trim((string) ($row['issueSymbolIdentifier'] ?? '')));
            $regsho = strtoupper((string) ($row['regShoThresholdFlag'] ?? '')) === 'Y';
            if ($sym !== '' && ($regsho || strtoupper((string) ($row['rule4320Flag'] ?? '')) === 'Y')) {
                $out[$sym] = [$regsho ? 'Y' : 'R4320', null];
            }
        }
        return $out === [] ? null : $out;                                  // FINRA's OTC list is never empty: no rows is not published yet
    });
}

/** A threshold source refused us (the pull answers blocked). */
final class SiThresholdBlocked extends RuntimeException {}

/** A pipe-delimited list file's entries [symbol => [flag, rule_3210]]; null when the file is not there (404). */
function si_threshold_answer(array $r, string $what, string $date): ?array
{
    if ($r['blocked']) {
        throw new SiThresholdBlocked($what . ' refused us (' . $r['status'] . ') — the user-agent or the rate.');
    }
    if ($r['skipped']) {
        throw new SiThresholdBlocked('robots.txt disallows the ' . $what . ' threshold path, or the host answered robots.txt with a bot wall (Imperva challenges machines at nasdaqtrader.com).');
    }
    if ($r['status'] === 404) {
        return null;
    }
    if (!$r['ok']) {
        throw new SiFetchError($what . ' answered ' . $r['status'] . ' for the threshold list of ' . $date . '.');
    }
    $lines = preg_split('~\r?\n~', trim($r['body']));
    if (!str_starts_with((string) ($lines[0] ?? ''), 'Symbol|')) {
        if (preg_match('~<html|<!doctype~i', substr($r['body'], 0, 512))) {
            return null;                                                    // Nasdaq answers a missing day with its "not found" page
        }
        throw new SiFetchError('The ' . $what . ' threshold list of ' . $date . ' is not the pipe layout.');
    }
    $head = array_map('strtolower', explode('|', $lines[0]));
    $flagAt = array_search('reg sho threshold flag', $head, true);
    $ruleAt = array_search('rule 3210', $head, true);
    $out = [];
    foreach (array_slice($lines, 1) as $line) {
        $c = explode('|', $line);
        if (count($c) < 3 || !preg_match('~^[A-Z0-9.\-]{1,12}$~', strtoupper(trim($c[0])))) {
            continue;                                                       // the trailer (the file's time)
        }
        $out[strtoupper(trim($c[0]))] = [$flagAt === false ? null : (trim((string) ($c[$flagAt] ?? '')) ?: null), $ruleAt === false ? null : (trim((string) ($c[$ruleAt] ?? '')) ?: null)];
    }
    return $out;
}

/**
 * The days to read for each list and the read itself: $read(list, date) answers [symbol => [flag, rule]] or null (not published yet).
 * Answers the connector's result.
 */
function si_threshold_pull(PDO $pdo, array $source, array $ctx, array $lists, callable $read): array
{
    $settings = json_decode((string) $source['settings'], true) ?: [];
    $back = max(1, min(60, (int) ($settings['backfill_days'] ?? 10)));
    $secs = si_tracked_securities($pdo, $source, $ctx, 'ticker', 'threshold_listings');
    $backfilled = array_map('intval', (array) ($settings['backfilled'] ?? []));
    $fresh = array_values(array_filter($secs, static fn ($s) => $s['rows'] === 0 && !in_array($s['security_id'], $backfilled, true)));
    $now = ($ctx['now'] ?? new DateTimeImmutable('now'))->setTimezone(si_market_tz($pdo));
    $through = (int) $now->format('G') >= 22 ? $now->format('Y-m-d') : $now->modify('-1 day')->format('Y-m-d');
    $window = array_slice(si_market_days($pdo, $now->modify('-' . ($back * 2 + 10) . ' days')->format('Y-m-d'), $through), -$back);
    $done = (array) ($settings['done'] ?? []);
    $bySymbol = [];
    foreach ($secs as $s) {
        $bySymbol[strtoupper((string) $s['ticker'])][] = $s;
    }
    $seen = 0; $new = 0; $changed = 0; $read_ = []; $absent = [];
    try {
        foreach ($lists as $list) {
            $after = isset($done[$list]) ? (string) $done[$list] : null;
            $dates = ($fresh !== [] || $after === null) ? $window : array_values(array_filter($window, static fn ($d) => $d > $after));
            foreach ($dates as $d) {
                $entries = $read($list, $d);
                if ($entries === null) {
                    $absent[] = $list . ' ' . $d;
                    continue;
                }
                [$s, $n, $c] = si_threshold_load($pdo, (int) $source['id'], $list, $d, $entries, $bySymbol);
                $seen += $s; $new += $n; $changed += $c;
                $read_[] = $list . ' ' . $d;
                if ($after === null || $d > $after) {
                    $after = $d;
                    $done[$list] = $d;
                }
            }
        }
    } catch (SiThresholdBlocked $e) {
        si_source_settings_merge($pdo, (int) $source['id'], ['done' => $done ?: new stdClass()]);
        return ['status' => 'blocked', 'seen' => $seen, 'new' => $new, 'changed' => $changed, 'error' => $e->getMessage(), 'detail' => ['read' => $read_, 'absent' => $absent]];
    }
    si_source_settings_merge($pdo, (int) $source['id'], ['done' => $done ?: new stdClass(), 'backfilled' => array_values(array_unique(array_merge($backfilled, array_column($fresh, 'security_id'))))]);
    return ['seen' => $seen, 'new' => $new, 'changed' => $changed, 'detail' => ['read' => $read_, 'absent' => $absent]];
}

/** One list day into threshold_list_days and threshold_listings, in one transaction; logs list/delist. Answers [seen, new, changed]. */
function si_threshold_load(PDO $pdo, int $source, string $list, string $date, array $entries, array $bySymbol): array
{
    $seen = 0; $new = 0; $changed = 0;
    $pdo->beginTransaction();
    try {
        $prev = one_value($pdo, 'SELECT max(list_date) FROM (SELECT list_date FROM threshold_list_days WHERE list = :l1 AND list_date < :d1 UNION SELECT list_date FROM threshold_listings WHERE list = :l2 AND list_date < :d2) x',
            ['l1' => $list, 'd1' => $date, 'l2' => $list, 'd2' => $date]);
        $day = $pdo->prepare('INSERT INTO threshold_list_days (list, list_date, entries, source_id) VALUES (:l, :d, :n, :s) ON CONFLICT (list, list_date) DO UPDATE SET entries = EXCLUDED.entries, loaded_at = now() RETURNING (xmax = 0)');
        $day->execute(['l' => $list, 'd' => $date, 'n' => count($entries), 's' => $source]);
        $firstRead = (bool) $day->fetchColumn();                            // a re-read day logs no second delisting
        $on = $pdo->prepare('SELECT 1 FROM threshold_listings WHERE security_id = :s AND list = :l AND list_date = :d');
        $up = $pdo->prepare('INSERT INTO threshold_listings (security_id, list_date, list, flag, rule_3210, source_id) VALUES (:s, :d, :l, :f, :r, :src)
                             ON CONFLICT (security_id, list_date, list) DO UPDATE SET flag = EXCLUDED.flag, rule_3210 = EXCLUDED.rule_3210, loaded_at = now()
                              WHERE (threshold_listings.flag, threshold_listings.rule_3210) IS DISTINCT FROM (EXCLUDED.flag, EXCLUDED.rule_3210) RETURNING (xmax = 0) AS inserted');
        foreach ($bySymbol as $sym => $secs) {
            foreach ($secs as $s) {
                $wasPrev = $prev !== null && $prev !== false && (bool) one_value($pdo, 'SELECT 1 FROM threshold_listings WHERE security_id = :s AND list = :l AND list_date = :d', ['s' => $s['security_id'], 'l' => $list, 'd' => $prev]);
                if (isset($entries[$sym])) {
                    $seen++;
                    $up->execute(['s' => $s['security_id'], 'd' => $date, 'l' => $list, 'f' => $entries[$sym][0], 'r' => $entries[$sym][1], 'src' => $source]);
                    $row = $up->fetch();
                    if ($row !== false) {
                        $row['inserted'] ? $new++ : $changed++;
                        if ($row['inserted'] && !$wasPrev) {
                            log_activity($pdo, 'threshold.list', 'security', $s['security_id'], ['issuer_id' => $s['issuer_id'], 'security_id' => $s['security_id'], 'after' => ['security_id' => $s['security_id'], 'list' => $list, 'list_date' => $date]]);
                        }
                    }
                    continue;
                }
                $on->execute(['s' => $s['security_id'], 'l' => $list, 'd' => $date]);
                if ($on->fetchColumn()) {                                   // a re-read day no longer lists it
                    $pdo->prepare('DELETE FROM threshold_listings WHERE security_id = :s AND list = :l AND list_date = :d')->execute(['s' => $s['security_id'], 'l' => $list, 'd' => $date]);
                    $changed++;
                }
                if ($wasPrev && $firstRead) {
                    log_activity($pdo, 'threshold.delist', 'security', $s['security_id'], ['issuer_id' => $s['issuer_id'], 'security_id' => $s['security_id'], 'after' => ['security_id' => $s['security_id'], 'list' => $list, 'list_date' => $date]]);
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return [$seen, $new, $changed];
}
