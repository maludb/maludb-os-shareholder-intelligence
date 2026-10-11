<?php
declare(strict_types=1);
/** Action `participant_class_set` (log `participant.class_set`; issuers.manage): **participant**, **class** (retail, clearing_retail, prime, market_maker, lending, transfer_agent, bank_custodian or other). A class set here is a person's — the directory never overwrites it. record_id = the participant. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/write.php';
require_once dirname(__DIR__, 3) . '/app/features/participants/queries.php';
si_handler_begin();
require_right('issuers.manage');
$pdo = db();
$p = find_participant($pdo, (int) (request_integer('participant') ?? 0)) ?? refuse(404, 'Participant not found.');
$errors = [];
$class = strtolower((string) req_val('class'));
if (!isset(participant_classes($pdo)[$class])) {
    $errors['class'] = 'The class is one of ' . implode(', ', array_keys(participant_classes($pdo))) . '.';
}
if ($errors !== []) {
    si_refuse_fields($errors);
}
$r = si_guard($pdo, static function () use ($pdo, $p, $class): array {
    $pdo->beginTransaction();
    $r = participant_class_set($pdo, (int) $p['participant_id'], $class);
    $pdo->commit();
    return $r;
});
si_done($r['before'] === $r['after'] && $p['class_source'] === 'manual' ? $p['number'] . ' is in ' . $class . ' already' : 'Moved ' . $p['number'] . ' ' . $p['name'] . ' to ' . $class, (int) $p['participant_id'],
    si_land(return_path('/admin/participant-classes'), 'classed'), 'participantChanged', ['class' => $class]);
