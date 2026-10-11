<?php
declare(strict_types=1);
/** /api/v1/fails — ?issuer= or ?security=, from, to — the SEC's fails-to-deliver (short/fails.php's JSON). A key is a person's own: their rights, both walls, one `api.call` row (app/api/routes.php). */
require_once dirname(__DIR__, 3) . '/app/api/routes.php';
api_serve('fails');
