<?php
declare(strict_types=1);
/** /short/borrow?security=&from=&to= — the borrow observations, "indicative" on every quote (screen `short-borrow`); every one by default. */
require_once dirname(__DIR__, 2) . '/app/features/short/screen.php';
require_right('short.read');
$pdo = db();
[, $security] = short_context_or_empty($pdo, 'short-borrow', 'Borrow');
$sid = (int) $security['security_id'];
$from = request_date('from');
$to = request_date('to');
$from = is_string($from) ? $from : null;
$to = is_string($to) ? $to : null;
$rows = borrow($pdo, $sid, $from, $to);
log_screen_view($pdo, 'short-borrow');
if (wants_json()) {
    respond_screen(['security_id' => $sid, 'from' => $from, 'to' => $to, 'observations' => array_map('present_borrow', $rows)]);
}
$notices = ['recorded' => ['success', 'Recorded the observation.'], 'removed' => ['success', 'Removed the observation.']];
render_screen('Borrow', view('short/borrow.php', ['security' => $security, 'from' => $from, 'to' => $to, 'rows' => $rows, 'canBorrow' => has_right('borrow.write'),
    'notice' => $notices[$_GET['notice'] ?? ''] ?? null]), ['activeNav' => 'short', 'screen' => 'short-borrow']);
