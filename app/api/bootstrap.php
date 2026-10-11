<?php
declare(strict_types=1);

/**
 * The token API (mcp-and-api.md §6; design §4, §8, D13): /api/v1/*.php, a session or a Bearer KEY (scope 'api'). A key is a PERSON's own:
 * it acts as that person and sees exactly what they see (the walls of db/010 judge every row), rate-limited per minute and per day
 * (si_rate_ok, db/005), logged under the key's label (never the key). One 401 body for every failure. CORS from an allow-list, never with
 * credentials.
 */
if (PHP_SAPI !== 'cli') {
    // Every answer under /api/v1 is JSON, whatever the caller's Accept said (so a refusal is never an HTML page); a JSON body is read as the form fields the handlers read.
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === 0) {
        $body = json_decode((string) file_get_contents('php://input'), true);
        if (is_array($body)) {
            $_POST = array_map(static fn ($v) => is_array($v) ? array_map(static fn ($x) => is_scalar($x) ? (string) $x : '', $v) : (is_bool($v) ? ($v ? '1' : '0') : (is_scalar($v) ? (string) $v : '')), $body);
        }
    }
}
require_once dirname(__DIR__) . '/bootstrap.php';

set_exception_handler(static function (Throwable $e): void {
    error_log('api error: ' . $e->getMessage());
    if (!headers_sent()) {
        api_error('server_error', 'Something went wrong.', 500);
    }
    exit;
});

function api_json(array $payload, int $status = 200): never
{
    json_response($payload, $status);
}

function api_error(string $code, string $message, int $status): never
{
    json_error($code, $message, $status);
}

function api_cors(): void
{
    header('Vary: Origin');
    $configured = array_filter(array_map('trim', explode(',', (string) env('API_CORS_ORIGINS', ''))));
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && in_array($origin, $configured, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function api_require_get(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        api_error('method_not_allowed', 'Only GET is supported.', 405);
    }
}

function api_require_method(string ...$methods): void
{
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $methods, true)) {
        header('Allow: ' . implode(', ', $methods));
        api_error('method_not_allowed', 'Only ' . implode(' or ', $methods) . ' is supported.', 405);
    }
}

function api_bearer_token(): string
{
    $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $header = (string) $value;
                break;
            }
        }
    }
    return preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m) ? $m[1] : '';
}

/**
 * The caller as a member row, or 401 — a session first, then a Bearer token. Two kinds of bearer, never confused:
 *   - an `api` KEY resolves through si_resolve_key(): its facts land in $GLOBALS['__api_key'] (token_id, label, rate_per_minute, rate_per_day),
 *     $GLOBALS['__api_token_id'] stamps every log row, and the caller acts as the key's person — every read and write judged as that person;
 *   - an `mcp` token is a person's read-only token for the MCP servers and is NOT a door to the token API (401 here).
 * Every key's call is counted (api_rate_limit) BEFORE the handler runs: the 61st in a minute is a 429 with Retry-After.
 */
function api_authenticate(): array
{
    $pdo = db();
    // A Bearer key, when one is presented, is what the call is: a stale or revoked one is 401 even from a browser that happens to hold a session. Without one, a signed-in person's session.
    if (api_bearer_token() === '' && is_logged_in() && empty($GLOBALS['__action_authed'])) {
        $member = current_member();
        if ($member !== null && $member['status'] === 'active' && $member['capability'] !== null) {
            return $member;
        }
    }
    $token = api_bearer_token();
    if ($token === '') {
        api_error('unauthorized', 'A valid API key is required.', 401);
    }
    $st = $pdo->prepare('SELECT token_id, member_id, label, rate_per_minute, rate_per_day FROM si_resolve_key(:h)');
    $st->execute(['h' => hash('sha256', $token)]);
    $key = $st->fetch();
    $member = $key === false ? null : find_member_by_id($pdo, (int) $key['member_id']);
    if ($key === false || $member === null) {
        api_error('unauthorized', 'A valid API key is required.', 401);
    }
    $browserSession = empty($_SESSION['member_id']) ? null : $_SESSION;   // a browser's own session, should the call carry its cookie as well, is put back as it was
    $_SESSION['member_id'] = (int) $member['id'];
    $GLOBALS['__api_token_authed'] = true;
    $GLOBALS['__api_token_id'] = (int) $key['token_id'];
    $GLOBALS['__api_key'] = ['token_id' => (int) $key['token_id'], 'label' => (string) $key['label'],
        'rate_per_minute' => (int) $key['rate_per_minute'], 'rate_per_day' => (int) $key['rate_per_day']];
    $GLOBALS['__public_door'] = 'api';
    header_remove('Set-Cookie');
    register_shutdown_function(static function () use ($browserSession): void {      // a key's call leaves no session behind (and no cookie), and one `api.call` row in the trail
        api_log_call();
        if (session_status() === PHP_SESSION_ACTIVE) {
            if ($browserSession !== null) {
                $_SESSION = $browserSession;
            } else {
                $_SESSION = [];
                session_destroy();
            }
        }
    });
    db_apply_context($pdo);
    api_rate_limit();
    return $member;
}

/** Is this request a key's? (a session caller is not). */
function api_key(): ?array
{
    return $GLOBALS['__api_key'] ?? null;
}

/** Count one call of a key into its minute and day buckets (si_rate_ok); the 61st in a minute is a 429 with Retry-After. A session caller is not limited. */
function api_rate_limit(): void
{
    $key = api_key();
    if ($key === null) {
        return;
    }
    $st = db()->prepare('SELECT ok, limit_hit, retry_after FROM si_rate_ok(:t)');
    $st->execute(['t' => $key['token_id']]);
    $r = $st->fetch();
    if ($r === false || !$r['ok']) {
        header('Retry-After: ' . max(1, (int) ($r['retry_after'] ?? 60)));
        api_error('rate_limited', 'Too many calls (' . ($r['limit_hit'] ?? 'limit') . ').', 429);
    }
}

/**
 * One `api.call` row for the call (source api, the key's token_id and person): the route, the method, the answer's status and the rows it carried — and, for a read of
 * holders, how many holders (the PII wall's trail). Never the query string (a name searched is a person's), never a key.
 */
function api_log_call(): void
{
    if (api_key() === null) {
        return;
    }
    $route = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
    $route = preg_replace('~/\d+~', '/{id}', $route);
    $p = $GLOBALS['__screen_payload'] ?? null;
    $rows = null;
    if (is_array($p)) {
        $rows = 0;
        foreach ($p as $v) {
            if (is_array($v) && array_is_list($v)) {
                $rows += count($v);
            }
        }
    }
    $after = ['route' => $route, 'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), 'status' => (int) http_response_code(), 'rows' => $rows];
    if (str_starts_with($route, '/api/v1/holders') && is_array($p)) {
        $after['holders_rows'] = isset($p['holders']) && is_array($p['holders']) ? count($p['holders']) : (isset($p['holder']) ? 1 + count((array) ($p['accounts'] ?? [])) : 0);
    }
    log_activity(db(), 'api.call', null, null, ['after' => $after]);
}
