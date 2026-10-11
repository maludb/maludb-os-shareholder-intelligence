<?php
declare(strict_types=1);
/** Action `source_schedule_set` (log `source.schedule`; sources.manage; an agent's pauses — other): **source**, **schedule_minutes** (0 for manual only). record_id = the source. The next slot moves with the schedule. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/present.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/write.php';
si_handler_begin();
require_right('sources.manage');
$pdo = db();
$src = find_source_base($pdo, (int) (request_integer('source') ?? 0)) ?? refuse(404, 'Source not found.');
$errors = [];
$m = si_int('schedule_minutes', null, 0, 525600, 'The schedule', $errors);
if ($m === null && $errors === []) { $errors['schedule_minutes'] = 'Say the minutes between pulls (0 for manual only).'; }
if ($errors !== []) {
    si_refuse_fields($errors);
}
$r = si_guard($pdo, static function () use ($pdo, $src, $m): array {
    $pdo->beginTransaction();
    $r = source_schedule_set($pdo, (int) $src['id'], (int) $m);
    $pdo->commit();
    return $r;
});
si_done($r['before'] === $r['after'] ? 'The schedule of ' . $src['name'] . ' is ' . schedule_words($r['after']) . ' already' : 'The schedule of ' . $src['name'] . ' is ' . schedule_words($r['after']), (int) $src['id'],
    si_land(return_path('/admin/sources/' . (int) $src['id']), 'scheduled'), 'sourceChanged', ['schedule_minutes' => $r['after']]);
