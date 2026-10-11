<?php
declare(strict_types=1);
/** Action `signal_escalate` (log `signal.escalate`; signals.work; people): **signal**, note (to the IR Manager). The IR Managers and the escalation member are told. */
require_once dirname(__DIR__, 2) . '/app/features/signals/screen.php';
si_handler_begin();
require_right('signals.work');
require_human();
$pdo = db();
$sig = signal_from_request($pdo, 'signal');
if ($sig['signal']['status'] !== 'open') {
    refuse(422, 'The signal is ' . $sig['signal']['status'] . ' already; only an open signal is escalated.');
}
$id = (int) $sig['signal']['signal_id'];
$note = (string) (req_val('note') ?? '');
if (mb_strlen($note) > 1000) {
    si_refuse_fields(['note' => 'A note is up to 1,000 characters.']);
}
si_guard($pdo, static function () use ($pdo, $id, $note): void {
    $pdo->beginTransaction();
    signal_dispose($pdo, $id, 'escalated', 'escalated', $note === '' ? null : $note, null);
    $pdo->commit();
});
si_done('Escalated the signal to the IR Manager', $id, signal_land($id, 'escalated'), 'signalChanged');
