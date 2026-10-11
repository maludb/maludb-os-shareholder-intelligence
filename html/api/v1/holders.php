<?php
declare(strict_types=1);
/** /api/v1/holders — ?issuer= or ?security=, channel, record_date, kind, custodian, q, tag, page — the holders as the screen answers them: a Viewer's key reads "Individual holder" (the view); /{id} one holder. A key is a person's own: their rights, both walls, one `api.call` row (app/api/routes.php). */
require_once dirname(__DIR__, 3) . '/app/api/routes.php';
api_serve('holders');
