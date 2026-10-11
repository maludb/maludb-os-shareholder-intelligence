<?php
declare(strict_types=1);
/** /admin/templates/{id}/edit and /admin/templates/new — a template's name, kind, connector and mapping, with what the mapping reads beside it (screen `admin-template-edit`; params: kind). settings.manage. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/present.php';
require_right('settings.manage');
$pdo = db();
$id = request_integer('id');
$t = $id === null ? null : (find_template($pdo, $id) ?? refuse(404, 'Template not found.'));
$mappingText = $t === null ? "{\n  \"header_row\": 1,\n  \"columns\": {\n  }\n}" : json_encode(json_decode((string) $t['mapping'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
log_screen_view($pdo, 'admin-template-edit');
if (wants_json()) {
    respond_screen(['template' => $t === null ? null : present_template($t), 'kinds' => TEMPLATE_KINDS, 'fields' => ['name', 'kind', 'connector', 'mapping']]);
}
render_screen($t === null ? 'New template' : 'Change ' . $t['name'], view('admin/template-form.php', ['t' => $t, 'mappingText' => $mappingText, 'tz' => member_timezone(), 'back' => back_link()]),
    ['activeNav' => 'admin', 'screen' => 'admin-template-edit', 'entity' => 'import_template', 'recordId' => $t === null ? '' : (string) $t['template_id']]);
