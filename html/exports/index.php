<?php
declare(strict_types=1);
/**
 * /exports?kind=&all= — my exports and, with export.shared, everyone's (screen `export-list`): the kind, the rows, a chip when names are in it, the size, when it expires; Download.
 * A person with export.own also sees the form that asks for another.
 */
require_once dirname(__DIR__, 2) . '/app/features/reports/screen.php';
require_once dirname(__DIR__, 2) . '/app/features/exports/queries.php';
require_login();
$pdo = db();
$kind = isset(EXPORT_KINDS[request_string('kind')]) ? request_string('kind') : null;
$all = request_bool('all') && has_right('export.shared');
$rows = find_exports($pdo, (int) current_member_id(), $all, $kind);
log_screen_view($pdo, 'export-list');
if (wants_json()) {
    respond_screen(['filters' => ['kind' => $kind, 'all' => $all], 'exports' => array_map('present_export', $rows)]);
}
$issuer = current_issuer($pdo);
$securities = $issuer === null ? [] : find_securities($pdo, (int) $issuer['issuer_id']);
$notices = ['built' => ['success', 'Built the export. It is kept for seven days.']];
render_screen('Exports', view('exports/index.php', ['rows' => $rows, 'kind' => $kind, 'all' => $all, 'shared' => has_right('export.shared'), 'may' => has_right('export.own'), 'holders' => has_right('holders.read'),
    'issuer' => $issuer, 'securities' => $securities, 'tz' => member_timezone(), 'here' => here_url(), 'back' => back_link(), 'notice' => $notices[$_GET['notice'] ?? ''] ?? null]), ['activeNav' => 'reports', 'screen' => 'export-list']);
