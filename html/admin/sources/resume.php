<?php
declare(strict_types=1);
/** Action `source_resume` (log `source.resume`; sources.manage; undo source_pause): **source**. A source waiting for its key resumes by itself when the credential is set. record_id = the source. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/present.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/write.php';
si_handler_begin();
require_right('sources.manage');
$pdo = db();
$src = find_source_base($pdo, (int) (request_integer('source') ?? 0)) ?? refuse(404, 'Source not found.');
si_guard($pdo, static function () use ($pdo, $src): void {
    $pdo->beginTransaction();
    source_pause($pdo, (int) $src['id'], null, true);
    $pdo->commit();
});
si_done('Resumed ' . $src['name'], (int) $src['id'], si_land(return_path('/admin/sources'), 'resumed'), 'sourceChanged');
