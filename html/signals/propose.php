<?php
declare(strict_types=1);
/**
 * Action `signal_propose` (log `signal.propose`; signals.work; an agent's door): **signal**, **reason** (known, watching, explained_by_event, data_defect,
 * false_positive or escalate), explaining_event (required for explained_by_event), note (one sentence of why). Stored on the open signal; a person takes it.
 */
require_once dirname(__DIR__, 2) . '/app/features/signals/screen.php';
si_handler_begin();
require_right('signals.work');
$pdo = db();
$sig = signal_from_request($pdo, 'signal');
if ($sig['signal']['status'] !== 'open') {
    refuse(422, 'The signal is ' . $sig['signal']['status'] . '; a proposal is made on an open signal.');
}
$id = (int) $sig['signal']['signal_id'];
$errors = [];
$reason = (string) (req_val('reason') ?? '');
if (!isset(SIGNAL_PROPOSAL_REASONS[$reason])) {
    $errors['reason'] = 'Propose known, watching, explained_by_event, data_defect, false_positive or escalate.';
}
$event = signal_event_check($pdo, $sig, req_val('explaining_event'), 'explaining_event', $errors);
if ($reason === 'explained_by_event' && $event === null && !isset($errors['explaining_event'])) {
    $errors['explaining_event'] = 'A proposal that an event explains the signal names the event.';
}
$note = (string) (req_val('note') ?? '');
if (mb_strlen($note) > 500) {
    $errors['note'] = 'One sentence of why: up to 500 characters.';
}
if ($errors !== []) {
    si_refuse_fields($errors);
}
si_guard($pdo, static function () use ($pdo, $id, $reason, $note, $event): void {
    $pdo->beginTransaction();
    signal_propose($pdo, $id, $reason, $reason === 'explained_by_event' ? $event : null, $note === '' ? null : $note);
    $pdo->commit();
});
si_done('Proposed: ' . SIGNAL_REASON_WORDS[$reason], $id, signal_land($id, 'proposed'), 'signalChanged');
