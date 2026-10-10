<?php
declare(strict_types=1);
/** Action `watch_hit_dismiss` (log `watch.dismiss`; events.write; PEOPLE): `watch_hit` — the hit dismissed, and the instrument it proposed with it. */
require_once dirname(__DIR__, 3) . '/app/features/filings/screen.php';
si_handler_begin();
require_human();
require_right('events.write');
$pdo = db();
$hit = request_integer('watch_hit');
if ($hit === null || find_watch_hit($pdo, $hit) === null) { refuse(404, 'Not found.'); }
si_guard($pdo, static function () use ($pdo, $hit): void {
    $pdo->beginTransaction();
    watch_hit_dismiss($pdo, (int) $hit, (int) current_member_id());
    $pdo->commit();
});
si_done('Dismissed the hit', $hit, si_land(return_path('/filings/watch'), 'dismissed'), 'watchChanged');
