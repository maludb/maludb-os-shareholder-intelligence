<?php
declare(strict_types=1);
/** /api/v1/events — ?issuer=, kind, from, to, page — the timeline (events/index.php's JSON); /{id} one event. POST records one — the same handler as the form (events.write): issuer, on_date, kind, title, …. A key is a person's own: their rights, both walls, one `api.call` row (app/api/routes.php). */
require_once dirname(__DIR__, 3) . '/app/api/routes.php';
api_serve('events');
