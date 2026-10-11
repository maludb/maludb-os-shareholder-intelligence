<?php
declare(strict_types=1);
/** /api/v1/issuers — the issuers the key's person may see (issuers/index.php's JSON); /{id} one issuer, its securities and counts (issuers/view.php). A key is a person's own: their rights, both walls, one `api.call` row (app/api/routes.php). */
require_once dirname(__DIR__, 3) . '/app/api/routes.php';
api_serve('issuers');
