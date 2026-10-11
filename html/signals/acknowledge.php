<?php
declare(strict_types=1);
/** Action `signal_acknowledge` (log `signal.acknowledge`; signals.work; people): **signal**, reason (known or watching — known when left out), note. */
require_once dirname(__DIR__, 2) . '/app/features/signals/screen.php';
si_handler_begin();
require_right('signals.work');
require_human();
$pdo = db();
$sig = signal_from_request($pdo, 'signal');
signal_open_or_refuse($sig);
$id = (int) $sig['signal']['signal_id'];
$errors = [];
$reason = (string) (req_val('reason') ?? '') ?: 'known';
if (!isset(SIGNAL_ACK_REASONS[$reason])) {
    $errors['reason'] = 'Acknowledge as known or watching.';
}
$note = (string) (req_val('note') ?? '');
if (mb_strlen($note) > 1000) {
    $errors['note'] = 'A note is up to 1,000 characters.';
}
if ($errors !== []) {
    si_refuse_fields($errors);
}
si_guard($pdo, static function () use ($pdo, $id, $reason, $note): void {
    $pdo->beginTransaction();
    signal_dispose($pdo, $id, 'acknowledged', $reason, $note === '' ? null : $note, null);
    $pdo->commit();
});
si_done('Acknowledged the signal (' . SIGNAL_REASON_WORDS[$reason] . ')', $id, signal_land($id, 'acknowledged'), 'signalChanged');
