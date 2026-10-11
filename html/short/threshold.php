<?php
declare(strict_types=1);
/** /short/threshold?security= — the Reg SHO threshold lists: the status now and every run (screen `short-threshold`). */
require_once dirname(__DIR__, 2) . '/app/features/short/screen.php';
require_right('short.read');
$pdo = db();
[, $security] = short_context_or_empty($pdo, 'short-threshold', 'Threshold lists');
$sid = (int) $security['security_id'];
$t = threshold_runs($pdo, $sid);
log_screen_view($pdo, 'short-threshold');
if (wants_json()) {
    $s = $t['status'];
    respond_screen(['security_id' => $sid, 'status' => $s === null ? null : ['on_list' => (bool) $s['on_list'], 'list' => $s['list'], 'since' => $s['since'], 'consecutive_days' => (int) $s['consecutive_days'], 'latest_list_date' => $s['latest_list_date']],
        'runs' => array_map('present_threshold_run', $t['runs']), 'lists_read' => array_map(static fn ($l) => ['list' => $l['list'], 'latest' => $l['latest'], 'days' => (int) $l['days']], $t['lists_read'])]);
}
render_screen('Threshold lists', view('short/threshold.php', ['security' => $security, 't' => $t]), ['activeNav' => 'short', 'screen' => 'short-threshold']);
