<?php
declare(strict_types=1);
/** Action `report_delete` (log `report.delete`; reports.approve; confirm; an agent's pauses — deletion): `report` goes to the tombstone (the admin restores or purges it). */
require_once dirname(__DIR__, 2) . '/app/features/reports/screen.php';
si_handler_begin();
require_right('reports.approve');
$pdo = db();
$r = report_from_request($pdo, 'report');
$id = (int) $r['report_id'];
si_guard($pdo, static function () use ($pdo, $id): void {
    $pdo->beginTransaction();
    report_delete($pdo, $id);
    $pdo->commit();
});
si_done('Deleted the pack', $id, si_land('/reports', 'deleted'), 'reportChanged');
