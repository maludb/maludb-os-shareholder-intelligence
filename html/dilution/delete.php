<?php
declare(strict_types=1);
/** Action `dilution_delete` (log `dilution.delete`; events.write; an agent's pauses — deletion): `instrument`. */
require_once dirname(__DIR__, 2) . '/app/features/filings/screen.php';
si_handler_begin();
require_right('events.write');
$pdo = db();
$d = instrument_from_request($pdo);
si_guard($pdo, static function () use ($pdo, $d): void {
    $pdo->beginTransaction();
    dilution_delete($pdo, (int) $d['instrument_id']);
    $pdo->commit();
});
si_done('Deleted the instrument', (int) $d['instrument_id'], si_land('/dilution', 'deleted'), 'dilutionChanged');
