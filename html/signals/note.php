<?php
declare(strict_types=1);
/** Action `signal_note` (log `signal.note`; notes.write): **signal**, **body**. The log carries the note's length, never its words. */
require_once dirname(__DIR__, 2) . '/app/features/signals/screen.php';
si_handler_begin();
require_right('notes.write');
$pdo = db();
$sig = signal_from_request($pdo, 'signal');
$id = (int) $sig['signal']['signal_id'];
$body = (string) (req_val('body') ?? '');
if ($body === '' || mb_strlen($body) > 4000) {
    si_refuse_fields(['body' => $body === '' ? 'Write the note.' : 'A note is up to 4,000 characters.']);
}
$nid = si_guard($pdo, static function () use ($pdo, $id, $body): int {
    $pdo->beginTransaction();
    $nid = signal_note($pdo, $id, $body, (int) current_member_id());
    $pdo->commit();
    return $nid;
});
si_done('Added the note', $nid, si_land(return_path('/signals/' . $id), 'note_added', 'notes'), 'noteChanged');
