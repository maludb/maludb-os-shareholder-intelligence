<?php
declare(strict_types=1);
/** /positions/flow?security=&from=&to= — net shares by class between consecutive position dates (screen `positions-flow`), with the caveat printed under it always. 90 days by default. */
require_once dirname(__DIR__, 2) . '/app/features/positions/screen.php';
require_right('positions.read');
$pdo = db();
[$issuer, $security] = positions_context($pdo);
if ($security === null) {
    log_screen_view($pdo, 'positions-flow');
    if (wants_json()) { respond_screen(['security_id' => null]); }
    render_screen('Flow by class', view('positions/partials/no-issuer.php', ['id' => 'positions-flow', 'title' => 'Flow by class', 'issuer' => $issuer]), ['activeNav' => 'positions', 'screen' => 'positions-flow']);
    return;
}
$sid = (int) $security['security_id'];
[$from, $to] = positions_window($pdo, $sid, 90);
$flow = flow_by_date($pdo, $sid, $from, $to);
log_screen_view($pdo, 'positions-flow');
if (wants_json()) {
    respond_screen(['security_id' => $sid, 'from' => $from, 'to' => $to, 'flow' => $flow, 'caveat' => 'Class buckets are a setting. Movement between them is practitioner lore with no published validation.']);
}
render_screen('Flow by class', view('positions/flow.php', ['security' => $security, 'flow' => $flow, 'from' => $from, 'to' => $to, 'classes' => participant_classes($pdo), 'colours' => class_colours($pdo)]),
    ['activeNav' => 'positions', 'screen' => 'positions-flow', 'entity' => 'security', 'recordId' => (string) $sid]);
