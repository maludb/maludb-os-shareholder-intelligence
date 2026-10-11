<?php
declare(strict_types=1);

/** What every reports and exports screen and handler starts with (slice 8): the bootstrap, the issuers' reads, the packs' features and the exports'. */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__) . '/issuers/queries.php';
require_once dirname(__DIR__) . '/issuers/present.php';
require_once dirname(__DIR__) . '/notes/write.php';
require_once dirname(__DIR__) . '/activity/queries.php';
require_once dirname(__DIR__, 2) . '/si/fetch/calendar.php';
require_once dirname(__DIR__) . '/events/queries.php';
require_once dirname(__DIR__) . '/events/present.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';

/** The current issuer for a reports screen, or the empty page. */
function reports_issuer_or_empty(PDO $pdo, string $screen, string $title): array
{
    $issuer = current_issuer($pdo);
    if ($issuer === null) {
        log_screen_view($pdo, $screen);
        if (wants_json()) { respond_screen(['issuer_id' => null]); }
        render_screen($title, view('positions/partials/no-issuer.php', ['id' => $screen, 'title' => $title, 'issuer' => null]), ['activeNav' => 'reports', 'screen' => $screen]);
        exit;
    }
    return $issuer;
}

/** The pack a request names (`report` on a handler, `id` on a screen), through the wall; a 404 when it is not there, tombstoned or a draft the member may not see. */
function report_from_request(PDO $pdo, string $field = 'report'): array
{
    $id = request_integer($field) ?? request_integer('id');
    $r = $id === null ? null : find_report($pdo, $id);
    return $r ?? refuse(404, 'Not found.');
}
