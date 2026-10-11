<?php
declare(strict_types=1);
/** /api/v1/reports — ?issuer= — the packs the key's person may read (reports/index.php's JSON); /{id} one pack (reports/view.php). A key is a person's own: their rights, both walls, one `api.call` row (app/api/routes.php). */
require_once dirname(__DIR__, 3) . '/app/api/routes.php';
api_serve('reports');
