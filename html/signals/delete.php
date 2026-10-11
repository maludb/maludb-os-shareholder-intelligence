<?php
declare(strict_types=1);
/** Action `signal_delete` (log `signal.delete`; rules.manage; confirm; an agent's pauses — deletion): **signal** (raised on data since corrected), reason. */
require_once dirname(__DIR__, 2) . '/app/features/signals/screen.php';
si_handler_begin();
require_right('rules.manage');
$pdo = db();
$sig = signal_from_request($pdo, 'signal');
$id = (int) $sig['signal']['signal_id'];
$reason = (string) (req_val('reason') ?? '');
if ($reason === '' || mb_strlen($reason) > 500) {
    si_refuse_fields(['reason' => $reason === '' ? 'Say which data was corrected: a signal is deleted only with its reason.' : 'The reason is up to 500 characters.']);
}
si_guard($pdo, static function () use ($pdo, $id, $reason): void {
    $pdo->beginTransaction();
    signal_delete($pdo, $id, $reason);
    $pdo->commit();
});
si_done('Deleted the signal', $id, si_land('/signals?security=' . (int) $sig['signal']['security_id'], 'deleted'), 'signalChanged');
