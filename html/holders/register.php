<?php
declare(strict_types=1);
/** /holders/register?security=&record_date= — the register on a record date (screen `holders-register`): the components the reconciliation reads (Cede & Co, book and DRS, certificates, plan) for everyone; the rows by position type with Cede flagged for the PII roles. */
require_once dirname(__DIR__, 2) . '/app/features/holders/screen.php';
require_once dirname(__DIR__, 2) . '/app/features/holders/aggregate.php';
$pdo = db();
[, $security] = holders_aggregate_start($pdo, 'holders-register', 'The register');
$sid = (int) $security['security_id'];
$dates = holder_record_dates($pdo, $sid, 'register');
$date = holders_pick_date($dates);
$reg = register_on($pdo, $sid, $date);
$types = holders_by_type($pdo, $sid, $date);
$rec = $date === null ? null : ($pdo->query("SELECT * FROM mcp_share_reconciliations WHERE security_id = $sid AND register_date = '" . $date . "' ORDER BY ran_at DESC LIMIT 1")->fetch() ?: null);
log_screen_view($pdo, 'holders-register');
if (wants_json()) {
    respond_screen(['security_id' => $sid, 'record_date' => $date, 'record_dates' => $dates, 'components' => $reg['components'], 'import_id' => $reg['import'] === null ? null : (int) $reg['import']['import_id'],
        'by_type' => array_map(static fn (array $x): array => ['account_kind' => $x['account_kind'], 'holders' => (int) $x['holders'], 'shares' => (float) $x['shares']], $types),
        'reconciliation' => $rec === null ? null : present_reconciliation($rec),
        'rows' => array_map(static fn (array $x): array => ['register_row_id' => (int) $x['register_row_id'], 'position_type' => $x['position_type'], 'registration_name' => $x['registration_name'], 'shares' => $x['shares'] === null ? null : (float) $x['shares'],
            'is_cede' => (bool) $x['is_cede'], 'holder_id' => $x['holder_id'] === null ? null : (int) $x['holder_id']], $reg['rows'])]);
}
render_screen('The register', view('holders/register.php', ['security' => $security, 'dates' => $dates, 'date' => $date, 'reg' => $reg, 'types' => $types, 'rec' => $rec, 'here' => here_url()]), ['activeNav' => 'holders', 'screen' => 'holders-register']);
