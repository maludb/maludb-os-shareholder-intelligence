<?php
declare(strict_types=1);
/** /api/v1/uploads — ?issuer= — the uploads and their tie states (uploads/index.php's JSON); /{id} one upload (uploads/view.php). POST (multipart: file, kind, security, record_date) is upload_create — the same parser, the same tie (uploads.write). A key is a person's own: their rights, both walls, one `api.call` row (app/api/routes.php). */
require_once dirname(__DIR__, 3) . '/app/api/routes.php';
api_serve('uploads');
