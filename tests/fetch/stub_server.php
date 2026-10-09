<?php
/**
 * THE STUB of the public record (edgar-and-finra-fetchers.md §4) — `php -S 127.0.0.1:8905 tests/fetch/stub_server.php` — serving the fixtures
 * at the paths the connectors ask for, so a fetcher's proof never needs the network:
 *   /robots.txt                                   allows all but /private/ (a disallowed path for the robots proof)
 *   /support/dtc-directories                      the DTCC directory page, linking the participant listing (and the pledgees, never read)
 *   /download/assets/<name>.xlsx/<hash>           tests/fixtures/samples/dtc_alpha.xlsx
 *   /files/company_tickers_exchange.json          the SEC's ticker file (samples/company_tickers_exchange.json)
 *   /submissions/CIK##########.json               samples/submissions_CIK##########.json, else 404
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
if ((str_starts_with($path, '/files/') || str_starts_with($path, '/submissions/')) && !str_contains($ua, '@')) {
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
if (str_starts_with($path, '/private/')) {
    $send(200, 'you should not be reading this', 'text/plain');
}
$send(404, 'not found', 'text/plain');
