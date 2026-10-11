<?php
declare(strict_types=1);
/** /reports?kind=&status= — the current issuer's packs as cards (screen `report-list`): the kind, the period, the status chip, who approved it. Drafts are seen by reports.run alone (mcp_reports). */
require_once dirname(__DIR__, 2) . '/app/features/reports/screen.php';
require_any_right('reports.read|reports.run');
$pdo = db();
$issuer = reports_issuer_or_empty($pdo, 'report-list', 'Reports');
$iid = (int) $issuer['issuer_id'];
$f = ['kind' => isset(REPORT_KINDS[request_string('kind')]) ? request_string('kind') : null, 'status' => isset(REPORT_STATUSES[request_string('status')]) ? request_string('status') : null];
$rows = find_reports($pdo, $iid, $f);
log_screen_view($pdo, 'report-list');
if (wants_json()) {
    respond_screen(['issuer_id' => $iid, 'filters' => $f, 'reports' => array_map(static fn (array $r): array => present_report($r, false), $rows)]);
}
$notices = ['deleted' => ['success', 'Deleted the pack.']];
render_screen('Reports', view('reports/index.php', ['issuer' => $issuer, 'f' => $f, 'rows' => $rows, 'may' => has_right('reports.run'), 'here' => here_url(), 'notice' => $notices[$_GET['notice'] ?? ''] ?? null]),
    ['activeNav' => 'reports', 'screen' => 'report-list']);
