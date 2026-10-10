<?php
declare(strict_types=1);
/** /positions?security=&date=&class=&q=&from= — who held at DTC on a date, ranked (screen `positions`). The latest position date by default; the window opens 30 days earlier. */
require_once dirname(__DIR__, 2) . '/app/features/positions/screen.php';
require_right('positions.read');
$pdo = db();
[$issuer, $security] = positions_context($pdo);
if ($security === null) {
    log_screen_view($pdo, 'positions');
    if (wants_json()) { respond_screen(['security_id' => null, 'positions' => []]); }
    render_screen('Positions', view('positions/partials/no-issuer.php', ['id' => 'positions', 'title' => 'Positions at DTC', 'issuer' => $issuer]), ['activeNav' => 'positions', 'screen' => 'positions']);
    return;
}
$sid = (int) $security['security_id'];
$dates = position_dates($pdo, $sid);
$date = in_array(request_string('date'), $dates, true) ? request_string('date') : ($dates[count($dates) - 1] ?? null);
$k = $date === null ? false : array_search($date, $dates, true);
$prev = $k !== false && $k > 0 ? $dates[$k - 1] : null;
$from = request_date('from') ?: null;
if ($from === null && $date !== null) {
    $earlier = array_values(array_filter($dates, static fn ($d) => $d >= date('Y-m-d', strtotime($date . ' -30 days'))));
    $from = $earlier[0] ?? $date;
}
$classes = participant_classes($pdo);
$class = isset($classes[request_string('class')]) ? request_string('class') : null;
$q = trim(request_string('q')) !== '' ? mb_substr(trim(request_string('q')), 0, 60) : null;
if ($q !== null && preg_match('~^\d{1,4}$~', $q)) { $q = str_pad($q, 4, '0', STR_PAD_LEFT); }
$p = $date === null ? ['rows' => [], 'total' => null, 'gone' => []] : positions_on($pdo, $sid, $date, $prev, $from, $class, $q);
$rec = reconciliation_page($pdo, $sid)['latest'];
log_screen_view($pdo, 'positions');
if (wants_json()) {
    respond_screen(['security_id' => $sid, 'position_date' => $date, 'previous_date' => $prev, 'from' => $from, 'total' => $p['total'] === null ? null : (float) $p['total'], 'dates' => $dates,
        'positions' => array_map(static fn (array $r): array => ['participant_id' => (int) $r['participant_id'], 'number' => $r['number'], 'name' => $r['name'], 'class_key' => $r['class_key'], 'shares' => (float) $r['shares'],
            'pct_of_total' => (float) $r['pct'], 'change_prev' => $r['change_prev'] === null ? null : (float) $r['change_prev'], 'new' => (bool) $r['is_new'], 'net_window' => $r['net_window'] === null ? null : (float) $r['net_window']], $p['rows']),
        'gone' => array_map(static fn (array $g): array => ['number' => $g['number'], 'name' => $g['name'], 'shares_before' => (float) $g['shares']], $p['gone']),
        'reconciliation' => $rec === null ? null : ['state' => reconciliation_state($rec), 'spr_date' => $rec['spr_date']]]);
}
render_screen('Positions', view('positions/index.php', ['security' => $security, 'dates' => $dates, 'date' => $date, 'prev' => $prev, 'from' => $from, 'p' => $p, 'classes' => $classes, 'class' => $class, 'q' => $q,
    'rec' => $rec, 'here' => here_url()]), ['activeNav' => 'positions', 'screen' => 'positions', 'entity' => 'security', 'recordId' => (string) $sid]);
