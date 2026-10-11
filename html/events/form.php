<?php
declare(strict_types=1);
/** /events/new?on_date=&kind=&filing= and /events/{id}/edit — record an event or change one (screens `event-add`, `event-edit`; events.write). */
require_once dirname(__DIR__, 2) . '/app/features/events/screen.php';
require_right('events.write');
$pdo = db();
$e = request_integer('id') !== null ? event_from_request($pdo, 'id') : null;
$issuer = $e !== null ? find_issuer($pdo, (int) $e['issuer_id']) : current_issuer($pdo);
if ($issuer === null) { refuse(404, 'Not found.'); }
$issuer['issuer_id'] ??= $issuer['id'];
$on = request_date('on_date');
$kind = request_string('kind');
$filing = request_integer('filing');
if ($filing !== null && (int) one_value($pdo, 'SELECT count(*) FROM mcp_filings WHERE filing_id = :f AND issuer_id = :i', ['f' => $filing, 'i' => (int) $issuer['issuer_id']]) === 0) { $filing = null; }
$v = $e ?? ['on_date' => is_string($on) ? $on : date('Y-m-d'), 'kind' => isset(EVENT_KINDS[$kind]) ? $kind : 'other', 'title' => '', 'body' => '', 'url' => '', 'severity' => 'info', 'filing_id' => $filing, 'security_id' => null, 'tags' => []];
log_screen_view($pdo, $e === null ? 'event-add' : 'event-edit');
if (wants_json()) {
    respond_screen(['event' => $e === null ? null : present_event($e), 'kinds' => EVENT_KINDS, 'severities' => EVENT_SEVERITIES]);
}
render_screen($e === null ? 'Record an event' : 'Edit the event', view('events/form.php', ['e' => $e, 'v' => $v, 'issuer' => $issuer, 'securities' => find_securities($pdo, (int) $issuer['issuer_id'])]),
    ['activeNav' => 'events', 'screen' => $e === null ? 'event-add' : 'event-edit']);
