<?php
declare(strict_types=1);

/** What every positions screen starts with (slice 2): the bootstrap, the features, and the security it reads — ?security= when the wall admits it, else the current issuer's primary. */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/queries.php';
require_once dirname(__DIR__) . '/participants/queries.php';
require_once dirname(__DIR__) . '/issuers/queries.php';
require_once dirname(__DIR__) . '/issuers/present.php';
require_once dirname(__DIR__) . '/uploads/write.php';
require_once dirname(__DIR__) . '/reconciliation/queries.php';
require_once dirname(__DIR__, 2) . '/si/svg.php';

/** [issuer|null, security|null] for a positions screen. */
function positions_context(PDO $pdo): array
{
    $sec = request_integer('security') !== null ? find_security($pdo, (int) request_integer('security')) : null;
    if ($sec !== null) {
        return [find_issuer($pdo, (int) $sec['issuer_id']), $sec];
    }
    $issuer = current_issuer($pdo);
    return [$issuer, $issuer === null ? null : current_security($pdo)];
}

/** The class colours keyed by class key (the chips' and the charts' palette). */
function class_colours(PDO $pdo): array
{
    $out = [];
    foreach (array_keys(participant_classes($pdo)) as $k) { $out[$k] = class_colour($k); }
    return $out;
}
