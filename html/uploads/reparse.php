<?php
declare(strict_types=1);
/** Action `upload_reparse` (log `upload.reparse`; uploads.write): read the kept file again (after a template or a parser changed) — its rows replaced, its positions reloaded, the tie judged again. */
require_once dirname(__DIR__, 2) . '/app/features/uploads/handler.php';
si_handler_begin();
require_right('uploads.write');
$pdo = db();
$u = upload_from_request($pdo);
if ($u['deleted']) {
    refuse(422, 'The upload is in the tombstone: restore it first.');
}
log_activity($pdo, 'upload.reparse', 'import', (int) $u['import_id'], ['issuer_id' => (int) $u['issuer_id'], 'security_id' => (int) $u['security_id'], 'import_id' => (int) $u['import_id']]);
$res = upload_ingest($pdo, (int) $u['import_id']);
si_done($res['status'] === 'failed' ? 'Read it again, and it could not be read: ' . $res['tie_note'] : 'Read it again: ' . ($res['tied'] ? 'tied' : 'does not tie — ' . $res['tie_note']), (int) $u['import_id'],
    si_land('/uploads/' . (int) $u['import_id'], $res['status'] === 'failed' ? 'failed' : ($res['tied'] ? 'tied' : 'untied')), 'uploadChanged', ['tied' => $res['tied']]);
