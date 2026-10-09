<?php
declare(strict_types=1);

/**
 * What the shell reads on every render (sso-shell.md "Query functions"): THE CURRENT ISSUER (the shell's first fact — every screen reads
 * it, the switcher writes it), the issuers the wall lets the person see, the bell's count (mcp_notifications) and the business name.
 * Each is cached for the request — one query per render.
 */

/** The issuers the caller may see (mcp_issuers: the wall in the view), for the header's switcher. [{issuer_id, name, ticker, cik, restricted, primary_security_id}] */
function my_issuers(PDO $pdo): array
{
    static $cache = null;
    return $cache ??= $pdo->query('SELECT issuer_id, name, ticker, cik, exchange, restricted, primary_security_id FROM mcp_issuers ORDER BY name')->fetchAll();
}

/** An issuer named by its id, its ticker or its CIK — among those the caller may see; null when none (a restricted one answers null). */
function find_visible_issuer(PDO $pdo, string $ref): ?array
{
    $ref = trim($ref);
    if ($ref === '') {
        return null;
    }
    foreach (my_issuers($pdo) as $i) {
        if ((ctype_digit($ref) && (int) $i['issuer_id'] === (int) $ref)
            || ($i['ticker'] !== null && strcasecmp((string) $i['ticker'], $ref) === 0)
            || ($i['cik'] !== null && ctype_digit($ref) && ltrim((string) $i['cik'], '0') === ltrim($ref, '0') && strlen($ref) >= 4)) {
            return $i;
        }
    }
    return null;
}

/**
 * The issuer the person is working on: `?issuer=` (an id, ticker or CIK) when the wall admits it — remembered in the session and logged
 * `issuer.switch` when it changes — else the session's, else the only issuer visible, else none. An issuer the person can no longer see
 * (a restriction added, a tombstone) is forgotten, never shown.
 */
function current_issuer(PDO $pdo): ?array
{
    static $done = false, $cache = null;
    if ($done) {
        return $cache;
    }
    $done = true;
    $asked = isset($_GET['issuer']) && is_string($_GET['issuer']) ? $_GET['issuer'] : null;
    if ($asked !== null && $asked !== '' && ($i = find_visible_issuer($pdo, $asked)) !== null) {
        if ((int) ($_SESSION['issuer_id'] ?? 0) !== (int) $i['issuer_id'] && PHP_SAPI !== 'cli') {
            $from = isset($_SESSION['issuer_id']) ? (int) $_SESSION['issuer_id'] : null;
            $_SESSION['issuer_id'] = (int) $i['issuer_id'];
            log_activity($pdo, 'issuer.switch', 'issuer', (int) $i['issuer_id'], ['issuer_id' => (int) $i['issuer_id'], 'before' => ['issuer_id' => $from], 'after' => ['issuer_id' => (int) $i['issuer_id']]]);
        }
        return $cache = $i;
    }
    if (isset($_SESSION['issuer_id']) && ($i = find_visible_issuer($pdo, (string) $_SESSION['issuer_id'])) !== null) {
        return $cache = $i;
    }
    unset($_SESSION['issuer_id']);
    $all = my_issuers($pdo);
    if (count($all) === 1) {
        $_SESSION['issuer_id'] = (int) $all[0]['issuer_id'];
        return $cache = $all[0];
    }
    return $cache = null;
}

/** Set the current issuer by reference (the switcher's GET does it through ?issuer=); null when the wall refuses. */
function set_current_issuer(PDO $pdo, string $ref): ?array
{
    $_GET['issuer'] = $ref;
    return current_issuer($pdo);
}

/** The current issuer's primary security (the one every series defaults to), or null. [{security_id, class, name, cusip, ticker}] */
function current_security(PDO $pdo): ?array
{
    static $done = false, $cache = null;
    if ($done) {
        return $cache;
    }
    $done = true;
    $i = current_issuer($pdo);
    if ($i === null || $i['primary_security_id'] === null) {
        return $cache = null;
    }
    $st = $pdo->prepare('SELECT security_id, issuer_id, class, name, cusip, ticker FROM mcp_securities WHERE security_id = :s');
    $st->execute(['s' => (int) $i['primary_security_id']]);
    $r = $st->fetch();
    return $cache = ($r === false ? null : $r);
}

/** The bell: how many of my notifications are unread. */
function bell_count(PDO $pdo, int $memberId): int
{
    static $cache = null;
    return $cache ??= (int) one_value($pdo, 'SELECT count(*) FROM mcp_notifications WHERE read_at IS NULL');
}

/** The business's name on the header (si_settings.business_name via mcp_settings), else the application's. */
function business_name(PDO $pdo): string
{
    static $cache = null;
    return $cache ??= (string) (one_value($pdo, 'SELECT business_name FROM mcp_settings') ?: app_name());
}

/** The path of the current request, for the sidebar's highlight. */
function current_path(): string
{
    return (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
}
