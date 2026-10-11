<?php
declare(strict_types=1);
/** Action `event_restore` (log `event.restore`; events.write): `event` — lifts the tombstone event_delete made (the purge window is db/014's). */
require_once dirname(__DIR__, 2) . '/app/features/events/screen.php';
si_handler_begin();
require_right('events.write');
$pdo = db();
$id = request_integer('event') ?? refuse(422, 'Say which event to restore.');
if (event_base_row($pdo, $id) === null) { refuse(404, 'Not found.'); }
si_guard($pdo, static function () use ($pdo, $id): void {
    $pdo->beginTransaction();
    event_delete($pdo, $id, true);
    $pdo->commit();
});
si_done('Restored the event', $id, si_land('/events/' . $id, 'restored'), 'eventChanged');
