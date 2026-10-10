<?php
declare(strict_types=1);
/** Action `holder_restore` (log `holder.restore`; holders.merge): `holder` back from the tombstone. */
require_once dirname(__DIR__, 2) . '/app/features/holders/screen.php';
si_handler_begin();
require_right('holders.merge');
$pdo = db();
$id = request_integer('holder');
$t = $id === null ? null : holder_tombstone($pdo, $id);
if ($t === null || $t['merged_into_id'] !== null) {
    refuse($t === null ? 404 : 422, $t === null ? 'Not found.' : 'A merged holder is not restored: it lives on in holder ' . (int) $t['merged_into_id'] . '.');
}
si_guard($pdo, static function () use ($pdo, $id): void {
    $pdo->beginTransaction();
    holder_delete($pdo, (int) $id, true);
    $pdo->commit();
});
si_done('Restored the holder', (int) $id, si_land('/holders/' . (int) $id, 'restored'), 'holderChanged');
