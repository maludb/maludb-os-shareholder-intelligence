<?php
declare(strict_types=1);
/** /holders/custodians?security=&record_date= — NOBO holders and shares by custodian (participant) and account kind on a record date, beside the OBO remainder (screen `holders-custodians`). */
require_once dirname(__DIR__, 2) . '/app/features/holders/screen.php';
require_once dirname(__DIR__, 2) . '/app/features/holders/aggregate.php';
$pdo = db();
[, $security] = holders_aggregate_start($pdo, 'holders-custodians', 'Custodians');
$sid = (int) $security['security_id'];
$dates = holder_record_dates($pdo, $sid, 'nobo');
$date = holders_pick_date($dates);
$c = holders_custodians($pdo, $sid, $date);
$hasListing = $date !== null && (int) one_value($pdo, 'SELECT count(*) FROM mcp_nobo_broker_rows WHERE security_id = :s AND record_date = :d', ['s' => $sid, 'd' => $date]) > 0;
log_screen_view($pdo, 'holders-custodians');
if (wants_json()) {
    respond_screen(['security_id' => $sid, 'record_date' => $date, 'record_dates' => $dates, 'listing' => $hasListing,
        'nobo' => array_map(static fn (array $x): array => ['participant_id' => $x['participant_id'] === null ? null : (int) $x['participant_id'], 'number' => $x['number'], 'name' => $x['name'], 'class_key' => $x['class_key'],
            'account_kind' => $x['account_kind'], 'holders' => (int) $x['holders'], 'shares' => (float) $x['shares']], $c['nobo']),
        'obo' => array_map(static fn (array $x): array => ['participant_id' => (int) $x['participant_id'], 'number' => $x['number'], 'name' => $x['name'], 'spr_shares' => $x['spr_shares'] === null ? null : (float) $x['spr_shares'],
            'spr_date' => $x['spr_date'], 'nobo_shares' => $x['nobo_shares'] === null ? null : (float) $x['nobo_shares'], 'nobo_count' => $x['nobo_count'] === null ? null : (int) $x['nobo_count'],
            'obo_remainder' => $x['obo_remainder'] === null ? null : (float) $x['obo_remainder']], $c['obo'])]);
}
render_screen('Custodians', view('holders/custodians.php', ['security' => $security, 'dates' => $dates, 'date' => $date, 'c' => $c, 'hasListing' => $hasListing]), ['activeNav' => 'holders', 'screen' => 'holders-custodians']);
