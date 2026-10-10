<?php
declare(strict_types=1);
/** /holders/changes?security=&channel=&date_a=&date_b=&state= — holders between two record dates: new, lost, up, down, same (screen `holders-changes`). A Viewer reads the counts and the entities; the PII roles every holder. */
require_once dirname(__DIR__, 2) . '/app/features/holders/screen.php';
require_once dirname(__DIR__, 2) . '/app/features/holders/aggregate.php';
$pdo = db();
[, $security] = holders_aggregate_start($pdo, 'holders-changes', 'Holder changes');
$sid = (int) $security['security_id'];
$channel = holders_channel();
$dates = holder_record_dates($pdo, $sid, $channel);
$b = is_string(request_date('date_b')) && in_array(request_date('date_b'), $dates, true) ? request_date('date_b') : ($dates[0] ?? null);
$a = is_string(request_date('date_a')) && in_array(request_date('date_a'), $dates, true) ? request_date('date_a') : ($dates[1] ?? null);
if ($a !== null && $b !== null && $a > $b) { [$a, $b] = [$b, $a]; }
$state = in_array(request_string('state'), ['new', 'lost', 'up', 'down', 'same'], true) ? request_string('state') : null;
$page = max(1, request_integer('page') ?? 1);
$ch = $a !== null && $b !== null ? holders_changes($pdo, $sid, $channel, $a, $b, $state, $page) : ['summary' => [], 'rows' => [], 'total' => 0];
log_screen_view($pdo, 'holders-changes');
if (wants_json()) {
    respond_screen(['security_id' => $sid, 'channel' => $channel, 'date_a' => $a, 'date_b' => $b, 'state' => $state, 'record_dates' => $dates,
        'summary' => array_map(static fn (array $r): array => ['state' => $r['state'], 'holders' => (int) $r['holders'], 'shares_a' => (float) $r['shares_a'], 'shares_b' => (float) $r['shares_b'], 'net' => (float) $r['net']], $ch['summary']),
        'holders' => array_map(static fn (array $r): array => ['holder_id' => (int) $r['holder_id'], 'display_name' => $r['display_name'], 'kind' => $r['kind'], 'state' => $r['state'],
            'shares_a' => $r['shares_a'] === null ? null : (float) $r['shares_a'], 'shares_b' => $r['shares_b'] === null ? null : (float) $r['shares_b'], 'net' => (float) $r['net']], $ch['rows'])]);
}
render_screen('Holder changes', view('holders/changes.php', ['security' => $security, 'channel' => $channel, 'dates' => $dates, 'a' => $a, 'b' => $b, 'state' => $state, 'ch' => $ch, 'page' => $page, 'here' => here_url()]),
    ['activeNav' => 'holders', 'screen' => 'holders-changes']);
