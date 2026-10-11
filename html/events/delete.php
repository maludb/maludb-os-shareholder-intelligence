<?php
declare(strict_types=1);
/** Action `event_delete` (log `event.delete`; events.write; an agent's pauses — deletion): `event` goes to the tombstone; event_restore brings it back. */
require_once dirname(__DIR__, 2) . '/app/features/events/screen.php';
si_handler_begin();
require_right('events.write');
$pdo = db();
$e = event_from_request($pdo, 'event');
$id = (int) $e['event_id'];
si_guard($pdo, static function () use ($pdo, $id): void {
    $pdo->beginTransaction();
    event_delete($pdo, $id, false);
    $pdo->commit();
});
si_done('Deleted the event', $id, si_land('/events?undo=' . $id, 'deleted'), 'eventChanged');
