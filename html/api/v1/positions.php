<?php
declare(strict_types=1);
/** /api/v1/positions — ?issuer= or ?security=, date, class, q — who held at DTC on a date, ranked (positions/index.php's JSON). A key is a person's own: their rights, both walls, one `api.call` row (app/api/routes.php). */
require_once dirname(__DIR__, 3) . '/app/api/routes.php';
api_serve('positions');
