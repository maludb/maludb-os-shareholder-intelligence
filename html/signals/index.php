<?php
declare(strict_types=1);
/**
 * /signals?status=&severity=&rule=&security=&class=&page= — the current issuer's signals as cards by severity (screen `signal-list`): the class chip, the
 * rule, the window, the summary, the basis on hover, the agent's proposal with Take it. By default the signals that still need a person (open, escalated).
 */
require_once dirname(__DIR__, 2) . '/app/features/signals/screen.php';
require_right('signals.read');
$pdo = db();
$issuer = current_issuer($pdo);
if ($issuer === null) {
    log_screen_view($pdo, 'signal-list');
    if (wants_json()) { respond_screen(['issuer_id' => null, 'signals' => []]); }
    render_screen('Signals', view('positions/partials/no-issuer.php', ['id' => 'signal-list', 'title' => 'Signals', 'issuer' => null]), ['activeNav' => 'signals', 'screen' => 'signal-list']);
    exit;
}
$iid = (int) $issuer['issuer_id'];
$f = ['status' => request_string('status', 'live'), 'severity' => request_string('severity') ?: null, 'rule' => request_string('rule') ?: null,
      'security' => request_integer('security'), 'class' => request_string('class') ?: null];
if (!isset(SIGNAL_STATUS_FILTERS[$f['status']])) { $f['status'] = 'live'; }
$list = find_signals($pdo, $iid, $f, request_integer('page') ?? 1);
log_screen_view($pdo, 'signal-list');
if (wants_json()) {
    respond_screen(['issuer_id' => $iid, 'filters' => $f, 'counts' => $list['counts'], 'total' => $list['total'], 'page' => $list['page'], 'pages' => $list['pages'],
        'signals' => array_map('present_signal', $list['rows'])]);
}
$secs = $pdo->prepare('SELECT security_id, name, ticker, is_primary FROM mcp_securities WHERE issuer_id = :i ORDER BY is_primary DESC, name');
$secs->execute(['i' => $iid]);
$notices = ['ran' => ['success', 'The rules ran.'], 'deleted' => ['success', 'Deleted the signal.'], 'acknowledged' => ['success', 'Acknowledged.'], 'dismissed' => ['success', 'Dismissed.'],
            'escalated' => ['success', 'Escalated to the IR Manager.'], 'proposed' => ['success', 'Proposed.']];
render_screen('Signals', view('signals/index.php', ['issuer' => $issuer, 'f' => $f, 'list' => $list, 'securities' => $secs->fetchAll(), 'rules' => rules_catalogue($pdo, null),
    'may' => has_right('signals.work') && (current_member()['member_kind'] ?? '') === 'human', 'canRun' => has_right('signals.work'), 'here' => here_url(),
    'notice' => $notices[$_GET['notice'] ?? ''] ?? null]), ['activeNav' => 'signals', 'screen' => 'signal-list']);
