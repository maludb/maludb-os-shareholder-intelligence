<?php
declare(strict_types=1);
/** Action `note_add` (log `note.add`; notes.write): a note on a record the caller may see (a natural-person holder's only for an Analyst). The log carries its length, never its words. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/tags/write.php';
require_once dirname(__DIR__, 2) . '/app/features/notes/write.php';
si_handler_begin();
require_right('notes.write');
$pdo = db();
[$kind, $id, $iss] = record_from_request($pdo, NOTE_KINDS);
$body = (string) req_val('body');
if ($body === '' || mb_strlen($body) > 4000) {
    si_refuse_fields(['body' => $body === '' ? 'Write the note.' : 'A note is up to 4,000 characters.']);
}
$pdo->beginTransaction();
$nid = note_add($pdo, $kind, $id, $body, (int) current_member_id());
log_activity($pdo, 'note.add', $kind, $id, ['issuer_id' => $iss, 'after' => ['note_id' => $nid, 'length' => mb_strlen($body)]]);
$pdo->commit();
si_done('Added the note', $nid, si_land(return_path('/'), 'note_added', 'notes'), 'noteChanged');
