<?php
declare(strict_types=1);
/** Action `holder_delete` (log `holder.delete`; holders.merge; an agent's pauses — deletion): `holder` to the tombstone (its accounts and positions wait with it; a manager restores it). */
require_once dirname(__DIR__, 2) . '/app/features/holders/screen.php';
si_handler_begin();
require_right('holders.merge');
$pdo = db();
$id = request_integer('holder');
$h = holder_from_request($pdo);
si_guard($pdo, static function () use ($pdo, $h): void {
    $pdo->beginTransaction();
    holder_delete($pdo, (int) $h['holder_id'], false);
    $pdo->commit();
});
si_done('Moved the holder to the tombstone', (int) $h['holder_id'], si_land('/holders', 'deleted'), 'holderChanged');
