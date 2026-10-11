<?php
declare(strict_types=1);
/** /api/v1/securities — ?issuer= — an issuer's securities (issuers/securities.php's JSON); /{id} one security with its latest share counts. A key is a person's own: their rights, both walls, one `api.call` row (app/api/routes.php). */
require_once dirname(__DIR__, 3) . '/app/api/routes.php';
api_serve('securities');
