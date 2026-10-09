<?php
declare(strict_types=1);

/**
 * The one funnel (memory.md §2). Every state change and every screen entry writes a row; the
 * ingest bridge ships it to the tenant's MaluDB with "application": "shareholder_intelligence".
 * $opts: actor_member_id, source, screen, route, before, after, request_id, agent_run_id, department_id, location_id, kernel_request_id,
 *        issuer_id, security_id, import_id, signal_id, token_id (the audit keys of design §6.8 — each record's TIMELINE is one index, and the
 *        key an API call ran under). A HOLDER'S NAME, ADDRESS OR LINES, A FILING'S TEXT, A CREDENTIAL, A TOKEN OR A KEY IS NEVER IN A PAYLOAD
 *        (design §6.8): ids, names, counts, kinds, statuses, stages and values are; a key is named by its LABEL in `after`.
 */
function log_activity(PDO $pdo, string $action, ?string $entityType = null, int|string|null $entityId = null, array $opts = []): void
{
    try {
        $eid = null;
        if (is_int($entityId) || (is_string($entityId) && ctype_digit($entityId))) {
            $eid = (int) $entityId;
        }
        $stmt = $pdo->prepare(<<<'SQL'
            INSERT INTO activity_log
                (actor_member_id, source, action, screen, route, entity_type, entity_id,
                 before, after, request_id, session_id, ip_address, agent_run_id, department_id, location_id, kernel_request_id,
                 issuer_id, security_id, import_id, signal_id, token_id)
            VALUES
                (:actor, :source, :action, :screen, :route, :etype, :eid,
                 :before, :after, :rid, :sid, :ip, :run, :dept, :loc, :krid, :issuer, :sec, :imp, :sig, :tok)
        SQL);
        $stmt->execute([
            'actor'  => array_key_exists('actor_member_id', $opts) ? $opts['actor_member_id'] : ($_SESSION['member_id'] ?? null),
            'source' => $opts['source'] ?? default_activity_source(),
            'action' => $action,
            'screen' => $opts['screen'] ?? null,
            'route'  => $opts['route'] ?? request_route(),
            'etype'  => $entityType,
            'eid'    => $eid,
            'before' => isset($opts['before']) ? json_encode($opts['before'], JSON_THROW_ON_ERROR) : null,
            'after'  => isset($opts['after']) ? json_encode($opts['after'], JSON_THROW_ON_ERROR) : null,
            'rid'    => $opts['request_id'] ?? agent_run_request_id() ?? request_id(),
            'sid'    => PHP_SAPI === 'cli' ? null : (session_id() ?: null),
            'ip'     => PHP_SAPI === 'cli' ? null : client_ip(),
            'run'    => $opts['agent_run_id'] ?? current_agent_run_id(),
            'dept'   => $opts['department_id'] ?? null,
            'loc'    => $opts['location_id'] ?? ($entityType === 'location' ? $eid : null),
            'krid'   => $opts['kernel_request_id'] ?? null,
            'issuer' => isset($opts['issuer_id']) ? (int) $opts['issuer_id'] : ($entityType === 'issuer' ? $eid : null),
            'sec'    => isset($opts['security_id']) ? (int) $opts['security_id'] : ($entityType === 'security' ? $eid : null),
            'imp'    => isset($opts['import_id']) ? (int) $opts['import_id'] : ($entityType === 'import' ? $eid : null),
            'sig'    => isset($opts['signal_id']) ? (int) $opts['signal_id'] : ($entityType === 'signal' ? $eid : null),
            'tok'    => isset($opts['token_id']) ? (int) $opts['token_id'] : (isset($GLOBALS['__api_token_id']) ? (int) $GLOBALS['__api_token_id'] : null),
        ]);
    } catch (Throwable $e) {
        error_log('activity_log insert failed: ' . $e->getMessage());
    }
}

/** 'web' for the UI; 'agent' under a run token; 'assistant' under a person's action token; 'cron' under CLI; 'api' under a key; 'portal' for a link holder. */
function default_activity_source(): string
{
    if (is_action_authed()) {
        return current_agent_run_id() !== null ? 'agent' : 'assistant';
    }
    if (!empty($GLOBALS['__public_door'])) {
        return (string) $GLOBALS['__public_door'];      // 'portal' (a link or a file request, /s/ and /r/) or 'api' (a key's call)
    }
    return PHP_SAPI === 'cli' ? 'cron' : 'web';
}

function log_screen_view(PDO $pdo, string $screen): void
{
    if (wants_json() && ($_SERVER['HTTP_X_SCREEN_VIEW'] ?? '') !== '1') {
        return;
    }
    $issuer = function_exists('current_issuer') && PHP_SAPI !== 'cli' && is_logged_in() ? current_issuer($pdo) : null;     // the screen's issuer (sso-shell.md)
    log_activity($pdo, 'screen.view', null, null, ['screen' => $screen, 'issuer_id' => $issuer !== null ? (int) $issuer['issuer_id'] : null]);
}

/**
 * Under a run token every row of the run carries the run's own request id (the join to the
 * kernel's prompt ledger). Shareholder Intelligence cannot read the kernel's database: it asks the run-facts call once
 * per request (agents.md, A7 b) — never a header the agent could set.
 */
function agent_run_request_id(): ?string
{
    static $resolved = false, $id = null;
    if (!$resolved) {
        $resolved = true;
        if (current_agent_run_id() !== null) {
            $facts = run_facts((string) ($GLOBALS['__action_token'] ?? ''));
            $id = $facts !== null && !empty($facts['valid']) ? ($facts['request_id'] ?? null) : null;
        }
    }
    return $id;
}

function request_id(): string
{
    static $id = null;
    if ($id === null) {
        $given = trim((string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
        $id = $given !== '' && preg_match('/^[A-Za-z0-9._:-]{4,80}$/', $given) ? $given : bin2hex(random_bytes(8));
    }
    return $id;
}

function request_route(): string
{
    $method = $_SERVER['REQUEST_METHOD'] ?? (PHP_SAPI === 'cli' ? 'CLI' : 'GET');
    $path = parse_url($_SERVER['REQUEST_URI'] ?? ($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH) ?: '';
    return trim($method . ' ' . $path);
}
