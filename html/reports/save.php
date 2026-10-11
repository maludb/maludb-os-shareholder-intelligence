<?php
declare(strict_types=1);
/**
 * Actions `report_draft` and `report_update` (logs `report.draft`, `report.update`; reports.run): `issuer`, `period_from`, `period_to` to draft (kind, title, narrative in Markdown,
 * sections[] optional); `report` to change a DRAFT (a field left out stays; an approved pack is frozen). record_id = the report. An agent may draft; a manager approves.
 */
require_once dirname(__DIR__, 2) . '/app/features/reports/screen.php';
si_handler_begin();
require_right('reports.run');
$pdo = db();
$r = request_integer('report') !== null ? report_from_request($pdo, 'report') : null;
$issuer = $r !== null ? (int) $r['issuer_id'] : (request_integer('issuer') ?? (int) (current_issuer($pdo)['issuer_id'] ?? 0));
if ($r === null && find_issuer($pdo, $issuer) === null) { si_refuse_fields(['issuer' => 'Choose the issuer.']); }
if ($r !== null && $r['status'] !== 'draft') { refuse(422, 'The pack is ' . $r['status'] . ' and frozen; draft a new one to correct it.'); }
$f = report_fields($pdo, $r);
$f['issuer'] = $issuer;
$id = si_guard($pdo, static function () use ($pdo, $r, $f): int {
    $pdo->beginTransaction();
    $id = report_save($pdo, $r === null ? null : (int) $r['report_id'], $f, (int) current_member_id());
    $pdo->commit();
    return $id;
});
si_done($r === null ? 'Drafted the pack' : 'Saved the pack', $id, si_land(return_path('/reports/' . $id), $r === null ? 'drafted' : 'saved'), 'reportChanged');
