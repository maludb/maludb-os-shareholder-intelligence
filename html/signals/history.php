<?php
declare(strict_types=1);
/**
 * /signals/history?from=&to=&rule=&status=&page= — every signal raised in a period with its disposition, who and why (screen `signal-history`); the
 * agent's proposals taken, not taken, waiting. The last 90 days by default.
 */
require_once dirname(__DIR__, 2) . '/app/features/signals/screen.php';
require_right('signals.read');
$pdo = db();
$issuer = current_issuer($pdo);
if ($issuer === null) {
    log_screen_view($pdo, 'signal-history');
    if (wants_json()) { respond_screen(['issuer_id' => null, 'signals' => []]); }
    render_screen('Signal history', view('positions/partials/no-issuer.php', ['id' => 'signal-history', 'title' => 'Signal history', 'issuer' => null]), ['activeNav' => 'signals', 'screen' => 'signal-history']);
    exit;
}
$iid = (int) $issuer['issuer_id'];
$to = request_date('to');
$from = request_date('from');
$to = is_string($to) ? $to : signals_today($pdo);
$from = is_string($from) ? $from : (new DateTimeImmutable($to))->modify('-90 days')->format('Y-m-d');
if ($from > $to) { [$from, $to] = [$to, $from]; }
$f = ['from' => $from, 'to' => $to, 'rule' => request_string('rule') ?: null, 'status' => request_string('status', 'all')];
if (!isset(SIGNAL_STATUS_FILTERS[$f['status']])) { $f['status'] = 'all'; }
$h = signals_history($pdo, $iid, $f, request_integer('page') ?? 1);
log_screen_view($pdo, 'signal-history');
if (wants_json()) {
    respond_screen(['issuer_id' => $iid, 'filters' => $f, 'total' => $h['total'], 'page' => $h['page'], 'pages' => $h['pages'], 'proposals' => $h['proposals'], 'signals' => array_map('present_signal', $h['rows'])]);
}
render_screen('Signal history', view('signals/history.php', ['issuer' => $issuer, 'f' => $f, 'h' => $h, 'rules' => rules_catalogue($pdo, null), 'tz' => member_timezone(), 'here' => here_url()]),
    ['activeNav' => 'signals', 'screen' => 'signal-history']);
