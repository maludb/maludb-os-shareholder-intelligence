<?php
declare(strict_types=1);

/** What every signals and rules screen and handler starts with (slice 7): the bootstrap, the charts, the signals' and the rules' features. */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__) . '/issuers/queries.php';
require_once dirname(__DIR__) . '/issuers/present.php';
require_once dirname(__DIR__, 2) . '/si/svg.php';
require_once dirname(__DIR__) . '/short/present.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';
require_once dirname(__DIR__) . '/rules/queries.php';
require_once dirname(__DIR__) . '/rules/write.php';

/** The signal a request names (`id` on a screen, `signal` on a handler), through the wall; a 404 when it is not there or not visible. */
function signal_from_request(PDO $pdo, string $param): array
{
    return find_signal($pdo, request_integer($param) ?? 0) ?? refuse(404, 'Signal not found.');
}

/** The open (or escalated) signal a disposition handler acts on; a disposed one is a 422 in words. */
function signal_open_or_refuse(array $sig): void
{
    if (!in_array($sig['signal']['status'], ['open', 'escalated'], true)) {
        refuse(422, 'The signal is ' . $sig['signal']['status'] . ' already; it can no longer be disposed.');
    }
}

/** An explaining event: an event of the signal's issuer in its window (the picker offers exactly these), or a field error. */
function signal_event_check(PDO $pdo, array $sig, ?string $raw, string $field, array &$errors): ?int
{
    if ($raw === null || $raw === '' || $raw === '0') {
        return null;
    }
    if (!ctype_digit($raw)) {
        $errors[$field] = 'Choose the event from the list.';
        return null;
    }
    foreach ($sig['events'] as $e) {
        if ((int) $e['event_id'] === (int) $raw) {
            return (int) $raw;
        }
    }
    $errors[$field] = 'That event is not one of the issuer\'s events around the signal\'s window.';
    return null;
}

/** The landing after a disposition: the page the form came from, else the signal. */
function signal_land(int $id, string $notice): string
{
    return si_land(return_path('/signals/' . $id), $notice);
}

/** A rule's thresholds from the request: `params[key]` (the form) or `params` as a JSON object (an agent); null when left out. */
function rule_params_from_request(array &$errors): ?array
{
    if (!array_key_exists('params', $_POST)) {
        return null;
    }
    $p = $_POST['params'];
    if (is_array($p)) {
        return array_map(static fn ($v): string => is_array($v) ? '' : trim((string) $v), $p);
    }
    $p = trim((string) $p);
    if ($p === '') {
        return [];
    }
    $j = json_decode($p, true);
    if (!is_array($j) || array_is_list($j) && $j !== []) {
        $errors['params'] = 'The thresholds are an object of a key and a number each, such as {"float_pct_watch": 12}.';
        return null;
    }
    return array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', $j);
}
