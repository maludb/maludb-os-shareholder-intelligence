<?php
declare(strict_types=1);
/** Action `template_delete` (log `template.delete`; settings.manage; confirm; an agent's pauses — deletion): **template**. The transfer agent's extract template stays (it names the columns never stored). record_id = the template. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/write.php';
si_handler_begin();
require_right('settings.manage');
$pdo = db();
$id = request_integer('template') ?? si_refuse_fields(['template' => 'Name the template.']);
$t = find_template($pdo, $id) ?? refuse(404, 'Template not found.');
si_guard($pdo, static function () use ($pdo, $id): void {
    $pdo->beginTransaction();
    template_delete($pdo, $id);
    $pdo->commit();
});
si_done('Deleted the template ' . $t['name'], $id, si_land('/admin/templates', 'deleted'), 'templateChanged');
