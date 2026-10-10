<?php
declare(strict_types=1);
/** /dilution?status=&kind= — the current issuer's dilution instruments as cards (screen `dilution-list`): the kind, the counterparty, the facility and what remains as a bar, the price terms, the floating-price flag, the status. */
require_once dirname(__DIR__, 2) . '/app/features/filings/screen.php';
require_right('filings.read');
$pdo = db();
$issuer = filings_issuer_or_empty($pdo, 'dilution-list', 'Dilution');
$filters = ['status' => isset(DILUTION_STATUSES[request_string('status')]) ? request_string('status') : null, 'kind' => isset(DILUTION_KINDS[request_string('kind')]) ? request_string('kind') : null];
$rows = find_dilution($pdo, (int) $issuer['issuer_id'], $filters);
log_screen_view($pdo, 'dilution-list');
if (wants_json()) {
    respond_screen(['issuer_id' => (int) $issuer['issuer_id'], 'filters' => $filters, 'instruments' => array_map('present_instrument', $rows)]);
}
render_screen('Dilution', view('dilution/index.php', ['issuer' => $issuer, 'rows' => $rows, 'filters' => $filters, 'here' => here_url(),
    'notice' => si_notice($_GET['notice'] ?? null, ['deleted' => ['success', 'Deleted.']])]), ['activeNav' => 'filings', 'screen' => 'dilution-list']);
