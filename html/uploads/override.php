<?php
declare(strict_types=1);
/** Action `upload_override` (log `upload.override`; issuers.manage; confirm; an agent's pauses — other): accept an untied upload with a note — its rows become usable, the difference on record. */
require_once dirname(__DIR__, 2) . '/app/features/uploads/handler.php';
si_handler_begin();
require_right('issuers.manage');
$pdo = db();
$u = upload_from_request($pdo);
$note = (string) req_val('note');
if ($note === '' || mb_strlen($note) > 1000) {
    si_refuse_fields(['note' => $note === '' ? 'Say why the difference is accepted.' : 'A note is up to 1,000 characters.']);
}
if ($u['deleted'] || $u['status'] !== 'done' || $u['tied'] !== false || $u['override_at'] !== null) {
    refuse(422, $u['override_at'] !== null ? 'This upload is already overridden.' : 'Only an untied upload that was read is overridden.');
}
$pdo->beginTransaction();
upload_override($pdo, (int) $u['import_id'], $note, (int) current_member_id());
log_activity($pdo, 'upload.override', 'import', (int) $u['import_id'], ['issuer_id' => (int) $u['issuer_id'], 'security_id' => (int) $u['security_id'], 'import_id' => (int) $u['import_id'],
    'after' => ['tie_note' => mb_substr((string) $u['tie_note'], 0, 200), 'note_length' => mb_strlen($note)]]);
$pdo->commit();
if ($u['period_to'] !== null) {
    reconciliation_after($pdo, (int) $u['security_id'], (string) $u['period_to'], 'after the override of upload ' . (int) $u['import_id']);
}
si_done('Accepted the upload with its difference on record', (int) $u['import_id'], si_land('/uploads/' . (int) $u['import_id'], 'overridden'), 'uploadChanged');
