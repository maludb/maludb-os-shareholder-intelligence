<?php
declare(strict_types=1);
/** /api/v1/borrow-observations — ?issuer= or ?security= — the borrow observations (short/borrow.php's JSON). POST records one — the same handler as the form (borrow.write): security, observed_on, fee_rate_percent, …. A key is a person's own: their rights, both walls, one `api.call` row (app/api/routes.php). */
require_once dirname(__DIR__, 3) . '/app/api/routes.php';
api_serve('borrow-observations');
