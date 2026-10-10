<?php
declare(strict_types=1);
/** Action `matcher_run` (log `matcher.run`; uploads.write): `upload` — every unmatched row of a usable NOBO list or register scored again (remembered rejections stand); over 200 waiting, the worker does it. */
require_once dirname(__DIR__, 3) . '/app/features/holders/screen.php';
require_once dirname(__DIR__, 3) . '/app/features/uploads/queries.php';
si_handler_begin();
require_right('uploads.write');
$pdo = db();
$u = upload_from_request($pdo);
if ($u['deleted'] || !in_array($u['kind'], ['nobo', 'register'], true) || $u['status'] !== 'done' || !($u['tied'] === true || $u['override_at'] !== null)) {
    refuse(422, 'Only a NOBO list or a register that tied (or was accepted) is matched.');
}
$m = si_guard($pdo, static fn (): ?array => upload_match_or_queue($pdo, (int) $u['import_id']));
if ($m === null) {
    si_done('Queued: the worker matches the rows again', (int) $u['import_id'], si_land('/uploads/' . (int) $u['import_id'], 'matching'), 'uploadChanged', ['matcher' => null, 'queued' => true]);
}
si_done(sprintf('Matched again: %d by the rules, %d new, %d proposed', $m['auto'], $m['new'], $m['proposed']), (int) $u['import_id'], si_land('/uploads/' . (int) $u['import_id'], 'matched'), 'uploadChanged', ['matcher' => $m]);
