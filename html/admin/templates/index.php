<?php
declare(strict_types=1);
/** /admin/templates?kind= — the import templates (the saved column mappings) by kind and connector (screen `admin-templates`); New, Edit, Delete. settings.manage. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/present.php';
require_right('settings.manage');
$pdo = db();
$kind = isset(TEMPLATE_KINDS[request_string('kind')]) ? request_string('kind') : '';
$rows = find_templates($pdo, $kind === '' ? null : $kind);
log_screen_view($pdo, 'admin-templates');
if (wants_json()) {
    respond_screen(['templates' => array_map('present_template', $rows), 'kinds' => TEMPLATE_KINDS, 'filters' => ['kind' => $kind]]);
}
$notices = ['deleted' => ['success', 'Deleted the template.'], 'saved' => ['success', 'Saved the template.']];
render_screen('Import templates', view('admin/templates.php', ['rows' => $rows, 'kind' => $kind, 'tz' => member_timezone(), 'notice' => si_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'admin', 'screen' => 'admin-templates', 'entity' => 'import_template']);
