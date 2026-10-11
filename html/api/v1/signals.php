<?php
declare(strict_types=1);
/** /api/v1/signals — ?issuer=, status, severity, rule, class, page — the signals with their evidence class (signals/index.php's JSON); /{id} one signal with its evidence (signals/view.php). A key is a person's own: their rights, both walls, one `api.call` row (app/api/routes.php). */
require_once dirname(__DIR__, 3) . '/app/api/routes.php';
api_serve('signals');
