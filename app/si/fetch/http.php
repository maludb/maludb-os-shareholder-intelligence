<?php
declare(strict_types=1);

/**
 * The ONE outbound HTTP client (docs/build-specs/edgar-and-finra-fetchers.md §1) — Inventory's app/sources/http.php ported verbatim but for
 * its names (inv_ → si_, InvHttp → SiHttp, INV_ → SI_): every fetcher of the public record goes through it, so the policy is in one place:
 *   - an honest User-Agent (EDGAR's "<business name> <contact email>" from si_settings; the others the source's or the business's);
 *   - robots.txt fetched once per host and honoured (a disallowed path is SKIPPED, never fetched; Crawl-delay raises the wait);
 *   - one request at a time per host (a lock file) at most rate_per_second (EDGAR 2, never above the SEC's 10), across processes;
 *   - ETag / Last-Modified caching in a file cache (a 304 answers from the cache and counts as `cached`);
 *   - timeouts, a byte ceiling, at most five redirects — never one to a login page (→ blocked);
 *   - a 403, a 429 or a bot wall → blocked (the source backs off an hour: si_pull_finish);
 *   - SI_HTTP_PROXY honoured when set (one proxy the business runs — never a pool);
 *   - every request appended to the client's facts (host, path, status, bytes, cached, blocked, ms) for the pull's policy.
 * The transport (cURL) is injectable: tests pass a callable that answers from fixtures without the network.
 *
 * A response: ['ok' => bool, 'status' => int, 'headers' => [lower => string], 'body' => string, 'bytes' => int,
 *              'cached' => bool, 'blocked' => bool, 'skipped' => bool, 'reason' => ?string, 'url' => final URL,
 *              'elapsed_ms' => int]
 */

require_once __DIR__ . '/robots.php';

final class SiHttp
{
    public const DEFAULT_USER_AGENT = 'MaluDB-Inventory/0.1 (+no contact configured; set SI_CRAWL_USER_AGENT)';
    public const LOGIN_PATH = '~^/(account/login|account/signin|account/sign-in|login|signin|sign-in|sign_in|customer(s)?(/|$)|customer/account|users/sign_in|user/login|auth/login|wp-login\.php|my-account)(/|\?|$)?~i';

    /** @var callable(array): array */
    private $transport;
    private string $userAgent;
    private float $rate;
    private string $cacheDir;
    private int $timeout;
    private int $maxBytes;
    private ?string $proxy;
    private bool $honourRobots;
    private array $extraHeaders;
    private array $robots = [];     // host => ['robots' => parsed|null, 'state' => ok|none|blocked|error, 'crawl_delay' => ?float]
    private array $requests = [];
    private int $bytes = 0;
    private int $cached = 0;
    private int $blocked = 0;
    private int $robotsSkipped = 0;

    public function __construct(array $opts = [])
    {
        $this->transport = $opts['transport'] ?? si_http_curl_transport();
        $this->userAgent = si_http_user_agent($opts);
        $rate = (float) ($opts['rate_per_second'] ?? 1);
        $this->rate = $rate > 0 ? $rate : 1.0;
        $this->cacheDir = rtrim((string) ($opts['cache_dir'] ?? si_http_default_cache_dir()), '/');
        $this->timeout = max(1, (int) ($opts['timeout'] ?? 20));
        $this->maxBytes = max(1024, (int) ($opts['max_bytes'] ?? 8 * 1024 * 1024));
        $proxy = $opts['proxy'] ?? (getenv('SI_HTTP_PROXY') ?: null);
        $this->proxy = is_string($proxy) && trim($proxy) !== '' ? trim($proxy) : null;
        $this->honourRobots = (bool) ($opts['robots'] ?? true);
        $this->extraHeaders = is_array($opts['headers'] ?? null) ? $opts['headers'] : [];
    }

    public function userAgent(): string
    {
        return $this->userAgent;
    }

    public function get(string $url, array $opts = []): array
    {
        return $this->request('GET', $url, null, $opts);
    }

    public function post(string $url, string $body, array $opts = []): array
    {
        return $this->request('POST', $url, $body, $opts);
    }

    /** The policy facts of everything this client did (design §6 source_pulls.policy). */
    public function facts(): array
    {
        $robots = [];
        foreach ($this->robots as $host => $r) {
            $robots[$host] = ['state' => $r['state'], 'crawl_delay' => $r['crawl_delay']];
        }
        return [
            'user_agent' => $this->userAgent,
            'rate_per_second' => $this->rate,
            'proxy' => $this->proxy !== null,
            'robots' => $robots,
            'http_requests' => count(array_filter($this->requests, static fn($r) => !$r['skipped'])),
            'bytes' => $this->bytes,
            'cached' => $this->cached,
            'blocked' => $this->blocked,
            'robots_skipped' => $this->robotsSkipped,
            'requests' => $this->requests,
        ];
    }

    /** The parsed robots.txt of a URL's host (fetched once per host per client). */
    public function robotsFor(string $url): array
    {
        $p = parse_url($url);
        $host = strtolower((string) ($p['host'] ?? ''));
        if ($host === '') {
            return ['robots' => null, 'state' => 'none', 'crawl_delay' => null];
        }
        if (!isset($this->robots[$host])) {
            $this->robots[$host] = ['robots' => null, 'state' => 'pending', 'crawl_delay' => null];
            $scheme = strtolower((string) ($p['scheme'] ?? 'https'));
            $port = isset($p['port']) ? ':' . $p['port'] : '';
            $resp = $this->request('GET', "$scheme://$host$port/robots.txt", null, ['robots' => false, 'is_robots' => true]);
            if ($resp['blocked']) {
                $this->robots[$host] = ['robots' => ['groups' => ['*' => ['allow' => [], 'disallow' => ['/'], 'crawl_delay' => null]], 'sitemaps' => []], 'state' => 'blocked', 'crawl_delay' => null];
            } elseif ($resp['status'] === 200 && !preg_match('~<html~i', substr($resp['body'], 0, 512))) {
                $parsed = si_robots_parse($resp['body']);
                $this->robots[$host] = ['robots' => $parsed, 'state' => 'ok', 'crawl_delay' => si_robots_crawl_delay($parsed, $this->userAgent)];
            } elseif ($resp['status'] >= 500 || $resp['status'] === 0) {
                $this->robots[$host] = ['robots' => null, 'state' => 'error', 'crawl_delay' => null];
            } else {
                $this->robots[$host] = ['robots' => null, 'state' => 'none', 'crawl_delay' => null];
            }
        }
        return $this->robots[$host];
    }

    /** Does robots.txt let us fetch this URL? */
    /**
     * The hosts that are APIs or file stores for machines, not sites: robots.txt does not govern them (edgar-and-finra-fetchers.md §1 — efts.sec.gov
     * answers its robots.txt 403; cdn.finra.org, FINRA's Reg SHO daily files, is an S3 bucket whose robots.txt answers S3's "AccessDenied" 403 for a
     * file that does not exist — RFC 9309 reads a 4xx robots answer as no rules, found by slice 6's live pull 2026-10-11).
     */
    public const API_HOSTS = ['data.sec.gov', 'efts.sec.gov', 'api.finra.org', 'cdn.finra.org'];

    public function allows(string $url): bool
    {
        if (in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), self::API_HOSTS, true)) {
            return true;
        }
        $r = $this->robotsFor($url);
        if ($r['robots'] === null) {
            return $r['state'] !== 'blocked';
        }
        $p = parse_url($url);
        $path = (string) ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
        return si_robots_allows($r['robots'], $path, $this->userAgent);
    }

    private function request(string $method, string $url, ?string $body, array $opts): array
    {
        $t0 = microtime(true);
        $honourRobots = $opts['robots'] ?? $this->honourRobots;
        $isRobots = (bool) ($opts['is_robots'] ?? false);
        $hops = 0;
        $current = $url;
        while (true) {
            $p = parse_url($current);
            $host = strtolower((string) ($p['host'] ?? ''));
            $path = (string) ($p['path'] ?? '/');
            if ($host === '' || !in_array(strtolower((string) ($p['scheme'] ?? '')), ['http', 'https'], true)) {
                return $this->done($t0, $current, $host, $path, ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'bad_url'], false, false, 'bad_url');
            }
            if ($hops > 0 && preg_match(self::LOGIN_PATH, $path)) {
                return $this->done($t0, $current, $host, $path, ['status' => 0, 'headers' => [], 'body' => '', 'error' => null], false, true, 'login_redirect');
            }
            if ($honourRobots && !$isRobots && !$this->allows($current)) {
                $this->robotsSkipped++;
                return $this->done($t0, $current, $host, $path, ['status' => 0, 'headers' => [], 'body' => '', 'error' => null], false, false, 'robots_disallow', true);
            }
            $cacheKey = $method === 'GET' && empty($opts['no_cache']) ? $this->cacheKey($current) : null;   // no_cache: a large file (a 13F data set) is not kept twice
            $cachedEntry = $cacheKey !== null ? $this->cacheRead($cacheKey) : null;
            $headers = array_merge([
                'User-Agent' => $this->userAgent,
                'Accept' => $opts['accept'] ?? 'application/json, text/html;q=0.9, application/xml;q=0.8, */*;q=0.5',
                'Accept-Language' => 'en',
            ], $this->extraHeaders, is_array($opts['headers'] ?? null) ? $opts['headers'] : []);
            if ($cachedEntry !== null) {
                if ($cachedEntry['etag'] !== null) {
                    $headers['If-None-Match'] = $cachedEntry['etag'];
                }
                if ($cachedEntry['last_modified'] !== null) {
                    $headers['If-Modified-Since'] = $cachedEntry['last_modified'];
                }
            }
            if ($body !== null) {
                $headers['Content-Type'] = $opts['content_type'] ?? 'application/json';
            }
            $this->waitForHost($host, function () use ($method, $current, $headers, $body, $opts, &$resp) {
                $resp = ($this->transport)([
                    'method' => $method, 'url' => $current, 'headers' => $headers, 'body' => $body,
                    'timeout' => (int) ($opts['timeout'] ?? $this->timeout), 'max_bytes' => (int) ($opts['max_bytes'] ?? $this->maxBytes),
                    'proxy' => $this->proxy,
                ]);
            });
            $resp = si_http_response_shape($resp);
            $status = $resp['status'];
            if ($status === 304 && $cachedEntry !== null) {
                $resp['body'] = $cachedEntry['body'];
                $resp['headers'] = array_merge($cachedEntry['headers'], $resp['headers']);
                $resp['status'] = 200;
                return $this->done($t0, $current, $host, $path, $resp, true, false, null);
            }
            if (in_array($status, [301, 302, 303, 307, 308], true) && isset($resp['headers']['location'])) {
                $this->record($host, $path, $status, strlen($resp['body']), false, false, 'redirect', $t0);
                if (++$hops > 5) {
                    return $this->done($t0, $current, $host, $path, $resp, false, false, 'too_many_redirects', false, false);
                }
                $current = si_http_resolve($current, $resp['headers']['location']);
                if (in_array($status, [301, 302, 303], true)) {
                    $method = 'GET';
                    $body = null;
                }
                continue;
            }
            $blockedReason = si_http_blocked_reason($status, $resp['headers'], $resp['body']);
            if ($blockedReason !== null) {
                return $this->done($t0, $current, $host, $path, $resp, false, true, $blockedReason);
            }
            if ($cacheKey !== null && $status === 200 && $resp['error'] === null) {
                $this->cacheWrite($cacheKey, $resp);
            }
            return $this->done($t0, $current, $host, $path, $resp, false, false, $resp['error'] !== null ? 'transport:' . $resp['error'] : null);
        }
    }

    private function done(float $t0, string $url, string $host, string $path, array $resp, bool $cached, bool $blocked, ?string $reason, bool $skipped = false, bool $record = true): array
    {
        $bytes = $cached || $skipped ? 0 : strlen($resp['body']);
        if ($record) {
            $this->record($host, $path, $resp['status'], $bytes, $cached, $blocked, $reason, $t0, $skipped);
        }
        return [
            'ok' => !$blocked && !$skipped && $resp['status'] >= 200 && $resp['status'] < 300 && ($resp['error'] ?? null) === null,
            'status' => $resp['status'],
            'headers' => $resp['headers'],
            'body' => $skipped ? '' : $resp['body'],
            'bytes' => $bytes,
            'cached' => $cached,
            'blocked' => $blocked,
            'skipped' => $skipped,
            'reason' => $reason,
            'url' => $url,
            'elapsed_ms' => (int) round((microtime(true) - $t0) * 1000),
        ];
    }

    private function record(string $host, string $path, int $status, int $bytes, bool $cached, bool $blocked, ?string $reason, float $t0, bool $skipped = false): void
    {
        $this->requests[] = ['host' => $host, 'path' => $path, 'status' => $status, 'bytes' => $bytes, 'cached' => $cached, 'blocked' => $blocked, 'skipped' => $skipped, 'reason' => $reason, 'ms' => (int) round((microtime(true) - $t0) * 1000)];
        $this->bytes += $bytes;
        if ($cached) {
            $this->cached++;
        }
        if ($blocked) {
            $this->blocked++;
        }
    }

    /** One request at a time per host, spaced by max(1/rate, Crawl-delay): a lock file carries the last request's time across processes. */
    private function waitForHost(string $host, callable $do): void
    {
        $wait = 1.0 / $this->rate;
        $delay = $this->robots[$host]['crawl_delay'] ?? null;
        if ($delay !== null && $delay > $wait) {
            $wait = min((float) $delay, 60.0);
        }
        $dir = $this->cacheDir . '/hosts';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $lockPath = $dir . '/' . preg_replace('~[^a-z0-9.\-]~', '_', $host) . '.lock';
        $fh = @fopen($lockPath, 'c+');
        if ($fh === false) {
            $do();
            return;
        }
        try {
            flock($fh, LOCK_EX);
            $last = (float) trim((string) stream_get_contents($fh));
            $sleep = $last + $wait - microtime(true);
            if ($sleep > 0) {
                usleep((int) ceil($sleep * 1_000_000));
            }
            $do();
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, sprintf('%.6f', microtime(true)));
            fflush($fh);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private function cacheKey(string $url): string
    {
        return sha1($this->userAgent . "\n" . $url);
    }

    private function cachePath(string $key): string
    {
        return $this->cacheDir . '/' . substr($key, 0, 2) . '/' . $key;
    }

    private function cacheRead(string $key): ?array
    {
        $base = $this->cachePath($key);
        if (!is_file($base . '.meta.json') || !is_file($base . '.body')) {
            return null;
        }
        $meta = json_decode((string) file_get_contents($base . '.meta.json'), true);
        if (!is_array($meta)) {
            return null;
        }
        return ['etag' => $meta['etag'] ?? null, 'last_modified' => $meta['last_modified'] ?? null, 'headers' => $meta['headers'] ?? [], 'body' => (string) file_get_contents($base . '.body')];
    }

    private function cacheWrite(string $key, array $resp): void
    {
        $etag = $resp['headers']['etag'] ?? null;
        $lm = $resp['headers']['last-modified'] ?? null;
        if ($etag === null && $lm === null) {
            return;
        }
        $base = $this->cachePath($key);
        $dir = dirname($base);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            return;
        }
        $keep = array_intersect_key($resp['headers'], array_flip(['content-type', 'etag', 'last-modified', 'x-wp-totalpages', 'x-wp-total']));
        @file_put_contents($base . '.body', $resp['body'], LOCK_EX);
        @file_put_contents($base . '.meta.json', json_encode(['etag' => $etag, 'last_modified' => $lm, 'headers' => $keep, 'stored_at' => time()]), LOCK_EX);
    }
}

/** The default cache directory: <SI_STORAGE_DIR>/http-cache, else storage/http-cache under the application (created when missing). */
function si_http_default_cache_dir(): string
{
    $root = function_exists('env') ? (string) env('SI_STORAGE_DIR', '') : '';
    $dir = ($root !== '' ? rtrim($root, '/') : dirname(__DIR__, 3) . '/storage') . '/http-cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    return $dir;
}

/** The honest user-agent: the source's override, else SI_CRAWL_USER_AGENT, else a default that says it is unconfigured. */
function si_http_user_agent(array $opts): string
{
    $ua = $opts['user_agent'] ?? null;
    if (!is_string($ua) || trim($ua) === '') {
        $ua = getenv('SI_CRAWL_USER_AGENT') ?: null;
        if ($ua === null && function_exists('env')) {
            $ua = env('SI_CRAWL_USER_AGENT');
        }
    }
    $ua = is_string($ua) ? trim(preg_replace('~[\r\n]+~', ' ', $ua) ?? '') : '';
    return $ua === '' ? SiHttp::DEFAULT_USER_AGENT : $ua;
}

/** A client for a source row (user_agent, rate_per_second, cache_dir and transport from the row when a test set them). */
function si_http_client(array $source = [], array $opts = []): SiHttp
{
    return new SiHttp(array_merge([
        'user_agent' => $source['user_agent'] ?? null,
        'rate_per_second' => $source['rate_per_second'] ?? 1,
        'cache_dir' => $source['cache_dir'] ?? null,
        'transport' => $source['transport'] ?? null,
        'timeout' => $source['timeout'] ?? 20,
    ], $opts));
}

/** The function the specification names: one GET through the policy. opts['client'] (an SiHttp) or a default client. */
function si_http_get(string $url, array $opts = []): array
{
    static $default = null;
    $client = $opts['client'] ?? null;
    if (!$client instanceof SiHttp) {
        $default ??= new SiHttp();
        $client = $default;
    }
    unset($opts['client']);
    return $client->get($url, $opts);
}

/** Resolve a (possibly relative) Location against the request URL. */
function si_http_resolve(string $base, string $location): string
{
    $location = trim($location);
    if (preg_match('~^https?://~i', $location)) {
        return $location;
    }
    $p = parse_url($base);
    $origin = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    if (str_starts_with($location, '//')) {
        return ($p['scheme'] ?? 'https') . ':' . $location;
    }
    if (str_starts_with($location, '/')) {
        return $origin . $location;
    }
    $dir = rtrim(dirname($p['path'] ?? '/'), '/');
    return $origin . $dir . '/' . $location;
}

/** A transport's answer, made whole: status int, headers lower-cased strings, body string, error ?string. */
function si_http_response_shape(mixed $resp): array
{
    if (!is_array($resp)) {
        return ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'transport_returned_nothing'];
    }
    $headers = [];
    foreach ((array) ($resp['headers'] ?? []) as $k => $v) {
        $headers[strtolower((string) $k)] = is_array($v) ? implode(', ', $v) : (string) $v;
    }
    return ['status' => (int) ($resp['status'] ?? 0), 'headers' => $headers, 'body' => (string) ($resp['body'] ?? ''), 'error' => isset($resp['error']) && $resp['error'] !== '' ? (string) $resp['error'] : null];
}

/** Why a response marks the source blocked: http_403, http_429, or a bot wall's name; null when it does not. */
function si_http_blocked_reason(int $status, array $headers, string $body): ?string
{
    if ($status === 403) {
        return 'http_403';
    }
    if ($status === 429) {
        // a sandbox that rate-limits outbound HTTP answers 429 "local_rate_limited" itself — named so a survey never
        // records it as the site's answer
        return trim($body) === 'local_rate_limited' ? 'http_429:local_rate_limited' : 'http_429';
    }
    $ct = strtolower($headers['content-type'] ?? '');
    $looksHtml = str_contains($ct, 'html') || ($ct === '' && preg_match('~<html|<!doctype~i', substr($body, 0, 1024)));
    if (!$looksHtml || strlen($body) > 512 * 1024) {
        return null;
    }
    $head = substr($body, 0, 65536);
    $patterns = [
        'bot_wall:cloudflare' => '~<title>\s*Just a moment\.{0,3}\s*</title>|cf-browser-verification|cf_chl_|challenge-platform|Checking your browser before accessing|cf-challenge~i',
        'bot_wall:perimeterx' => '~px-captcha|_pxhd|perimeterx|Press (&amp;|&) Hold to confirm you are~i',
        'bot_wall:akamai' => '~errors\.edgesuite\.net|Reference&#32;&#35;|<title>\s*Access Denied\s*</title>~i',
        'bot_wall:imperva' => '~Pardon Our Interruption|Incapsula incident~i',   // _Incapsula_Resource alone is on every page of an Imperva site (below)
        'bot_wall:datadome' => '~datadome|captcha-delivery\.com~i',
        'bot_wall:js_challenge' => '~requires JavaScript to verify your browser|crypto\.subtle\.digest[^<]{0,400}/__verify~i',   // a proof-of-work page (stooq, 2026-10-10)
        'bot_wall:captcha' => '~<title>[^<]*(Access denied|Verify you are human|Are you a robot|Bot Verification|Security Check)[^<]*</title>|(g-recaptcha|h-captcha)[^<]{0,200}(verify|human|robot)~i',
    ];
    foreach ($patterns as $name => $re) {
        if (preg_match($re, $head)) {
            return $name;
        }
    }
    if (strlen($body) < 8192 && stripos($head, '_Incapsula_Resource') !== false) {
        return 'bot_wall:imperva';                                      // a challenge is a near-empty page carrying only Imperva's script (Nasdaq's ordinary pages carry it too, 2026-10-11)
    }
    if ($status === 503 && preg_match('~cloudflare~i', $head)) {
        return 'bot_wall:cloudflare';
    }
    return null;
}

/** The cURL transport: no automatic redirects (the client judges each hop), a byte ceiling, gzip accepted. */
function si_http_curl_transport(): callable
{
    return static function (array $req): array {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'curl_missing'];
        }
        $ch = curl_init();
        $headers = [];
        $body = '';
        $max = (int) $req['max_bytes'];
        $tooLarge = false;
        $hdrs = [];
        foreach ($req['headers'] as $k => $v) {
            $hdrs[] = $k . ': ' . $v;
        }
        curl_setopt_array($ch, [
            CURLOPT_URL => $req['url'],
            CURLOPT_CUSTOMREQUEST => $req['method'],
            CURLOPT_HTTPHEADER => $hdrs,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => min(10, (int) $req['timeout']),
            CURLOPT_TIMEOUT => (int) $req['timeout'],
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                $len = strlen($line);
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $k = strtolower(trim($k));
                    $v = trim($v);
                    $headers[$k] = isset($headers[$k]) && $k !== 'location' ? $headers[$k] . ', ' . $v : $v;
                } elseif (preg_match('~^HTTP/~', $line)) {
                    $headers = []; // a new status line: the previous (e.g. 100 Continue) headers are discarded
                }
                return $len;
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, $max, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > $max) {
                    $tooLarge = true;
                    return -1;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($req['body'] !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $req['body']);
        }
        if (!empty($req['proxy'])) {
            curl_setopt($ch, CURLOPT_PROXY, $req['proxy']);
        }
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_errno($ch) !== 0 ? curl_error($ch) : null;
        curl_close($ch);
        if ($tooLarge) {
            return ['status' => $status, 'headers' => $headers, 'body' => '', 'error' => 'max_bytes'];
        }
        if ($err !== null) {
            return ['status' => $status, 'headers' => $headers, 'body' => $body, 'error' => preg_replace('~[\r\n]+~', ' ', $err)];
        }
        return ['status' => $status, 'headers' => $headers, 'body' => $body, 'error' => null];
    };
}

/** JSON of a response body, or null when it is not an object/array. */
function si_http_json(array $resp): ?array
{
    if (!$resp['ok'] || $resp['body'] === '') {
        return null;
    }
    $data = json_decode($resp['body'], true);
    return is_array($data) ? $data : null;
}
