<?php
/**
 * ONE LIVE PULL EACH against the real hosts (edgar-and-finra-fetchers.md §5), for the connectors named on the command line — run by a slice's
 * proof after its stub proofs, on the SCRATCH database only (SI_DEV_ENV): the EDGAR contact is the owner's answer (b) "Kinetic Seas
 * claude@kineticseas.com"; the reference issuer is the owner's (CIK 0001829311, Bitmine Immersion Technologies — the company behind the real
 * SPR files). Each pull runs under the policy (robots, the rate, the user-agent) through si_run_source(); the counts are printed; nothing is
 * kept outside the scratch database and its store. Network trouble is reported as a FAIL in words, never hidden.
 *   php tests/fetch/live.php dtcc_participants edgar_submissions
 */
if (getenv('SI_DEV_ENV') === false || getenv('SI_DEV_ENV') === '') {
    fwrite(STDERR, "live pulls run on a scratch database only (SI_DEV_ENV)\n");
    exit(2);
}
require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/si/fetch/source.php';
foreach (['edgar_ownership', 'edgar_13dg', 'edgar_144'] as $__c) { require_once dirname(__DIR__, 2) . '/app/si/fetch/' . $__c . '.php'; }   // their form lists, for the checks
$pdo = db();
$fails = 0;
$passes = 0;
$ok = static function (bool $c, string $l) use (&$fails, &$passes): void { $c ? $passes++ : $fails++; echo ($c ? '  ok   ' : '  FAIL ') . $l . "\n"; };
$pdo->exec("UPDATE si_settings SET business_name = 'Kinetic Seas', edgar_contact_email = 'claude@kineticseas.com' WHERE id = 1");
$real = ['edgar_submissions' => ['https://data.sec.gov/submissions/', 2], 'dtcc_participants' => ['https://www.dtcc.com/support/dtc-directories', 1],
         'edgar_companyfacts' => ['https://data.sec.gov/api/xbrl/companyfacts/', 2], 'edgar_fulltext' => ['https://efts.sec.gov/LATEST/search-index', 2],
         'edgar_ownership' => ['https://data.sec.gov/submissions/', 2], 'edgar_13dg' => ['https://data.sec.gov/submissions/', 2], 'edgar_144' => ['https://data.sec.gov/submissions/', 2],
         'edgar_13f_dataset' => ['https://www.sec.gov/files/structureddata/data/form-13f-data-sets/', 1],
         'finra_short_interest' => ['https://api.finra.org/data/group/otcMarket/name/consolidatedShortInterest', 1], 'finra_daily_short_volume' => ['https://cdn.finra.org/equity/regsho/daily/', 1],
         'sec_ftd' => [null, 1], 'threshold_nasdaq' => ['https://www.nasdaqtrader.com/dynamic/symdir/regsho/', 1], 'threshold_nyse' => ['https://www.nyse.com/api/regulatory/threshold-securities/download', 1],
         'threshold_otc' => ['https://api.finra.org/data/group/otcMarket/name/thresholdList', 1], 'prices_stooq' => ['https://stooq.com/q/d/l/', 1]];
// slice 6: one file each — the newest market day's CNMS file, the newest fails half-month, the last market day's lists; stooq unpaused to show it refuses machines
$pdo->exec("UPDATE sources SET settings = (settings - 'done' - 'backfilled') || '{\"backfill_days\": 1, \"markets\": [\"CNMS\"]}' WHERE connector = 'finra_daily_short_volume'");
$pdo->exec("UPDATE sources SET settings = (settings - 'loaded' - 'backfilled' - 'listing_url') || '{\"backfill_halves\": 1}' WHERE connector = 'sec_ftd'");
$pdo->exec("UPDATE sources SET settings = (settings - 'done' - 'backfilled') || '{\"backfill_days\": 1}' WHERE connector IN ('threshold_nasdaq', 'threshold_nyse', 'threshold_otc')");
$pdo->exec("UPDATE sources SET paused_at = NULL, paused_reason = NULL WHERE connector = 'prices_stooq'");
$pdo->exec("UPDATE sources SET settings = settings - 'listing_url' WHERE connector = 'edgar_13f_dataset'");            // the SEC's own page
$pdo->exec("UPDATE sources SET settings = settings - 'archives_url' WHERE connector = 'edgar_submissions'");       // the real archives
$pdo->exec("UPDATE sources SET settings = settings || '{\"terms\": [\"at-the-market\"]}' WHERE connector = 'edgar_fulltext'");   // one query a term: the live check is one term
foreach (array_slice($argv, 1) as $connector) {
    if (!isset($real[$connector])) {
        $ok(false, "$connector: no live pull is defined for it yet");
        continue;
    }
    $pdo->prepare("UPDATE sources SET base_url = COALESCE(:u, base_url), rate_per_second = :r, settings = settings - 'tickers_url', backoff_until = NULL, consecutive_failures = 0 WHERE connector = :c")
        ->execute(['u' => $real[$connector][0], 'r' => $real[$connector][1], 'c' => $connector]);
    $src = si_source_by_connector($pdo, $connector);
    $issuer = null;
    if ($connector !== 'dtcc_participants' && $connector !== 'edgar_13f_dataset') {
        $issuer = (int) (one_value($pdo, "SELECT id FROM issuers WHERE cik = '0001829311'") ?: 0);
        if ($issuer === 0) {
            $pdo->exec("INSERT INTO issuers (name, cik) VALUES ('BITMINE IMMERSION TECHNOLOGIES, INC.', '0001829311')");
            $issuer = (int) one_value($pdo, "SELECT id FROM issuers WHERE cik = '0001829311'");
            $pdo->exec("INSERT INTO securities (issuer_id, name, cusip, ticker, is_primary) VALUES ($issuer, 'Common Stock', '09175A206', 'BMNR', true)");   // the CUSIP since the 2025 split
        }
    }
    $t0 = microtime(true);
    $r = si_run_source($pdo, $src, ['kind' => 'manual', 'issuer' => $connector === 'edgar_13f_dataset' ? null : $issuer, 'listing_only' => $connector === 'edgar_13f_dataset']);   // the 13F set: its listing, not the download
    $pull = $pdo->query('SELECT http_requests, bytes, policy FROM source_pulls WHERE id = ' . (int) $r['pull_id'])->fetch();
    $pol = json_decode((string) $pull['policy'], true);
    if ($connector === 'prices_stooq') {                                                // the policy's answer: stooq refuses machines (found 2026-10-10)
        $asked = array_column(array_filter($pol['requests'] ?? [], static fn ($q) => empty($q['skipped'])), 'path');
        $ok($r['status'] === 'blocked' && !in_array('/q/d/l/', $asked, true), sprintf('prices_stooq live: %s — "%s"; asked %s (the CSV never)', $r['status'], $r['error'], implode(', ', $asked) ?: 'nothing'));
        continue;
    }
    $ok(in_array($r['status'], ['ok', 'partial'], true), sprintf('%s live: %s — %d seen, %d new, %d changed; %d requests, %d bytes, %.1f s, as "%s"%s', $connector, $r['status'], $r['seen'], $r['new'], $r['changed'],
        $pull['http_requests'], $pull['bytes'], microtime(true) - $t0, $pol['user_agent'] ?? '?', $r['error'] ? ' — ' . $r['error'] : ''));
    if ($connector === 'edgar_submissions' && $issuer) {
        $i = $pdo->query('SELECT name, edgar_entity->>\'name\' AS sec_name, ticker, state_of_incorporation, fiscal_year_end FROM issuers WHERE id = ' . $issuer)->fetch();
        $ok(($i['sec_name'] ?? '') !== '', sprintf('  the reference issuer read: %s, ticker %s, incorporated %s, year end %s', $i['sec_name'], $i['ticker'], $i['state_of_incorporation'], $i['fiscal_year_end']));
        require_once dirname(__DIR__, 2) . '/app/si/sec/tickers.php';
        try {
            $l = si_sec_lookup($pdo, 'BMNR');
            $ok($l !== null && $l['cik'] === '0001829311', '  the SEC\'s ticker file finds BMNR: ' . json_encode($l));
        } catch (SiFetchError $e) {
            $ok(false, '  the ticker lookup: ' . $e->getMessage());
        }
    }
    if ($connector === 'edgar_submissions' && $issuer) {
        $f = $pdo->query("SELECT count(*) AS n, count(*) FILTER (WHERE fetched_text_at IS NOT NULL) AS t, max(filed_on) AS last FROM filings WHERE issuer_id = $issuer")->fetch();
        $ok((int) $f['n'] > 50, sprintf('  the index: %d filings, the newest %s; %d watched filings read as text on arrival', $f['n'], $f['last'], $f['t']));
    }
    if ($connector === 'edgar_companyfacts' && $issuer) {
        $c = $pdo->query("SELECT count(*) AS n, max(as_of) AS last FROM share_counts WHERE kind = 'outstanding' AND source = 'filing'")->fetch();
        $v = one_value($pdo, "SELECT value FROM share_counts WHERE kind = 'outstanding' AND source = 'filing' ORDER BY as_of DESC LIMIT 1");
        $ok((int) $c['n'] > 3, sprintf('  the covers: %d outstanding counts, the latest %s shares as of %s', $c['n'], number_format((float) $v), $c['last']));
    }
    if ($connector === 'edgar_fulltext' && $issuer) {
        $w = $pdo->query("SELECT count(*) AS n, count(DISTINCT filing_id) AS f FROM watch_hits")->fetch();
        $d = (int) one_value($pdo, "SELECT count(*) FROM dilution_instruments WHERE status = 'proposed'");
        $ok(true, sprintf('  the watch (90 days, "at-the-market" and the name in others\' filings): %d hits on %d filings, %d instruments proposed', $w['n'], $w['f'], $d));
    }
    if (in_array($connector, ['edgar_ownership', 'edgar_13dg', 'edgar_144'], true) && $issuer) {
        $n = (int) one_value($pdo, ['edgar_ownership' => 'SELECT count(*) FROM insider_transactions', 'edgar_13dg' => 'SELECT count(*) FROM beneficial_ownership', 'edgar_144' => 'SELECT count(*) FROM form144_notices'][$connector]);
        $forms = ['edgar_ownership' => SI_OWNERSHIP_FORMS, 'edgar_13dg' => SI_13DG_FORMS, 'edgar_144' => ['144', '144/A']][$connector];
        $st = $pdo->prepare("SELECT count(*) FILTER (WHERE error LIKE 'Not read%'), count(*) FILTER (WHERE error LIKE 'About another%'), count(*) FILTER (WHERE structured_at IS NOT NULL) FROM filings WHERE form = ANY (CAST(:f AS text[]))");
        $st->execute(['f' => '{' . implode(',', array_map(static fn ($x) => '"' . $x . '"', $forms)) . '}']);
        [$e, $other, $read] = array_map('intval', $st->fetch(PDO::FETCH_NUM));
        $ok($e === 0, sprintf('  %s: %d of its filings read, %d rows kept, %d about another company set aside, %d not read as their form', $connector, $read, $n, $other, $e));
    }
    if ($connector === 'edgar_13f_dataset') {
        $ok(count($r['detail']['zips'] ?? []) > 4, '  the 13F page lists ' . count($r['detail']['zips'] ?? []) . ' data sets, the newest ' . (($r['detail']['zips'] ?? [])[0] ?? '?') . ' (not downloaded)');
    }
    $sid = $issuer ? (int) one_value($pdo, "SELECT id FROM securities WHERE issuer_id = $issuer AND is_primary") : 0;
    if ($connector === 'finra_short_interest' && $sid) {
        $l = $pdo->query("SELECT settlement_date, short_shares, days_to_cover, market FROM short_interest WHERE security_id = $sid ORDER BY settlement_date DESC LIMIT 1")->fetch();
        $n = (int) one_value($pdo, "SELECT count(*) FROM short_interest WHERE security_id = $sid");
        $ok($n > 20 && $l !== false, sprintf('  short interest: %d settlement dates, the latest %s — %s shares short, %s days to cover (%s)', $n, $l['settlement_date'] ?? '?', number_format((float) ($l['short_shares'] ?? 0)), $l['days_to_cover'] ?? '?', $l['market'] ?? '?'));
    }
    if ($connector === 'finra_daily_short_volume' && $sid) {
        $v = $pdo->query("SELECT trade_date, short_volume, total_volume, ratio FROM daily_short_volume WHERE security_id = $sid ORDER BY trade_date DESC LIMIT 1")->fetch();
        $ok($v !== false, sprintf('  the CNMS file of %s: %s short of %s off-exchange (ratio %s; fractional volumes kept to the share)', $v['trade_date'] ?? '?', number_format((float) ($v['short_volume'] ?? 0)), number_format((float) ($v['total_volume'] ?? 0)), $v['ratio'] ?? '?'));
    }
    if ($connector === 'sec_ftd' && $sid) {
        $f = $pdo->query("SELECT count(*) AS n, max(quantity) AS hi, min(settlement_date) AS a, max(settlement_date) AS b FROM fails_to_deliver WHERE security_id = $sid")->fetch();
        $ok(($r['detail']['read'] ?? []) !== [], sprintf('  %s read, tied to its trailer: %d days of fails for BMNR (%s to %s), the most %s', implode(', ', $r['detail']['read'] ?? []), $f['n'], $f['a'] ?? '—', $f['b'] ?? '—', number_format((float) $f['hi'])));
    }
    if (str_starts_with($connector, 'threshold_')) {
        $d = $pdo->query("SELECT list, list_date, entries FROM threshold_list_days ORDER BY list, list_date")->fetchAll();
        $mine = (int) one_value($pdo, "SELECT count(*) FROM threshold_listings WHERE security_id = $sid");
        $want = ['threshold_nasdaq' => ['nasdaq'], 'threshold_nyse' => ['nyse', 'nyse_american', 'nyse_arca'], 'threshold_otc' => ['otc']][$connector];
        $got = array_values(array_filter($d, static fn ($x) => in_array($x['list'], $want, true)));
        $ok(count($got) >= count($want), '  ' . implode('; ', array_map(static fn ($x) => $x['list'] . ' ' . $x['list_date'] . ': ' . $x['entries'] . ' securities', $got)) . " — BMNR listed on $mine of them");
    }
    if ($connector === 'dtcc_participants') {
        $n = (int) one_value($pdo, 'SELECT count(*) FROM participants');
        $ok($n > 800, "  the directory now holds $n participants");
    }
}
echo $fails ? "$fails FAILED ($passes passed)\n" : "all $passes passed\n";
exit($fails ? 1 : 0);
