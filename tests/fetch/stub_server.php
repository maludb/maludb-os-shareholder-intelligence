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
 *   /Archives/edgar/data/<cik>/<accession>/<file> samples/archives/<file>, else 404 (slice 4)
 *   /private/…                                    served only to a client that ignores robots (the proof checks it is never asked)
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
if ((str_starts_with($path, '/files/') || str_starts_with($path, '/submissions/') || str_starts_with($path, '/api/xbrl/') || str_starts_with($path, '/LATEST/') || str_starts_with($path, '/Archives/')) && !str_contains($ua, '@')) {
    $send(403, '<html><body>Your request has been identified as part of a network of automated tools outside of the acceptable policy.</body></html>', 'text/html');
}
if ($path === '/robots.txt') {
    $send(200, "User-agent: *\nDisallow: /private/\n", 'text/plain');
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
if (preg_match('~^/Archives/edgar/data/\d+/\d{18}/([A-Za-z0-9_.\-]+)$~', $path, $m)) {
    $f = $root . '/archives/' . $m[1];
    is_file($f) ? $send(200, (string) file_get_contents($f), str_ends_with($f, '.xml') ? 'application/xml' : 'text/html') : $send(404, 'not found', 'text/plain');
}
if (str_starts_with($path, '/private/')) {
    $send(200, 'you should not be reading this', 'text/plain');
}
$send(404, 'not found', 'text/plain');
