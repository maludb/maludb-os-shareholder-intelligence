<?php
declare(strict_types=1);
/**
 * Actions `event_create` and `event_update` (logs `event.create`, `event.update`; events.write): `issuer`, `on_date`, `kind`, `title` to record; `event` to change one
 * (a field left out stays); security, body (Markdown), url, severity, filing, tags[]. A person's event is source person, an agent's agent. record_id = the event.
 */
require_once dirname(__DIR__, 2) . '/app/features/events/screen.php';
si_handler_begin();
require_right('events.write');
$pdo = db();
$e = request_integer('event') !== null ? event_from_request($pdo, 'event') : null;
$issuer = $e !== null ? (int) $e['issuer_id'] : (request_integer('issuer') ?? (int) (current_issuer($pdo)['issuer_id'] ?? 0));
if ($e === null && find_issuer($pdo, $issuer) === null) { si_refuse_fields(['issuer' => 'Choose the issuer.']); }
$f = event_fields($pdo, $issuer, $e);
$f['issuer'] = $issuer;
$tags = request_list('tags');
if ($tags !== null) { $f['tags'] = $tags; }
$id = si_guard($pdo, static function () use ($pdo, $e, $f): int {
    $pdo->beginTransaction();
    $id = event_save($pdo, $e === null ? null : (int) $e['event_id'], $f, (int) current_member_id());
    $pdo->commit();
    return $id;
});
si_done($e === null ? 'Recorded the event' : 'Saved the event', $id, si_land(return_path('/events/' . $id), $e === null ? 'created' : 'saved'), 'eventChanged');
