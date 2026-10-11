<?php
declare(strict_types=1);
/** /api/v1/beneficial — ?issuer= or ?security= — the 5% filers (ownership/beneficial.php's JSON). A key is a person's own: their rights, both walls, one `api.call` row (app/api/routes.php). */
require_once dirname(__DIR__, 3) . '/app/api/routes.php';
api_serve('beneficial');
