<?php
declare(strict_types=1);
/**
 * /short?security=&from=&to= — the short side (screen `short`): the tiles and the one chart. The window defaults to the last 180 days, and on
 * a phone (the page asks once, by its width) to 90.
 */
require_once dirname(__DIR__, 2) . '/app/features/short/screen.php';
require_right('short.read');
$pdo = db();
[, $security] = short_context_or_empty($pdo, 'short', 'Short side');
$sid = (int) $security['security_id'];
$given = request_date('from') !== null;
[$from, $to] = short_window(180);
$ov = overlay($pdo, $sid, $from, $to);
$svf = short_vs_float($pdo, $sid, $to);
$status = threshold_runs($pdo, $sid)['status'];
$failRows = fails($pdo, $sid, (new DateTimeImmutable($to))->modify('-90 days')->format('Y-m-d'), $to);
$ratioRows = short_volume($pdo, $sid, 'CNMS', (new DateTimeImmutable($to))->modify('-30 days')->format('Y-m-d'), $to);
$borrowRows = borrow($pdo, $sid, null, $to);
$sources = short_sources($pdo, $sid);
log_screen_view($pdo, 'short');
if (wants_json()) {
    respond_screen(['security_id' => $sid, 'from' => $from, 'to' => $to, 'caveat' => SI_SHORT_VOLUME_CAVEAT,
        'short_vs_float' => $svf === null ? null : ['settlement_date' => $svf['settlement_date'], 'short_shares' => (float) $svf['short_shares'], 'pct_of_float' => si_num_or_null($svf['pct_of_float']),
            'pct_of_outstanding' => si_num_or_null($svf['pct_of_outstanding']), 'days_to_cover' => si_num_or_null($svf['days_to_cover']), 'avg_daily_volume' => si_num_or_null($svf['avg_daily_volume'])],
        'threshold' => $status === null ? null : ['on_list' => (bool) $status['on_list'], 'list' => $status['list'], 'since' => $status['since'], 'consecutive_days' => (int) $status['consecutive_days'], 'latest_list_date' => $status['latest_list_date']],
        'points' => array_map('present_overlay_point', $ov['points']), 'events' => array_map(static fn ($e) => ['event_id' => (int) $e['event_id'], 'on_date' => $e['on_date'], 'kind' => $e['kind'], 'title' => $e['title'], 'severity' => $e['severity']], $ov['events']),
        'sources' => ['short_interest' => $sources['short_interest'], 'short_volume' => $sources['short_volume'], 'fails' => $sources['fails'], 'threshold' => $sources['threshold'], 'prices' => $sources['prices'], 'borrow' => $sources['borrow']]]);
}
render_screen('Short side', view('short/index.php', ['security' => $security, 'from' => $from, 'to' => $to, 'svf' => $svf, 'status' => $status, 'fail' => $failRows[0] ?? null, 'ratio' => $ratioRows[0] ?? null,
    'borrowLast' => $borrowRows[0] ?? null, 'ov' => $ov, 'sources' => $sources, 'canBorrow' => has_right('borrow.write'),
    'narrowDefault' => $given ? null : (new DateTimeImmutable($to))->modify('-90 days')->format('Y-m-d')]), ['activeNav' => 'short', 'screen' => 'short']);
