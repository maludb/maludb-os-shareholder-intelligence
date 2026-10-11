<?php
declare(strict_types=1);
/** /api/v1/participants — DTC participants (participants/index.php's JSON; ?security= adds the position); /{id} one participant (participants/view.php). A key is a person's own: their rights, both walls, one `api.call` row (app/api/routes.php). */
require_once dirname(__DIR__, 3) . '/app/api/routes.php';
api_serve('participants');
