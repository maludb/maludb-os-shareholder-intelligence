<?php
declare(strict_types=1);
/** /api/v1/short-volume — ?issuer= or ?security=, market, from, to — FINRA's daily off-exchange short volume with its caveat (short/volume.php's JSON). A key is a person's own: their rights, both walls, one `api.call` row (app/api/routes.php). */
require_once dirname(__DIR__, 3) . '/app/api/routes.php';
api_serve('short-volume');
