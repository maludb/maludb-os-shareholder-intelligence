<?php
declare(strict_types=1);
/** Action `report_render` (log `report.render`; reports.run): `report` — the HTML kept under the store and the PDF beside it when the renderer is present. An approved pack keeps the rendering made at approval. */
require_once dirname(__DIR__, 2) . '/app/features/reports/screen.php';
si_handler_begin();
require_right('reports.run');
$pdo = db();
$r = report_from_request($pdo, 'report');
$id = (int) $r['report_id'];
$out = si_guard($pdo, static function () use ($pdo, $id): array {
    $pdo->beginTransaction();
    $o = report_render($pdo, $id);
    $pdo->commit();
    return $o;
});
si_done('Rendered the pack', $id, si_land('/reports/' . $id, 'rendered'), 'reportChanged', ['pdf' => $out['pdf']]);
