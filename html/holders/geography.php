<?php
declare(strict_types=1);
/** /holders/geography?security=&channel=&record_date= — holders by country and state on a record date (screen `holders-geography`). Counts and shares, never a name. */
require_once dirname(__DIR__, 2) . '/app/features/holders/screen.php';
require_once dirname(__DIR__, 2) . '/app/features/holders/aggregate.php';
$pdo = db();
[, $security] = holders_aggregate_start($pdo, 'holders-geography', 'Geography');
$sid = (int) $security['security_id'];
$channel = holders_channel();
$dates = holder_record_dates($pdo, $sid, $channel);
$date = holders_pick_date($dates);
$rows = holders_geography($pdo, $sid, $channel, $date);
log_screen_view($pdo, 'holders-geography');
if (wants_json()) {
    respond_screen(['security_id' => $sid, 'channel' => $channel, 'record_date' => $date, 'record_dates' => $dates,
        'rows' => array_map(static fn (array $x): array => ['country' => $x['country'], 'state' => $x['state'], 'holders' => (int) $x['holders'], 'shares' => (float) $x['shares']], $rows)]);
}
render_screen('Geography', view('holders/geography.php', ['security' => $security, 'channel' => $channel, 'dates' => $dates, 'date' => $date, 'rows' => $rows]), ['activeNav' => 'holders', 'screen' => 'holders-geography']);
