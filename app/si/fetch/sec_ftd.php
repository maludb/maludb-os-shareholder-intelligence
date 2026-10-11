<?php
declare(strict_types=1);

/**
 * Connector `sec_ftd` (edgar-and-finra-fetchers.md §2; short-side.md): the SEC's fails-to-deliver data. The fails page
 * (`settings.listing_url`, https://www.sec.gov/data-research/sec-markets-data/fails-deliver-data) is read for its half-month zips
 * (cnsfailsYYYYMMa.zip the 1st–15th, …b.zip the 16th–end); the zips not yet read (`settings.loaded`) among the newest
 * `settings.backfill_halves` (24 — a year) are read, oldest first; a security never loaded has the year read again for it.
 *
 * A zip holds one pipe-delimited file "SETTLEMENT DATE|CUSIP|SYMBOL|QUANTITY (FAILS)|DESCRIPTION|PRICE" and ENDS WITH ITS OWN TRAILER —
 * "Trailer record count N" and "Trailer total quantity of shares N" (found on the first live call, 2026-10-10): the file's rows are checked
 * against it before anything is kept, and a file that does not tie is refused in words (a truncated download is not a half-month of fails).
 * The tracked CUSIPs' rows are upserted by (security, settlement date) with the file's half; `ftd.load` per zip with the rows kept.
 */

/** The zips on the fails page, newest first: [name => ['url', 'month' => YYYYMM, 'half' => a|b]]. */
function si_ftd_zips(string $html, string $base): array
{
    $out = [];
    if (preg_match_all('~href="([^"]*?cnsfails(\d{6})([ab])\.zip)"~i', $html, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) {
            $out[strtolower(basename($x[1]))] = ['url' => si_http_resolve($base, $x[1]), 'month' => $x[2], 'half' => strtolower($x[3])];
        }
    }
    uksort($out, static fn ($a, $b) => strcmp($b, $a));
    return $out;
}

function si_fetch_sec_ftd(PDO $pdo, array $source, SiHttp $client, array $ctx): array
{
    $settings = json_decode((string) $source['settings'], true) ?: [];
    $listing = (string) ($settings['listing_url'] ?? 'https://www.sec.gov/data-research/sec-markets-data/fails-deliver-data');
    $halves = max(1, min(120, (int) ($settings['backfill_halves'] ?? 24)));
    $loaded = array_values(array_map('strval', (array) ($settings['loaded'] ?? [])));
    $backfilled = array_map('intval', (array) ($settings['backfilled'] ?? []));
    $secs = si_tracked_securities($pdo, $source, $ctx, 'cusip', 'fails_to_deliver');
    if ($secs === []) {
        return ['seen' => 0, 'new' => 0, 'changed' => 0, 'detail' => ['note' => 'No tracked security has a CUSIP.']];
    }
    $byCusip = [];
    foreach ($secs as $s) {
        $byCusip[strtoupper((string) $s['cusip'])] = $s;
    }
    $fresh = array_values(array_filter($secs, static fn ($s) => $s['rows'] === 0 && !in_array($s['security_id'], $backfilled, true)));
    $r = $client->get($listing, ['accept' => 'text/html']);
    if ($r['blocked']) {
        return ['status' => 'blocked', 'seen' => 0, 'new' => 0, 'changed' => 0, 'error' => 'The SEC refused the fails page (' . $r['status'] . ').'];
    }
    if ($r['skipped']) {
        throw new SiFetchError('robots.txt disallows the SEC\'s fails page.');
    }
    if (!$r['ok']) {
        throw new SiFetchError('The SEC answered ' . $r['status'] . ' for the fails page.');
    }
    $zips = array_slice(si_ftd_zips($r['body'], $listing), 0, $halves, true);
    if ($zips === []) {
        throw new SiFetchError('The SEC\'s fails page lists no cnsfails zip.');
    }
    $todo = $fresh !== [] ? $zips : array_filter($zips, static fn ($n) => !in_array($n, $loaded, true), ARRAY_FILTER_USE_KEY);
    $todo = array_reverse($todo, true);                                     // oldest first
    $up = $pdo->prepare('INSERT INTO fails_to_deliver (security_id, settlement_date, quantity, price, file_half, source_id) VALUES (:s, :d, :q, :p, :h, :src)
                         ON CONFLICT (security_id, settlement_date) DO UPDATE SET quantity = EXCLUDED.quantity, price = EXCLUDED.price, file_half = EXCLUDED.file_half, source_id = EXCLUDED.source_id, loaded_at = now()
                          WHERE (fails_to_deliver.quantity, fails_to_deliver.price) IS DISTINCT FROM (EXCLUDED.quantity, EXCLUDED.price)
                         RETURNING (xmax = 0) AS inserted');
    $seen = 0; $new = 0; $changed = 0; $read = [];
    foreach ($todo as $name => $z) {
        $resp = $client->get($z['url'], ['accept' => 'application/zip', 'no_cache' => true, 'max_bytes' => 64 * 1024 * 1024, 'timeout' => 120]);
        if ($resp['blocked']) {
            return ['status' => 'blocked', 'seen' => $seen, 'new' => $new, 'changed' => $changed, 'error' => 'The SEC refused ' . $name . ' (' . $resp['status'] . ').', 'detail' => ['read' => $read]];
        }
        if (!$resp['ok']) {
            throw new SiFetchError('The SEC answered ' . $resp['status'] . ' for ' . $name . '.');
        }
        $rows = si_ftd_rows($resp['body'], $name);
        $kept = 0;
        $pdo->beginTransaction();
        try {
            foreach ($rows as [$date, $cusip, $qty, $price]) {
                $s = $byCusip[$cusip] ?? null;
                if ($s === null) {
                    continue;
                }
                $seen++;
                $up->execute(['s' => $s['security_id'], 'd' => $date, 'q' => $qty, 'p' => $price, 'h' => $z['half'], 'src' => (int) $source['id']]);
                $row = $up->fetch();
                if ($row !== false) {
                    $row['inserted'] ? $new++ : $changed++;
                    $kept++;
                }
            }
            log_activity($pdo, 'ftd.load', 'source', (int) $source['id'], ['after' => ['file' => $name, 'rows' => $kept, 'half' => $z['month'] . $z['half'], 'securities' => count($secs)]]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $read[] = $name;
        $loaded[] = $name;
    }
    si_source_settings_merge($pdo, (int) $source['id'], ['loaded' => array_values(array_unique(array_slice($loaded, -200))), 'backfilled' => array_values(array_unique(array_merge($backfilled, array_column($fresh, 'security_id'))))]);
    return ['seen' => $seen, 'new' => $new, 'changed' => $changed, 'detail' => ['zips' => array_keys($zips), 'read' => $read]];
}

/**
 * A fails zip's rows, checked against its trailer: [[settlement_date, cusip, quantity, price|null], …] — every row of the file (the
 * caller keeps the tracked ones). Throws SiFetchError when the zip cannot be read or its rows do not tie to the trailer.
 */
function si_ftd_rows(string $zipBytes, string $name): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'si-ftd-');
    file_put_contents($tmp, $zipBytes);
    try {
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true || $zip->numFiles < 1) {
            throw new SiFetchError($name . ' is not a readable zip.');
        }
        $text = (string) $zip->getFromIndex(0);
        $zip->close();
    } finally {
        @unlink($tmp);
    }
    $rows = []; $count = 0; $sum = 0; $trailerCount = null; $trailerSum = null;
    foreach (preg_split('~\r?\n~', $text) as $line) {
        if (preg_match('~^Trailer record count\s+(\d+)~i', $line, $m)) { $trailerCount = (int) $m[1]; continue; }
        if (preg_match('~^Trailer total quantity of shares\s+(\d+)~i', $line, $m)) { $trailerSum = (int) $m[1]; continue; }
        $c = explode('|', $line);
        if (count($c) < 4 || !preg_match('~^(\d{4})(\d{2})(\d{2})$~', trim($c[0]), $d)) {
            continue;                                                       // the header, a blank line
        }
        $count++;
        $qty = preg_match('~^\d+$~', trim($c[3])) ? (int) trim($c[3]) : null;
        $sum += $qty ?? 0;
        $cusip = strtoupper(trim($c[1]));
        if ($qty === null || !preg_match('~^[0-9A-Z]{9}$~', $cusip)) {
            continue;
        }
        $price = isset($c[5]) && is_numeric(trim($c[5])) ? trim($c[5]) : null;
        $rows[] = ["$d[1]-$d[2]-$d[3]", $cusip, (string) $qty, $price];
    }
    if ($trailerCount !== null && $trailerCount !== $count) {
        throw new SiFetchError($name . '\'s trailer says ' . number_format($trailerCount) . ' records, the file has ' . number_format($count) . ': not loaded.');
    }
    if ($trailerSum !== null && $trailerSum !== $sum) {
        throw new SiFetchError($name . '\'s trailer says ' . number_format($trailerSum) . ' shares, the rows add to ' . number_format($sum) . ': not loaded.');
    }
    return $rows;
}
