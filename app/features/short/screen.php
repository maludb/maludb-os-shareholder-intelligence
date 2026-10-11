<?php
declare(strict_types=1);

/** What every short-side screen and handler starts with (slice 6): the positions context (the security behind the wall) and the short side's features. */
require_once dirname(__DIR__) . '/positions/screen.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';

/** [issuer, security] for a short-side screen, or the empty page. */
function short_context_or_empty(PDO $pdo, string $screen, string $title): array
{
    [$issuer, $security] = positions_context($pdo);
    if ($security === null) {
        log_screen_view($pdo, $screen);
        if (wants_json()) { respond_screen(['security_id' => null]); }
        render_screen($title, view('positions/partials/no-issuer.php', ['id' => $screen, 'title' => $title, 'issuer' => $issuer]), ['activeNav' => 'short', 'screen' => $screen]);
        exit;
    }
    return [$issuer, $security];
}
