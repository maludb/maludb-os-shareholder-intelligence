<?php
/**
 * THE STUB of the public record (edgar-and-finra-fetchers.md §4) — `php -S 127.0.0.1:8905 tests/fetch/stub_server.php` — serving the fixtures
 * at the paths the connectors ask for, so a fetcher's proof never needs the network:
 *   /robots.txt                                   allows all but /private/ (a disallowed path for the robots proof)
 *   /support/dtc-directories                      the DTCC directory page, linking the participant listing (and the pledgees, never read)
 *   /download/assets/<name>.xlsx/<hash>           tests/fixtures/samples/dtc_alpha.xlsx
 *   /files/company_tickers_exchange.json          the SEC's ticker file (samples/company_tickers_exchange.json)
 *   /submissions/CIK##########.json               samples/submissions_CIK##########.json, else 404
 *   /submissions/CIK##########-submissions-001.json   the older page a submissions document names (slice 4)
 *   /api/xbrl/companyfacts/CIK##########.json     samples/companyfacts_CIK##########.json, else 404 (slice 4)
 *   /LATEST/search-index?q=&ciks=&…               samples/efts/<the term as a slug>.json (q without ciks: about-<slug>.json), else no hits (slice 4)
 *   /Archives/edgar/data/<cik>/<accession>/<file> samples/archives/<accession>/<file>, else samples/archives/<file>, else 404 (slices 4, 5)
 *   /data-research/sec-markets-data/form-13f-data-sets   the 13F data sets' page (samples/form13f/listing.html) (slice 5)
 *   /files/structureddata/data/form-13f-data-sets/<zip>  samples/form13f/<zip> (slice 5)
 *   POST /data/group/otcMarket/name/consolidatedShortInterest   FINRA's short interest: samples/short_interest_api.json filtered by the domain
 *                                                 filter's symbols; {"variant": "si_revised"} revises 2024-03-28 (530,000, flagged) (slice 6)
 *   POST /data/group/otcMarket/name/thresholdList  FINRA's OTC threshold list for the tradeDate: OTCX always, SMOK on the fixture's otc days (slice 6)
 *   /equity/regsho/daily/<CNMS|FORF>shvolYYYYMMDD.txt   the day's lines of tests/fixtures/CNMSshvol.txt (FORF: another symbol only) and
 *                                                 a trailer; 404 on a day the fixture has none (a holiday) (slice 6)
 *   /data-research/sec-markets-data/fails-deliver-data   the fails page listing the half-months of tests/fixtures/cnsfails.txt (slice 6)
 *   /files/data/fails-deliver-data/cnsfailsYYYYMM[ab].zip   that half's lines, another security's, and the trailer ({"variant": "ftd_bad_trailer"}
 *                                                 makes the trailer disagree) (slice 6)
 *   /dynamic/symdir/regsho/nasdaqthYYYYMMDD.txt   Nasdaq's list: OTHR every weekday, SMOK on tests/fixtures/threshold_days.csv's days (slice 6)
 *   /api/regulatory/threshold-securities/download?selectedDate=&market=   NYSE's list: NYSX on NYSE, an empty NYSE American (slice 6)
 *   /q/d/l/?s=smok.us&i=d                         stooq's CSV (tests/fixtures/prices_stooq.csv); {"variant": ["stooq_wall"]} its proof-of-work page (slice 6)
 *   /query?function=TIME_SERIES_DAILY&symbol=&apikey=   Alpha Vantage's JSON of the same bars, the key SMOKE-AV-KEY (slice 6)
 *   /v2/aggs/ticker/<T>/range/1/day/<from>/<to>    Polygon's JSON, the bearer SMOKE-PG-KEY (slice 6)
 *   /private/…                                    served only to a client that ignores robots (the proof checks it is never asked)
 * {"robots": "<the robots.txt>"} in $STUB_STATE replaces the robots answer (the stooq refusal proof).
 * The SEC's rule is enforced on /files/ and /submissions/: a User-Agent without an email answers 403. Every answer carries an ETag, and a
 * matching If-None-Match answers 304. $STUB_STATE (a JSON file) may force answers: {"force": {"/path": 429}} or {"force_all": 503}; $STUB_LOG
 * gets one JSON line per request (path, user-agent, status, time) — the proofs count requests and check the rate.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$root = dirname(__DIR__) . '/fixtures/samples';
$state = json_decode((string) @file_get_contents((string) getenv('STUB_STATE')), true) ?: [];
$log = static function (int $status) use ($path, $ua): void {
    if (($f = getenv('STUB_LOG')) !== false && $f !== '') {
        file_put_contents($f, json_encode(['path' => $path, 'ua' => $ua, 'status' => $status, 't' => microtime(true)]) . "\n", FILE_APPEND | LOCK_EX);
    }
};
$send = static function (int $status, string $body, string $type) use ($log): never {
    $etag = '"' . md5($body) . '"';
    if ($status === 200 && ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        $log(304);
        http_response_code(304);
        header('ETag: ' . $etag);
        exit;
    }
    $log($status);
    http_response_code($status);
    header('Content-Type: ' . $type);
    if ($status === 200) { header('ETag: ' . $etag); }
    echo $body;
    exit;
};
if (isset($state['force_all'])) {
    $send((int) $state['force_all'], 'forced', 'text/plain');
}
if (isset($state['force'][$path])) {
    $send((int) $state['force'][$path], 'forced', 'text/plain');
}
if ((str_starts_with($path, '/files/') || str_starts_with($path, '/submissions/') || str_starts_with($path, '/api/xbrl/') || str_starts_with($path, '/LATEST/') || str_starts_with($path, '/Archives/') || str_starts_with($path, '/data-research/')) && !str_contains($ua, '@')) {
    $send(403, '<html><body>Your request has been identified as part of a network of automated tools outside of the acceptable policy.</body></html>', 'text/html');
}
if ($path === '/robots.txt') {
    $send(200, isset($state['robots']) ? (string) $state['robots'] : "User-agent: *\nDisallow: /private/\n", 'text/plain');
}
// ---- slice 6: the short side and prices, from the CSV/TSV fixtures (tests/fixtures/)
$fx = dirname(__DIR__) . '/fixtures';
$variant = (array) ($state['variant'] ?? []);
$variant = array_is_list($variant) ? $variant : array_keys(array_filter($variant));
$smokeDays = static function () use ($fx): array {                    // the fixture's trading days (CNMS lines), YYYYMMDD
    $d = [];
    foreach (array_slice(file($fx . '/CNMSshvol.txt', FILE_IGNORE_NEW_LINES), 1) as $l) { $d[explode('|', $l)[0]] = true; }
    return $d;
};
$thDays = static function (string $list) use ($fx): array {
    $d = [];
    foreach (array_slice(file($fx . '/threshold_days.csv', FILE_IGNORE_NEW_LINES), 1) as $l) { $c = str_getcsv($l, ',', '"', ''); if (($c[1] ?? '') === $list) { $d[str_replace('-', '', $c[0])] = true; } }
    return $d;
};
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/data/group/otcMarket/name/consolidatedShortInterest') {
    $req = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $want = array_map('strtoupper', (array) ($req['domainFilters'][0]['values'] ?? []));
    $rows = array_values(array_filter(json_decode((string) file_get_contents($root . '/short_interest_api.json'), true), static fn ($r) => in_array($r['symbolCode'], $want, true)));
    if (in_array('si_revised', $variant, true)) {
        foreach ($rows as &$r) { if ($r['settlementDate'] === '2024-03-28') { $r['currentShortPositionQuantity'] = 530000; $r['daysToCoverQuantity'] = 11.28; $r['changePreviousNumber'] = 120000; $r['revisionFlag'] = 'Y'; } }
        unset($r);
    }
    $rows = array_slice($rows, (int) ($req['offset'] ?? 0), (int) ($req['limit'] ?? 5000));
    header('record-total: ' . count($rows));
    $send(200, json_encode($rows), 'application/json');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/data/group/otcMarket/name/thresholdList') {
    $req = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $date = (string) ($req['compareFilters'][0]['fieldValue'] ?? '');
    $ymd = str_replace('-', '', $date);
    $rows = [];
    if (isset($smokeDays()[$ymd])) {
        $rows[] = ['tradeDate' => $date, 'issueSymbolIdentifier' => 'OTCX', 'issueName' => 'OTHER OTC CO', 'regShoThresholdFlag' => 'N', 'rule4320Flag' => 'Y', 'thresholdListFlag' => 'NR', 'marketClassCode' => 'OTC'];
        if (isset($thDays('otc')[$ymd])) { $rows[] = ['tradeDate' => $date, 'issueSymbolIdentifier' => 'SMOK', 'issueName' => 'SMOKE ISSUER INC', 'regShoThresholdFlag' => 'Y', 'rule4320Flag' => 'N', 'thresholdListFlag' => 'R', 'marketClassCode' => 'OTC']; }
    }
    header('record-total: ' . count($rows));
    $send(200, json_encode($rows), 'application/json');
}
if (preg_match('~^/equity/regsho/daily/(CNMS|FORF)shvol(\d{8})\.txt$~', $path, $m)) {
    if (!isset($smokeDays()[$m[2]])) { $send(404, '<?xml version="1.0"?><Error><Code>NoSuchKey</Code></Error>', 'application/xml'); }
    $out = ["Date|Symbol|ShortVolume|ShortExemptVolume|TotalVolume|Market"];
    $out[] = $m[2] . '|AAAA|1000.5|0|2000.25|B,Q,N';
    if ($m[1] === 'CNMS') { foreach (file($fx . '/CNMSshvol.txt', FILE_IGNORE_NEW_LINES) as $l) { if (str_starts_with($l, $m[2] . '|')) { $out[] = $l; } } }
    $out[] = (string) (count($out) - 1);
    $send(200, implode("\r\n", $out) . "\r\n", 'text/plain');
}
if ($path === '/data-research/sec-markets-data/fails-deliver-data') {
    $halves = [];
    foreach (array_slice(file($fx . '/cnsfails.txt', FILE_IGNORE_NEW_LINES), 1) as $l) { $d = explode('|', $l)[0]; $halves[substr($d, 0, 6) . ((int) substr($d, 6, 2) <= 15 ? 'a' : 'b')] = true; }
    krsort($halves);
    $links = implode('', array_map(static fn ($h) => '<li><a href="/files/data/fails-deliver-data/cnsfails' . $h . '.zip">cnsfails' . $h . '.zip</a></li>', array_keys($halves)));
    $send(200, '<html><body><h1>Fails-to-Deliver Data</h1><ul>' . $links . '</ul></body></html>', 'text/html');
}
if (preg_match('~^/files/data/fails-deliver-data/cnsfails(\d{6})([ab])\.zip$~', $path, $m)) {
    $lines = ['SETTLEMENT DATE|CUSIP|SYMBOL|QUANTITY (FAILS)|DESCRIPTION|PRICE'];
    $sum = 0;
    foreach (array_slice(file($fx . '/cnsfails.txt', FILE_IGNORE_NEW_LINES), 1) as $l) {
        $d = explode('|', $l)[0];
        if (substr($d, 0, 6) === $m[1] && (((int) substr($d, 6, 2) <= 15) === ($m[2] === 'a'))) { $lines[] = $l; $sum += (int) explode('|', $l)[3]; }
    }
    if (count($lines) === 1) { $send(404, 'not found', 'text/plain'); }
    $lines[] = $m[1] . ($m[2] === 'a' ? '05' : '20') . '|00000X100|OTHR|777|OTHER CO|1.00';
    $sum += 777;
    $lines[] = 'Trailer record count ' . (count($lines) - 1 + (in_array('ftd_bad_trailer', $variant, true) ? 5 : 0));
    $lines[] = 'Trailer total quantity of shares ' . $sum;
    $tmp = tempnam(sys_get_temp_dir(), 'stub-ftd');
    $z = new ZipArchive();
    $z->open($tmp, ZipArchive::OVERWRITE);
    $z->addFromString('cnsfails' . $m[1] . $m[2] . '.txt', implode("\r\n", $lines) . "\r\n");
    $z->close();
    $bytes = (string) file_get_contents($tmp);
    unlink($tmp);
    $send(200, $bytes, 'application/zip');
}
if (preg_match('~^/dynamic/symdir/regsho/nasdaqth(\d{8})\.txt$~', $path, $m)) {
    if (!isset($smokeDays()[$m[1]])) { $send(404, '<html><body>File you have requested was not found on this server</body></html>', 'text/html'); }
    $out = ['Symbol|Security Name|Market Category|Reg SHO Threshold Flag|Rule 3210|Filler', 'OTHR|OTHER CO - Common Stock|Q|Y|N|'];
    if (isset($thDays('nasdaq')[$m[1]])) { $out[] = 'SMOK|SMOKE ISSUER INC - Common Stock|S|Y|N|'; }
    $out[] = $m[1] . '230024';
    $send(200, implode("\r\n", $out) . "\r\n", 'text/plain');
}
if ($path === '/api/regulatory/threshold-securities/download') {
    $ymd = str_replace('-', '', (string) ($_GET['selectedDate'] ?? ''));
    $market = (string) ($_GET['market'] ?? '');
    $out = ['Symbol|Security Name|Market Category|Reg SHO Threshold Flag|Filler|Filler'];
    if ($market === 'NYSE' && isset($smokeDays()[$ymd])) { $out[] = 'NYSX|NYSE Other Inc.|NYSE|Y||'; }
    if (isset($smokeDays()[$ymd])) { $out[] = $ymd . '220201'; }
    $send(200, implode("\r\n", $out) . "\r\n", 'text/plain; charset=UTF-8');
}
$bars = static function () use ($fx): array {
    $b = [];
    foreach (array_slice(file($fx . '/prices_stooq.csv', FILE_IGNORE_NEW_LINES), 1) as $l) { $b[] = str_getcsv($l, ',', '"', ''); }
    return $b;
};
if ($path === '/q/d/l/') {
    if (in_array('stooq_wall', $variant, true)) {                          // stooq's proof-of-work page, as the live host answered 2026-10-10
        $send(200, '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><noscript>This site requires JavaScript to verify your browser. Please enable JavaScript and reload.</noscript><script>(async()=>{const h=await crypto.subtle.digest("SHA-256",new TextEncoder().encode("x"));await fetch("/__verify",{method:"POST"})})();</script></body></html>', 'text/html; charset=utf-8');
    }
    strtolower((string) ($_GET['s'] ?? '')) === 'smok.us' ? $send(200, (string) file_get_contents($fx . '/prices_stooq.csv'), 'text/csv') : $send(200, 'No data', 'text/plain');
}
if ($path === '/query') {
    // the answers' words as the live host gave them (2026-10-11): a missing or wrong key, the day's limit, full as premium, an unknown symbol
    if (($_GET['apikey'] ?? '') !== 'SMOKE-AV-KEY') { $send(200, json_encode(['Error Message' => 'the parameter apikey is invalid or missing. Please claim your free API key on (https://www.alphavantage.co/support/#api-key). It should take less than 20 seconds.']), 'application/json'); }
    if (in_array('av_limit', $variant, true)) { $send(200, json_encode(['Information' => 'We have detected your API key as SMOKE-AV-KEY and our standard API rate limit is 25 requests per day. Please subscribe to any of the premium plans at https://www.alphavantage.co/premium/ to instantly remove all daily rate limits.']), 'application/json'); }
    if (($_GET['outputsize'] ?? '') === 'full') { $send(200, json_encode(['Information' => 'Thank you for using Alpha Vantage! The outputsize=full parameter value is a premium feature for the TIME_SERIES_DAILY endpoint. You may subscribe to any of the premium plans at https://www.alphavantage.co/premium/ to instantly unlock all premium features']), 'application/json'); }
    if (!in_array(strtoupper((string) ($_GET['symbol'] ?? '')), ['SMOK', 'IBM'], true)) { $send(200, json_encode(['Error Message' => 'Invalid API call. Please retry or visit the documentation (https://www.alphavantage.co/documentation/) for TIME_SERIES_DAILY.']), 'application/json'); }
    $ts = [];
    foreach (array_reverse($bars()) as [$d, $o, $h, $l, $c, $v]) { $ts[$d] = ['1. open' => $o, '2. high' => $h, '3. low' => $l, '4. close' => $c, '5. volume' => $v]; }
    $send(200, json_encode(['Meta Data' => ['2. Symbol' => $_GET['symbol'] ?? ''], 'Time Series (Daily)' => $ts]), 'application/json');
}
if (preg_match('~^/v2/aggs/ticker/([A-Z0-9.]+)/range/1/day/(\d{4}-\d{2}-\d{2})/(\d{4}-\d{2}-\d{2})$~', $path, $m)) {
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer SMOKE-PG-KEY') { $send(401, json_encode(['status' => 'ERROR', 'error' => 'API Key was not provided']), 'application/json'); }
    $res = [];
    foreach ($bars() as [$d, $o, $h, $l, $c, $v]) { if ($d >= $m[2] && $d <= $m[3]) { $res[] = ['t' => (strtotime($d . ' 16:00:00 America/New_York')) * 1000, 'o' => (float) $o, 'h' => (float) $h, 'l' => (float) $l, 'c' => (float) $c, 'v' => (float) $v]; } }
    $send(200, json_encode(['ticker' => $m[1], 'resultsCount' => count($res), 'results' => $res, 'status' => 'OK']), 'application/json');
}
if ($path === '/support/dtc-directories') {
    $send(200, '<html><body><h1>DTC Directories</h1><ul>'
        . '<li><a href="/download/assets/DTC+Participant+in+Alphabetical+Listing+-+09.30.2026.xlsx/21355760a87911f1855dc6529a108655">Participant listing (alphabetical)</a></li>'
        . '<li><a href="/download/assets/DTC+Pledgees+-+09.30.2026.xlsx/2134da9ca87911f193251ece5e267a85">Pledgees</a></li></ul></body></html>', 'text/html; charset=utf-8');
}
if (preg_match('~^/download/assets/DTC\+Participant[^/]*\.xlsx/[0-9a-f]+$~', $path)) {
    $send(200, (string) file_get_contents($root . '/dtc_alpha.xlsx'), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
}
if ($path === '/files/company_tickers_exchange.json') {
    $send(200, (string) file_get_contents($root . '/company_tickers_exchange.json'), 'application/json');
}
if (preg_match('~^/submissions/CIK(\d{10})\.json$~', $path, $m)) {
    $f = $root . '/submissions_CIK' . $m[1] . '.json';
    is_file($f) ? $send(200, (string) file_get_contents($f), 'application/json') : $send(404, '{"message":"Not found"}', 'application/json');
}
if (preg_match('~^/submissions/(CIK\d{10}-submissions-\d{3}\.json)$~', $path, $m)) {
    $f = $root . '/' . $m[1];
    is_file($f) ? $send(200, (string) file_get_contents($f), 'application/json') : $send(404, '{"message":"Not found"}', 'application/json');
}
if (preg_match('~^/api/xbrl/companyfacts/CIK(\d{10})\.json$~', $path, $m)) {
    $f = $root . '/companyfacts_CIK' . $m[1] . '.json';
    is_file($f) ? $send(200, (string) file_get_contents($f), 'application/json') : $send(404, '{"message":"Not found"}', 'application/json');
}
if ($path === '/LATEST/search-index') {
    $q = strtolower(trim((string) ($_GET['q'] ?? ''), '" '));
    $slug = (empty($_GET['ciks']) ? 'about-' : '') . trim((string) preg_replace('~[^a-z0-9]+~', '-', $q), '-');
    $f = $root . '/efts/' . $slug . '.json';
    $send(200, is_file($f) && (int) ($_GET['from'] ?? 0) === 0 ? (string) file_get_contents($f) : '{"took":1,"timed_out":false,"hits":{"total":{"value":0,"relation":"eq"},"hits":[]}}', 'application/json');
}
if ($path === '/data-research/sec-markets-data/form-13f-data-sets') {
    $send(200, (string) file_get_contents($root . '/form13f/listing.html'), 'text/html');
}
if (preg_match('~^/files/structureddata/data/form-13f-data-sets/([A-Za-z0-9_\-]+\.zip)$~', $path, $m)) {
    $f = $root . '/form13f/' . $m[1];
    is_file($f) ? $send(200, (string) file_get_contents($f), 'application/zip') : $send(404, 'not found', 'text/plain');
}
if (preg_match('~^/Archives/edgar/data/\d+/(\d{18})/([A-Za-z0-9_.\-]+)$~', $path, $m)) {
    $f = is_file($root . '/archives/' . $m[1] . '/' . $m[2]) ? $root . '/archives/' . $m[1] . '/' . $m[2] : $root . '/archives/' . $m[2];
    is_file($f) ? $send(200, (string) file_get_contents($f), str_ends_with($f, '.xml') ? 'application/xml' : 'text/html') : $send(404, 'not found', 'text/plain');
}
if (str_starts_with($path, '/private/')) {
    $send(200, 'you should not be reading this', 'text/plain');
}
$send(404, 'not found', 'text/plain');
