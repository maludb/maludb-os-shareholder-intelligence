<?php
declare(strict_types=1);
/** /positions/concentration?security=&from=&to= — top-5/10/20 shares of the DTC total and the index over the window (screen `positions-concentration`). 180 days by default. */
require_once dirname(__DIR__, 2) . '/app/features/positions/screen.php';
require_right('positions.read');
$pdo = db();
[$issuer, $security] = positions_context($pdo);
if ($security === null) {
    log_screen_view($pdo, 'positions-concentration');
    if (wants_json()) { respond_screen(['security_id' => null]); }
    render_screen('Concentration', view('positions/partials/no-issuer.php', ['id' => 'positions-concentration', 'title' => 'Concentration', 'issuer' => $issuer]), ['activeNav' => 'positions', 'screen' => 'positions-concentration']);
    return;
}
$sid = (int) $security['security_id'];
[$from, $to] = positions_window($pdo, $sid, 180);
$series = concentration_series($pdo, $sid, $from, $to);
log_screen_view($pdo, 'positions-concentration');
if (wants_json()) {
    respond_screen(['security_id' => $sid, 'from' => $from, 'to' => $to, 'series' => array_map(static fn (array $r): array => ['position_date' => $r['position_date'], 'total' => (float) $r['total'], 'participants' => (int) $r['participants'],
        'top5_pct' => (float) $r['top5_pct'], 'top10_pct' => (float) $r['top10_pct'], 'top20_pct' => (float) $r['top20_pct'], 'hhi' => (float) $r['hhi']], $series)]);
}
render_screen('Concentration', view('positions/concentration.php', ['security' => $security, 'series' => $series, 'from' => $from, 'to' => $to]),
    ['activeNav' => 'positions', 'screen' => 'positions-concentration', 'entity' => 'security', 'recordId' => (string) $sid]);
