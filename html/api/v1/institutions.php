<?php
declare(strict_types=1);
/** /api/v1/institutions — ?issuer= or ?security= — 13F, 13D and 13G filers (institutions/index.php's JSON); /{id} one institution. A key is a person's own: their rights, both walls, one `api.call` row (app/api/routes.php). */
require_once dirname(__DIR__, 3) . '/app/api/routes.php';
api_serve('institutions');
