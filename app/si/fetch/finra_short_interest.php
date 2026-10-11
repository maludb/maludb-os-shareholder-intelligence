<?php
declare(strict_types=1);

/**
 * Connector `finra_short_interest` (edgar-and-finra-fetchers.md §2; short-side.md): FINRA's Query API, the dataset `consolidatedShortInterest`
 * (group otcMarket — every market, exchange-listed and OTC; keyless: confirmed on the first live call, 2026-10-10, db/023), POSTed with the
 * tracked tickers as a domain filter (fifty a request), paged by `offset` up to FINRA's `record-total`. Every settlement date FINRA has for a
 * ticker is read every pull (a rolling year and more — Bitmine's 76 dates since 2022), so a missed publication is caught by the next one.
 *
 * **A revision is a new row**: a settlement date's figures (short, previous, change, average volume, days to cover, the split flag) are
 * compared with the latest observation of that date; the same figures change nothing, different ones append an observation with
 * `revision_flag` (FINRA's own `revisionFlag` = Y, or an earlier observation exists) — logged `short.revise`, a first one `short.load`.
 * FINRA's fields are kept by name (currentShortPositionQuantity → short_shares, daysToCoverQuantity → days_to_cover, …); `published_on` is the
 * record's `publishedDate` when FINRA sends one, else NULL (the screen shows the day it was first read). Calendar: calendar.php.
 */

const SI_FINRA_SI_DATASET = 'consolidatedShortInterest';

function si_fetch_finra_short_interest(PDO $pdo, array $source, SiHttp $client, array $ctx): array
{
    $settings = json_decode((string) $source['settings'], true) ?: [];
    $url = (string) ($source['base_url'] ?: 'https://api.finra.org/data/group/otcMarket/name/' . SI_FINRA_SI_DATASET);
    $secs = si_tracked_securities($pdo, $source, $ctx, 'ticker');
    $bySymbol = [];
    foreach ($secs as $s) {
        $bySymbol[strtoupper((string) $s['ticker'])][] = $s;
    }
    if ($bySymbol === []) {
        return ['seen' => 0, 'new' => 0, 'changed' => 0, 'detail' => ['note' => 'No tracked security has a ticker.']];
    }
    $latest = $pdo->prepare('SELECT short_shares, previous_short_shares, change_shares, avg_daily_volume, days_to_cover, change_percent FROM short_interest
                              WHERE security_id = :s AND settlement_date = :d ORDER BY observed_at DESC LIMIT 1');
    $ins = $pdo->prepare('INSERT INTO short_interest (security_id, settlement_date, published_on, short_shares, previous_short_shares, change_shares, change_percent, avg_daily_volume, days_to_cover,
                                 revision_flag, market, source_id, observed_at) VALUES (:s, :d, :pub, :sh, :prev, :chg, :pct, :adv, :dtc, :rev, :m, :src, clock_timestamp()) RETURNING id');
    $seen = 0; $new = 0; $changed = 0; $detail = [];
    $num = static fn ($v): ?string => $v === null || $v === '' || !is_numeric($v) ? null : (string) $v;
    foreach (array_chunk(array_keys($bySymbol), 50) as $chunk) {
        $offset = 0;
        do {
            $body = json_encode(['limit' => 5000, 'offset' => $offset, 'domainFilters' => [['fieldName' => 'symbolCode', 'values' => array_values($chunk)]]]);
            $r = $client->post($url, $body, ['accept' => 'application/json', 'max_bytes' => 32 * 1024 * 1024]);
            if ($r['blocked']) {
                return ['status' => 'blocked', 'seen' => $seen, 'new' => $new, 'changed' => $changed, 'error' => 'FINRA refused us (' . $r['status'] . ') — the user-agent or the rate.', 'detail' => $detail];
            }
            if (!$r['ok']) {
                $msg = json_decode($r['body'], true)['message'] ?? null;
                throw new SiFetchError('FINRA answered ' . $r['status'] . ' for short interest' . (is_string($msg) ? ': ' . mb_substr($msg, 0, 120) : '.'));
            }
            $rows = json_decode($r['body'], true);
            if (!is_array($rows) || ($rows !== [] && !array_is_list($rows))) {
                throw new SiFetchError('FINRA\'s short-interest answer is not a list of records.');
            }
            $total = (int) ($r['headers']['record-total'] ?? count($rows));
            $pdo->beginTransaction();
            try {
                foreach ($rows as $row) {
                    $sym = strtoupper(trim((string) ($row['symbolCode'] ?? '')));
                    $date = (string) ($row['settlementDate'] ?? '');
                    $short = $num($row['currentShortPositionQuantity'] ?? null);
                    if (!isset($bySymbol[$sym]) || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $date) || $short === null) {
                        continue;
                    }
                    $f = ['sh' => $short, 'prev' => $num($row['previousShortPositionQuantity'] ?? null), 'chg' => $num($row['changePreviousNumber'] ?? null),
                          'adv' => $num($row['averageDailyVolumeQuantity'] ?? null), 'dtc' => $num($row['daysToCoverQuantity'] ?? null), 'pct' => $num($row['changePercent'] ?? null)];
                    foreach ($bySymbol[$sym] as $s) {
                        $seen++;
                        $latest->execute(['s' => $s['security_id'], 'd' => $date]);
                        $was = $latest->fetch();
                        if ($was !== false && si_short_same($was, $f)) {
                            continue;
                        }
                        $pub = isset($row['publishedDate']) && preg_match('~^\d{4}-\d{2}-\d{2}~', (string) $row['publishedDate']) ? substr((string) $row['publishedDate'], 0, 10) : null;
                        $ins->execute($f + ['s' => $s['security_id'], 'd' => $date, 'pub' => $pub, 'rev' => ($was !== false || strtoupper((string) ($row['revisionFlag'] ?? '')) === 'Y') ? 'true' : 'false',
                            'm' => isset($row['marketClassCode']) ? mb_substr((string) $row['marketClassCode'], 0, 12) : null, 'src' => (int) $source['id']]);
                        $id = (int) $ins->fetchColumn();
                        $was === false ? $new++ : $changed++;
                        log_activity($pdo, $was === false ? 'short.load' : 'short.revise', 'short_interest', $id, ['issuer_id' => $s['issuer_id'], 'security_id' => $s['security_id'],
                            'after' => ['security_id' => $s['security_id'], 'settlement_date' => $date, 'short_shares' => (float) $short] + ($was === false ? [] : ['before_short_shares' => (float) $was['short_shares']])]);
                        $detail[$s['security_id']] = ($detail[$s['security_id']] ?? 0) + 1;
                    }
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            $offset += count($rows);
        } while ($rows !== [] && $offset < $total);
    }
    return ['seen' => $seen, 'new' => $new, 'changed' => $changed, 'detail' => $detail];
}

/** Are FINRA's figures the latest observation's? (numbers compared as numbers: 3.57 = 3.5700) */
function si_short_same(array $was, array $f): bool
{
    foreach (['short_shares' => 'sh', 'previous_short_shares' => 'prev', 'change_shares' => 'chg', 'avg_daily_volume' => 'adv', 'days_to_cover' => 'dtc', 'change_percent' => 'pct'] as $col => $k) {
        $a = $was[$col]; $b = $f[$k];
        if (($a === null) !== ($b === null) || ($a !== null && abs((float) $a - (float) $b) > 1e-6)) {
            return false;
        }
    }
    return true;
}
