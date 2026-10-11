<?php
declare(strict_types=1);
/** /reports/new?kind=&from=&to= and /reports/{id}/edit — draft a pack by hand or change a draft's period, sections and narrative (screens `report-add`, `report-edit`; reports.run; frozen once approved). */
require_once dirname(__DIR__, 2) . '/app/features/reports/screen.php';
require_right('reports.run');
$pdo = db();
$r = request_integer('id') !== null ? report_from_request($pdo, 'id') : null;
if ($r !== null && $r['status'] !== 'draft') { refuse(422, 'The pack is ' . $r['status'] . ' and frozen; draft a new one to correct it.'); }
$issuer = $r !== null ? find_issuer($pdo, (int) $r['issuer_id']) : current_issuer($pdo);
if ($issuer === null) { refuse(404, 'Not found.'); }
$issuer['issuer_id'] ??= $issuer['id'];
$kind = request_string('kind');
$from = request_date('from');
$to = request_date('to');
$today = (new DateTimeImmutable('now', si_market_tz($pdo)))->format('Y-m-d');
$v = $r ?? ['kind' => isset(REPORT_KINDS[$kind]) ? $kind : 'weekly_pack', 'title' => '', 'period_from' => is_string($from) ? $from : (new DateTimeImmutable($today))->modify('-6 days')->format('Y-m-d'),
            'period_to' => is_string($to) ? $to : $today, 'narrative' => '', 'sections' => json_encode(array_keys(REPORT_SECTIONS))];
log_screen_view($pdo, $r === null ? 'report-add' : 'report-edit');
if (wants_json()) {
    respond_screen(['report' => $r === null ? null : present_report($r), 'kinds' => REPORT_KINDS, 'sections' => REPORT_SECTIONS]);
}
render_screen($r === null ? 'Draft a pack' : 'Edit the pack', view('reports/form.php', ['r' => $r, 'v' => $v, 'issuer' => $issuer]), ['activeNav' => 'reports', 'screen' => $r === null ? 'report-add' : 'report-edit']);
