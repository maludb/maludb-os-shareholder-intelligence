<?php
declare(strict_types=1);
/** /api/v1/threshold — ?issuer= or ?security= — the Reg SHO threshold lists (short/threshold.php's JSON). A key is a person's own: their rights, both walls, one `api.call` row (app/api/routes.php). */
require_once dirname(__DIR__, 3) . '/app/api/routes.php';
api_serve('threshold');
