<?php
declare(strict_types=1);
/** Action `upload_restore` (log `upload.restore`; uploads.delete; undo upload_delete): bring an upload back from the tombstone — its positions reload from its kept rows. */
require_once dirname(__DIR__, 2) . '/app/features/uploads/handler.php';
si_handler_begin();
require_right('uploads.delete');
$pdo = db();
$u = upload_from_request($pdo);
if (!$u['deleted']) {
    refuse(422, 'The upload is not in the tombstone.');
}
$pdo->beginTransaction();
$pdo->prepare('SELECT si_import_delete(:i, true)')->execute(['i' => (int) $u['import_id']]);
log_activity($pdo, 'upload.restore', 'import', (int) $u['import_id'], ['issuer_id' => (int) $u['issuer_id'], 'security_id' => (int) $u['security_id'], 'import_id' => (int) $u['import_id']]);
$pdo->commit();
if ($u['period_to'] !== null) {
    reconciliation_after($pdo, (int) $u['security_id'], (string) $u['period_to'], 'after the restore of upload ' . (int) $u['import_id']);
}
si_done('Restored the upload', (int) $u['import_id'], si_land(return_path('/uploads/' . (int) $u['import_id']), 'restored'), 'uploadChanged');
