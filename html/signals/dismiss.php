<?php
declare(strict_types=1);
/**
 * Action `signal_dismiss` (log `signal.dismiss`; signals.work; people): **signal**, **reason** (explained_by_event, data_defect, false_positive or other),
 * explaining_event (required for explained_by_event — one of the issuer's events around the window), note (required for other).
 */
require_once dirname(__DIR__, 2) . '/app/features/signals/screen.php';
si_handler_begin();
require_right('signals.work');
require_human();
$pdo = db();
$sig = signal_from_request($pdo, 'signal');
signal_open_or_refuse($sig);
$id = (int) $sig['signal']['signal_id'];
$errors = [];
$reason = (string) (req_val('reason') ?? '');
if (!isset(SIGNAL_DISMISS_REASONS[$reason])) {
    $errors['reason'] = 'Say why: explained by an event, a data defect, a false positive, or another reason.';
}
$event = signal_event_check($pdo, $sig, req_val('explaining_event'), 'explaining_event', $errors);
if ($reason === 'explained_by_event' && $event === null && !isset($errors['explaining_event'])) {
    $errors['explaining_event'] = 'A signal explained by an event names the event: choose it.';
}
$note = (string) (req_val('note') ?? '');
if (mb_strlen($note) > 1000) {
    $errors['note'] = 'A note is up to 1,000 characters.';
} elseif ($reason === 'other' && $note === '') {
    $errors['note'] = 'Another reason is said in the note.';
}
if ($errors !== []) {
    si_refuse_fields($errors);
}
si_guard($pdo, static function () use ($pdo, $id, $reason, $note, $event): void {
    $pdo->beginTransaction();
    signal_dispose($pdo, $id, 'dismissed', $reason, $note === '' ? null : $note, $reason === 'explained_by_event' ? $event : null);
    $pdo->commit();
});
si_done('Dismissed the signal (' . SIGNAL_REASON_WORDS[$reason] . ')', $id, signal_land($id, 'dismissed'), 'signalChanged');
