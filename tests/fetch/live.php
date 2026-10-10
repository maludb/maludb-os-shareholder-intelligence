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
$pdo = db();
$fails = 0;
$passes = 0;
$ok = static function (bool $c, string $l) use (&$fails, &$passes): void { $c ? $passes++ : $fails++; echo ($c ? '  ok   ' : '  FAIL ') . $l . "\n"; };
$pdo->exec("UPDATE si_settings SET business_name = 'Kinetic Seas', edgar_contact_email = 'claude@kineticseas.com' WHERE id = 1");
$real = ['edgar_submissions' => ['https://data.sec.gov/submissions/', 2], 'dtcc_participants' => ['https://www.dtcc.com/support/dtc-directories', 1],
         'edgar_companyfacts' => ['https://data.sec.gov/api/xbrl/companyfacts/', 2], 'edgar_fulltext' => ['https://efts.sec.gov/LATEST/search-index', 2]];
$pdo->exec("UPDATE sources SET settings = settings - 'archives_url' WHERE connector = 'edgar_submissions'");       // the real archives
$pdo->exec("UPDATE sources SET settings = settings || '{\"terms\": [\"at-the-market\"]}' WHERE connector = 'edgar_fulltext'");   // one query a term: the live check is one term
foreach (array_slice($argv, 1) as $connector) {
    if (!isset($real[$connector])) {
        $ok(false, "$connector: no live pull is defined for it yet");
        continue;
    }
    $pdo->prepare("UPDATE sources SET base_url = :u, rate_per_second = :r, settings = settings - 'tickers_url', backoff_until = NULL, consecutive_failures = 0 WHERE connector = :c")
        ->execute(['u' => $real[$connector][0], 'r' => $real[$connector][1], 'c' => $connector]);
    $src = si_source_by_connector($pdo, $connector);
    $issuer = null;
    if (str_starts_with($connector, 'edgar_')) {
        $issuer = (int) (one_value($pdo, "SELECT id FROM issuers WHERE cik = '0001829311'") ?: 0);
        if ($issuer === 0) {
            $pdo->exec("INSERT INTO issuers (name, cik) VALUES ('BITMINE IMMERSION TECHNOLOGIES, INC.', '0001829311')");
            $issuer = (int) one_value($pdo, "SELECT id FROM issuers WHERE cik = '0001829311'");
            $pdo->exec("INSERT INTO securities (issuer_id, name, cusip, ticker, is_primary) VALUES ($issuer, 'Common Stock', '09175A107', 'BMNR', true)");
        }
    }
    $t0 = microtime(true);
    $r = si_run_source($pdo, $src, ['kind' => 'manual', 'issuer' => $issuer]);
    $pull = $pdo->query('SELECT http_requests, bytes, policy FROM source_pulls WHERE id = ' . (int) $r['pull_id'])->fetch();
    $pol = json_decode((string) $pull['policy'], true);
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
    if ($connector === 'dtcc_participants') {
        $n = (int) one_value($pdo, 'SELECT count(*) FROM participants');
        $ok($n > 800, "  the directory now holds $n participants");
    }
}
echo $fails ? "$fails FAILED ($passes passed)\n" : "all $passes passed\n";
exit($fails ? 1 : 0);
