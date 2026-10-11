<?php
declare(strict_types=1);
/** /admin/sources/{id}/edit — change a source's name, base URL, settings, rate, user-agent override and whether it is active (screen `admin-source-edit`; the form posts to save.php). sources.manage. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/present.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/write.php';
require_right('sources.manage');
$pdo = db();
$b = find_source_base($pdo, (int) (request_integer('id') ?? 0)) ?? refuse(404, 'Source not found.');
log_screen_view($pdo, 'admin-source-edit');
if (wants_json()) {
    respond_screen(['source' => ['source_id' => (int) $b['id'], 'name' => $b['name'], 'connector' => $b['connector'], 'base_url' => $b['base_url'], 'rate_per_second' => (float) $b['rate_per_second'], 'user_agent' => $b['user_agent'],
        'active' => (bool) $b['active'], 'settings' => (object) source_settings_array($b)], 'fields' => ['name', 'base_url', 'settings', 'rate_per_second', 'user_agent', 'active'], 'edgar_rate_ceiling' => edgar_rate_ceiling($pdo)]);
}
render_screen('Change ' . $b['name'], view('admin/source-form.php', ['b' => $b, 'errors' => [], 'old' => [], 'ceiling' => edgar_rate_ceiling($pdo), 'tz' => member_timezone(), 'back' => back_link()]),
    ['activeNav' => 'admin', 'screen' => 'admin-source-edit', 'entity' => 'source', 'recordId' => (string) $b['id']]);
