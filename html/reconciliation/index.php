<?php
declare(strict_types=1);
/** /reconciliation?security= — the reconciliation's runs for a security (screen `reconciliation`): the latest as its two equations, the history, Run now. */
require_once dirname(__DIR__, 2) . '/app/features/positions/screen.php';
require_any_right('positions.read|issuers.read');
$pdo = db();
[$issuer, $security] = positions_context($pdo);
if ($security === null) {
    log_screen_view($pdo, 'reconciliation');
    if (wants_json()) { respond_screen(['security_id' => null]); }
    render_screen('Reconciliation', view('positions/partials/no-issuer.php', ['id' => 'reconciliation', 'title' => 'Reconciliation', 'issuer' => $issuer]), ['activeNav' => 'positions', 'screen' => 'reconciliation']);
    return;
}
$page = reconciliation_page($pdo, (int) $security['security_id']);
log_screen_view($pdo, 'reconciliation');
if (wants_json()) {
    respond_screen(['security_id' => (int) $security['security_id'], 'latest' => $page['latest'] === null ? null : present_reconciliation($page['latest']), 'history' => array_map('present_reconciliation', $page['history'])]);
}
$notices = ['ran_tied' => ['success', 'It ties.'], 'ran_untied' => ['danger', 'It does not tie: the differences are named below.'], 'ran_incomplete' => ['secondary', 'Not enough to compare yet: a register, or the transfer agent\'s Cede count, is needed beside the SPR.']];
render_screen('Reconciliation', view('reconciliation/index.php', ['security' => $security, 'page' => $page, 'notice' => $notices[$_GET['notice'] ?? ''] ?? null, 'tz' => member_timezone()]),
    ['activeNav' => 'positions', 'screen' => 'reconciliation', 'entity' => 'security', 'recordId' => (string) $security['security_id']]);
