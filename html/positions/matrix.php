<?php
declare(strict_types=1);
/** /positions/matrix?security=&from=&to=&class=&focus=&rows= — participants by position dates, coloured by change (screen `positions-matrix`). 40 rows × the window's last 30 dates; on a phone one date (focus). */
require_once dirname(__DIR__, 2) . '/app/features/positions/screen.php';
require_right('positions.read');
$pdo = db();
[$issuer, $security] = positions_context($pdo);
if ($security === null) {
    log_screen_view($pdo, 'positions-matrix');
    if (wants_json()) { respond_screen(['security_id' => null]); }
    render_screen('Movement', view('positions/partials/no-issuer.php', ['id' => 'positions-matrix', 'title' => 'Movement', 'issuer' => $issuer]), ['activeNav' => 'positions', 'screen' => 'positions-matrix']);
    return;
}
$sid = (int) $security['security_id'];
[$from, $to] = positions_window($pdo, $sid, 30);
$classes = participant_classes($pdo);
$class = isset($classes[request_string('class')]) ? request_string('class') : null;
$rows = max(40, min(400, request_integer('rows') ?? 40));
$m = movement_matrix($pdo, $sid, $from, $to, $class, $rows, 30);
$focus = in_array(request_string('focus'), $m['dates'], true) ? request_string('focus') : ($m['dates'] === [] ? null : $m['dates'][count($m['dates']) - 1]);
log_screen_view($pdo, 'positions-matrix');
if (wants_json()) {
    respond_screen(['security_id' => $sid, 'from' => $from, 'to' => $to, 'class' => $class, 'dates' => $m['dates'], 'more' => $m['more'],
        'rows' => array_map(static fn (array $r): array => ['participant_id' => (int) $r['participant_id'], 'number' => $r['number'], 'name' => $r['name'], 'class_key' => $r['class_key'], 'net' => (float) $r['net'],
            'cells' => array_map(static fn (array $c): array => ['shares' => (float) $c['shares'], 'change' => $c['change'] === null ? null : (float) $c['change']], $r['cells'])], $m['rows'])]);
}
render_screen('Movement', view('positions/matrix.php', ['security' => $security, 'm' => $m, 'from' => $from, 'to' => $to, 'focus' => $focus, 'classes' => $classes, 'class' => $class, 'rows' => $rows, 'here' => here_url()]),
    ['activeNav' => 'positions', 'screen' => 'positions-matrix', 'entity' => 'security', 'recordId' => (string) $sid]);
