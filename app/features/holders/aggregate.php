<?php
declare(strict_types=1);

/** The aggregate screens' common start (changes, ranges, geography, custodians, register): the context, or the empty page when there is no security. Answers [issuer, security]. */
function holders_aggregate_start(PDO $pdo, string $screen, string $title): array
{
    require_any_right('holders.read_aggregate|holders.read');
    [$issuer, $security] = positions_context($pdo);
    if ($security === null) {
        log_screen_view($pdo, $screen);
        if (wants_json()) { respond_screen(['security_id' => null]); }
        render_screen($title, view('positions/partials/no-issuer.php', ['id' => $screen, 'title' => $title, 'issuer' => $issuer]), ['activeNav' => 'holders', 'screen' => $screen]);
        exit;
    }
    return [$issuer, $security];
}
