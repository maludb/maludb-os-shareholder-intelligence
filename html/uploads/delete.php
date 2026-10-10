<?php
declare(strict_types=1);
/**
 * Action `upload_delete` (log `upload.delete`; uploads.delete; confirm; an agent's pauses — deletion; undo upload_restore): the upload to the
 * tombstone — its positions and the share count it wrote go now, its raw rows stay for the restore; an earlier SPR this one superseded loads its
 * dates again; the reconciliation runs.
 */
require_once dirname(__DIR__, 2) . '/app/features/uploads/handler.php';
si_handler_begin();
require_right('uploads.delete');
$pdo = db();
$u = upload_from_request($pdo);
if ($u['deleted']) {
    refuse(422, 'The upload is already in the tombstone.');
}
$pdo->beginTransaction();
$pdo->prepare('SELECT si_import_delete(:i, false)')->execute(['i' => (int) $u['import_id']]);
$back = upload_reclaim_superseded($pdo, (int) $u['import_id']);
log_activity($pdo, 'upload.delete', 'import', (int) $u['import_id'], ['issuer_id' => (int) $u['issuer_id'], 'security_id' => (int) $u['security_id'], 'import_id' => (int) $u['import_id'],
    'before' => ['kind' => $u['kind'], 'period_from' => $u['period_from'], 'period_to' => $u['period_to'], 'rows_made' => (int) $u['rows_made']], 'after' => ['reloaded' => $back]]);
$pdo->commit();
if ($u['period_to'] !== null) {
    reconciliation_after($pdo, (int) $u['security_id'], (string) $u['period_to'], 'after the delete of upload ' . (int) $u['import_id']);
}
si_done('Moved the upload to the tombstone' . ($back > 0 ? '; the earlier report it overlapped is back' : ''), (int) $u['import_id'], si_land('/uploads/' . (int) $u['import_id'], 'deleted'), 'uploadChanged');
