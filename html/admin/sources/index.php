<?php
declare(strict_types=1);
/** /admin/sources — a placeholder until slice 10 builds it (sso-shell.md): 200 to a person holding the right, 501 to JSON and to a POST; the registry marks it a stub. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
render_nav_stub('admin', NAV_SLICES['admin'], 'settings.manage|sources.manage|credentials.manage|tokens.manage|records.purge');
