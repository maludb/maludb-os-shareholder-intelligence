<?php
declare(strict_types=1);

/** What every events and calendar screen and handler starts with (slice 8): the bootstrap, the issuers' reads, the events' and the calendar's features. */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__) . '/issuers/queries.php';
require_once dirname(__DIR__) . '/issuers/present.php';
require_once dirname(__DIR__) . '/filings/queries.php';
require_once dirname(__DIR__) . '/notes/write.php';
require_once dirname(__DIR__) . '/activity/queries.php';
require_once dirname(__DIR__) . '/signals/present.php';
require_once dirname(__DIR__) . '/calendar/queries.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';

/** The current issuer for an events screen, or the empty page. */
function events_issuer_or_empty(PDO $pdo, string $screen, string $title, string $nav = 'events'): array
{
    $issuer = current_issuer($pdo);
    if ($issuer === null) {
        log_screen_view($pdo, $screen);
        if (wants_json()) { respond_screen(['issuer_id' => null]); }
        render_screen($title, view('positions/partials/no-issuer.php', ['id' => $screen, 'title' => $title, 'issuer' => null]), ['activeNav' => $nav, 'screen' => $screen]);
        exit;
    }
    return $issuer;
}

/** The event a request names (`event` on a handler, `id` on a screen), through the wall; a 404 when it is not there or not visible. */
function event_from_request(PDO $pdo, string $field = 'event'): array
{
    $id = request_integer($field) ?? request_integer('id');
    $e = $id === null ? null : find_event($pdo, $id);
    return $e ?? refuse(404, 'Not found.');
}
