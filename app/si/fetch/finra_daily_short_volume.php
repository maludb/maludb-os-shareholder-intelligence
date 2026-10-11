<?php
declare(strict_types=1);

/**
 * Connector `finra_daily_short_volume` (edgar-and-finra-fetchers.md §2; short-side.md): FINRA's Reg SHO daily files,
 * <base_url><MARKET>shvol<YYYYMMDD>.txt for each market of `settings.markets` (CNMS — the consolidated NMS file — and FORF, the OTC one),
 * pipe-delimited "Date|Symbol|ShortVolume|ShortExemptVolume|TotalVolume|Market" with a trailer line (the record count). The tracked symbols'
 * lines are upserted into daily_short_volume by (security, date, market); the ratio is the table's own generated column.
 *
 * Which days: every market day after the last file read (`settings.done[market]`) through today, and — for a security never loaded — the last
 * `settings.backfill_days` (60) market days (the security then recorded in `settings.backfilled`). A file that is not there (404) is not a
 * failure: a holiday FINRA keeps that the calendar does not, or today's file before FINRA publishes it (about 18:00 market time) — the days
 * after the last file read are asked again next pull, and an absent day older than a file that answered is a day without trading.
 * **FINRA writes fractional volumes since 2026** (3493384.390661 — fractional-share trading): the shares are kept rounded to the whole share,
 * as the columns are. The volume is OFF-EXCHANGE ONLY and never short interest: FINRA's caveat travels with every reading of it.
 */

function si_fetch_finra_daily_short_volume(PDO $pdo, array $source, SiHttp $client, array $ctx): array
{
    $settings = json_decode((string) $source['settings'], true) ?: [];
    $base = rtrim((string) ($source['base_url'] ?: 'https://cdn.finra.org/equity/regsho/daily/'), '/') . '/';
    $markets = array_values(array_intersect(array_map('strtoupper', (array) ($settings['markets'] ?? ['CNMS'])), ['CNMS', 'FORF', 'FNRA', 'FNYX', 'FNSQ', 'FNQC']));
    $back = max(1, min(260, (int) ($settings['backfill_days'] ?? 60)));
    $secs = si_tracked_securities($pdo, $source, $ctx, 'ticker', 'daily_short_volume');
    if ($secs === [] || $markets === []) {
        return ['seen' => 0, 'new' => 0, 'changed' => 0, 'detail' => ['note' => 'No tracked security has a ticker.']];
    }
    $now = ($ctx['now'] ?? new DateTimeImmutable('now'))->setTimezone(si_market_tz($pdo));
    $today = $now->format('Y-m-d');
    $window = si_market_days($pdo, $now->modify('-' . (int) ceil($back * 1.6 + 10) . ' days')->format('Y-m-d'), $today);
    $window = array_slice($window, -$back);
    $backfilled = array_map('intval', (array) ($settings['backfilled'] ?? []));
    $fresh = array_values(array_filter($secs, static fn ($s) => $s['rows'] === 0 && !in_array($s['security_id'], $backfilled, true)));
    $bySymbol = [];
    foreach ($secs as $s) {
        $bySymbol[strtoupper((string) $s['ticker'])][] = $s;
    }
    $done = (array) ($settings['done'] ?? []);
    $up = $pdo->prepare('INSERT INTO daily_short_volume (security_id, trade_date, market, short_volume, short_exempt_volume, total_volume, source_id) VALUES (:s, :d, :m, :sv, :sev, :tv, :src)
                         ON CONFLICT (security_id, trade_date, market) DO UPDATE SET short_volume = EXCLUDED.short_volume, short_exempt_volume = EXCLUDED.short_exempt_volume, total_volume = EXCLUDED.total_volume,
                                source_id = EXCLUDED.source_id, loaded_at = now()
                          WHERE (daily_short_volume.short_volume, daily_short_volume.short_exempt_volume, daily_short_volume.total_volume) IS DISTINCT FROM (EXCLUDED.short_volume, EXCLUDED.short_exempt_volume, EXCLUDED.total_volume)
                         RETURNING (xmax = 0) AS inserted');
    $seen = 0; $new = 0; $changed = 0; $absent = []; $read = []; $perSec = [];
    $whole = static fn (string $v): string => is_numeric($v) ? (string) (int) round((float) $v) : '';
    foreach ($markets as $m) {
        $after = isset($done[$m]) && preg_match('~^\d{4}-\d{2}-\d{2}$~', (string) $done[$m]) ? (string) $done[$m] : null;
        $dates = array_values(array_filter($window, static fn ($d) => $after === null || $d > $after));
        if ($fresh !== [] && $after !== null) {
            $dates = $window;                                               // a security never loaded: the whole window, every symbol read again (upserts)
        }
        $last = $after;
        foreach ($dates as $d) {
            $r = $client->get($base . $m . 'shvol' . str_replace('-', '', $d) . '.txt', ['accept' => 'text/plain', 'no_cache' => true, 'max_bytes' => 16 * 1024 * 1024]);
            if ($r['blocked']) {
                return ['status' => 'blocked', 'seen' => $seen, 'new' => $new, 'changed' => $changed, 'error' => 'FINRA refused us (' . $r['status'] . ') — the user-agent or the rate.', 'detail' => ['read' => $read, 'absent' => $absent]];
            }
            if ($r['skipped']) {
                throw new SiFetchError('robots.txt disallows FINRA\'s daily short-volume path, or the host answered robots.txt with a bot wall.');
            }
            if ($r['status'] === 404 || $r['status'] === 403) {
                $absent[] = $m . ' ' . $d;
                continue;
            }
            if (!$r['ok']) {
                throw new SiFetchError('FINRA answered ' . $r['status'] . ' for the ' . $m . ' file of ' . $d . '.');
            }
            if (!str_starts_with(ltrim($r['body']), 'Date|Symbol|ShortVolume')) {
                throw new SiFetchError('The ' . $m . ' file of ' . $d . ' is not FINRA\'s short-volume layout.');
            }
            $pdo->beginTransaction();
            try {
                foreach (preg_split('~\r?\n~', $r['body']) as $line) {
                    $c = explode('|', $line);
                    if (count($c) < 5 || !isset($bySymbol[strtoupper($c[1])]) || !preg_match('~^(\d{4})(\d{2})(\d{2})$~', $c[0], $dm)) {
                        continue;
                    }
                    $sv = $whole($c[2]); $sev = $whole($c[3]); $tv = $whole($c[4]);
                    if ($sv === '' || $tv === '') {
                        continue;
                    }
                    foreach ($bySymbol[strtoupper($c[1])] as $s) {
                        $seen++;
                        $up->execute(['s' => $s['security_id'], 'd' => "$dm[1]-$dm[2]-$dm[3]", 'm' => $m, 'sv' => $sv, 'sev' => $sev === '' ? '0' : $sev, 'tv' => $tv, 'src' => (int) $source['id']]);
                        $row = $up->fetch();
                        if ($row !== false) {
                            $row['inserted'] ? $new++ : $changed++;
                            $perSec[$s['security_id']] = ($perSec[$s['security_id']] ?? 0) + 1;
                        }
                    }
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            $read[] = $m . ' ' . $d;
            if ($last === null || $d > $last) {
                $last = $d;
            }
        }
        if ($last !== null) {
            $done[$m] = $last;
        }
    }
    foreach ($perSec as $sid => $n) {
        $s = array_values(array_filter($secs, static fn ($x) => $x['security_id'] === $sid))[0];
        log_activity($pdo, 'volume.load', 'security', $sid, ['issuer_id' => $s['issuer_id'], 'security_id' => $sid, 'after' => ['days' => $n, 'markets' => $markets]]);
    }
    si_source_settings_merge($pdo, (int) $source['id'], ['done' => $done ?: new stdClass(), 'backfilled' => array_values(array_unique(array_merge($backfilled, array_column($fresh, 'security_id'))))]);
    return ['seen' => $seen, 'new' => $new, 'changed' => $changed, 'detail' => ['read' => $read, 'absent' => $absent]];
}
