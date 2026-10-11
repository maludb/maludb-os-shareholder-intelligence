<?php
declare(strict_types=1);

/**
 * The records API's routes (agents-k7-api.md "The API"; design §4, §8): `GET /api/v1/{issuers|securities|participants|positions|holders|institutions|holdings|beneficial|insiders|filings|
 * short-interest|short-volume|fails|threshold|signals|events|reports|uploads|borrow-observations}[/{id}]`, `POST /api/v1/{uploads|events|borrow-observations}`.
 *
 * THE KEY IS A PERSON'S OWN (app/api/bootstrap.php): it acts as that person, so every read is the screen's own query function under the screen's own right and the
 * database's two walls — a Viewer's key reads "Individual holder", a restricted issuer is not there for a key whose person is not on its list. A read therefore
 * answers EXACTLY the JSON of the screen it mirrors (`{data: …}` — the whitelist presenters of app/features/*), and a write runs the SAME handler a person's form posts to
 * (so the right, the validation, the database's verb and the log line are one code path): there is no second rule here. The route chooses the screen; this file chooses nothing else
 * except the issuer — `?issuer=` (an id, ticker or CIK) names it without switching anything (no `issuer.switch` row, no session), and a key that reaches several issuers must name one.
 */
require_once __DIR__ . '/bootstrap.php';

/** route => [list screen, item screen, POST handler, needs the issuer named]. Paths are under html/. */
const API_ROUTES = [
    'issuers'              => ['issuers/index.php', 'issuers/view.php', null, false],
    'securities'           => ['issuers/securities.php', null, null, false],                      // ?issuer= ; /{id} is answered here
    'participants'         => ['participants/index.php', 'participants/view.php', null, false],
    'positions'            => ['positions/index.php', null, null, true],
    'holders'              => ['holders/index.php', 'holders/view.php', null, true],
    'institutions'         => ['institutions/index.php', 'institutions/view.php', null, true],
    'holdings'             => ['ownership/13f.php', null, null, true],
    'beneficial'           => ['ownership/beneficial.php', null, null, true],
    'insiders'             => ['ownership/insiders.php', null, null, true],
    'filings'              => ['filings/index.php', 'filings/view.php', null, true],
    'short-interest'       => ['short/interest.php', null, null, true],
    'short-volume'         => ['short/volume.php', null, null, true],
    'fails'                => ['short/fails.php', null, null, true],
    'threshold'            => ['short/threshold.php', null, null, true],
    'signals'              => ['signals/index.php', 'signals/view.php', null, true],
    'events'               => ['events/index.php', 'events/view.php', 'events/save.php', true],
    'reports'              => ['reports/index.php', 'reports/view.php', null, true],
    'uploads'              => ['uploads/index.php', 'uploads/view.php', 'uploads/save.php', true],
    'borrow-observations'  => ['short/borrow.php', null, 'short/borrow/save.php', true],
];

/**
 * Serve one route: CORS, the key (or a session), the method, the issuer, then the screen or the handler. Never returns.
 */
function api_serve(string $route): never
{
    $def = API_ROUTES[$route] ?? api_error('not_found', 'No such route.', 404);
    [$list, $item, $post, $needsIssuer] = $def;
    api_cors();
    $methods = $post === null ? ['GET'] : ['GET', 'POST'];
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $methods, true)) {
        api_require_method(...$methods);
    }
    api_authenticate();
    $pdo = db();
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    if ($isPost && api_key() === null) {
        verify_csrf();                                             // a browser session calling the API is a form: its token is needed (a key's call has no cookie to forge)
    }
    $root = dirname(__DIR__, 2) . '/html/';
    $id = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : null;
    // the issuer, named without switching anything
    $issuerRef = isset($_GET['issuer']) && is_string($_GET['issuer']) ? trim($_GET['issuer']) : '';
    unset($_GET['issuer']);
    if ($issuerRef !== '') {
        $i = find_visible_issuer($pdo, $issuerRef) ?? api_error('not_found', 'Issuer not found.', 404);
        $_SESSION['issuer_id'] = (int) $i['issuer_id'];
    }
    if ($isPost) {
        if ($issuerRef !== '' && !isset($_POST['issuer'])) {
            $_POST['issuer'] = (string) $_SESSION['issuer_id'];
        }
        $_SERVER['REQUEST_METHOD'] = 'POST';
        require $root . $post;
        api_error('server_error', 'The handler did not answer.', 500);
    }
    if ($id !== null) {
        if ($route === 'securities') {
            api_security($pdo, $id);
        }
        if ($item === null) {
            api_error('not_found', 'No such record.', 404);
        }
        $_GET['id'] = (string) $id;
        require $root . $item;
        api_error('server_error', 'The screen did not answer.', 500);
    }
    if ($route === 'securities' && $issuerRef === '') {
        api_error('invalid', 'Name the issuer: ?issuer=<id, ticker or CIK>.', 422);
    }
    if ($needsIssuer && !isset($_GET['security']) && $issuerRef === '' && count(my_issuers($pdo)) > 1) {
        api_error('invalid', 'This key reaches several issuers: name one with ?issuer=<id, ticker or CIK> (or the security with ?security=<id>).', 422);
    }
    if ($route === 'securities') {
        $_GET['id'] = (string) $_SESSION['issuer_id'];            // the issuer's securities screen takes the issuer as its id
    }
    require $root . $list;
    api_error('server_error', 'The screen did not answer.', 500);
}

/** One security (and its latest share counts) behind the issuer wall: GET /api/v1/securities/{id}. */
function api_security(PDO $pdo, int $id): never
{
    require_once dirname(__DIR__) . '/features/issuers/queries.php';
    require_once dirname(__DIR__) . '/features/issuers/present.php';
    require_right('issuers.read');
    $s = find_security($pdo, $id) ?? api_error('not_found', 'Security not found.', 404);
    $GLOBALS['__screen_payload'] = ['security' => present_security($s)];
    json_response(['data' => ['security' => present_security($s), 'share_counts' => array_map('present_share_count', share_counts_latest($pdo, $id))]]);
}
