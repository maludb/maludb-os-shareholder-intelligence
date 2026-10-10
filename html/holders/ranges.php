<?php
declare(strict_types=1);
/** /holders/ranges?security=&channel=&record_date= — holders by share range on a record date (screen `holders-ranges`): the list's, and Broadridge's Share Range Analysis with the OBO population beside it. Counts and shares, never a name. */
require_once dirname(__DIR__, 2) . '/app/features/holders/screen.php';
require_once dirname(__DIR__, 2) . '/app/features/holders/aggregate.php';
$pdo = db();
[, $security] = holders_aggregate_start($pdo, 'holders-ranges', 'Share ranges');
$sid = (int) $security['security_id'];
$channel = holders_channel();
$dates = holder_record_dates($pdo, $sid, $channel);
$srDates = array_map('strval', $pdo->query('SELECT DISTINCT record_date FROM mcp_share_range_reports WHERE security_id = ' . $sid . ' ORDER BY record_date DESC')->fetchAll(PDO::FETCH_COLUMN));
$all = array_values(array_unique(array_merge($dates, $srDates)));
rsort($all);
$date = holders_pick_date($all);
$r = holders_ranges($pdo, $sid, $channel, $date);
log_screen_view($pdo, 'holders-ranges');
if (wants_json()) {
    respond_screen(['security_id' => $sid, 'channel' => $channel, 'record_date' => $date, 'record_dates' => $all,
        'list' => array_map(static fn (array $x): array => ['range' => $x['range_label'], 'from' => (float) $x['range_from'], 'holders' => (int) $x['holders'], 'shares' => (float) $x['shares'], 'share_pct' => $x['share_pct'] === null ? null : (float) $x['share_pct']], $r['list']),
        'broadridge' => array_map(static fn (array $x): array => ['record_date' => $x['record_date'], 'range' => $x['range_label'], 'from' => (float) $x['range_from'], 'all_accounts' => (int) $x['all_accounts'], 'all_shares' => (float) $x['all_shares'],
            'obo_accounts' => (int) $x['obo_accounts'], 'obo_shares' => (float) $x['obo_shares'], 'nobo_accounts' => (int) $x['nobo_accounts'], 'nobo_shares' => (float) $x['nobo_shares']], $r['broadridge']),
        'obo_totals' => array_map(static fn (array $x): array => ['record_date' => $x['record_date'], 'all_accounts' => (int) $x['all_accounts'], 'obo_accounts' => (int) $x['obo_accounts'], 'obo_shares' => (float) $x['obo_shares'],
            'obo_pct_of_accounts' => $x['obo_pct_of_accounts'] === null ? null : (float) $x['obo_pct_of_accounts']], $r['obo_totals'])]);
}
render_screen('Share ranges', view('holders/ranges.php', ['security' => $security, 'channel' => $channel, 'dates' => $all, 'date' => $date, 'r' => $r]), ['activeNav' => 'holders', 'screen' => 'holders-ranges']);
