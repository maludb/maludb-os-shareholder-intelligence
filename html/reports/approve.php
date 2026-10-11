<?php
declare(strict_types=1);
/** Action `report_approve` (log `report.approve`; reports.approve; confirm; an agent's pauses — other): `report` — a draft becomes approved, its narrative frozen and its rendering kept. */
require_once dirname(__DIR__, 2) . '/app/features/reports/screen.php';
si_handler_begin();
require_right('reports.approve');
$pdo = db();
$r = report_from_request($pdo, 'report');
$id = (int) $r['report_id'];
si_guard($pdo, static function () use ($pdo, $id): void {
    $pdo->beginTransaction();
    report_approve($pdo, $id);
    $pdo->commit();
});
si_done('Approved the pack', $id, si_land('/reports/' . $id, 'approved'), 'reportChanged');
