<?php
declare(strict_types=1);
/** Action `source_pause` (log `source.pause`; sources.manage; undo source_resume): **source**, reason. The worker skips a paused source and a manual pull names it paused. record_id = the source. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/present.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/write.php';
si_handler_begin();
require_right('sources.manage');
$pdo = db();
$src = find_source_base($pdo, (int) (request_integer('source') ?? 0)) ?? refuse(404, 'Source not found.');
$reason = req_val('reason');
if ($reason !== null && mb_strlen($reason) > 300) {
    si_refuse_fields(['reason' => 'The reason is up to 300 characters.']);
}
si_guard($pdo, static function () use ($pdo, $src, $reason): void {
    $pdo->beginTransaction();
    source_pause($pdo, (int) $src['id'], $reason, false);
    $pdo->commit();
});
si_done('Paused ' . $src['name'], (int) $src['id'], si_land(return_path('/admin/sources'), 'paused'), 'sourceChanged');
