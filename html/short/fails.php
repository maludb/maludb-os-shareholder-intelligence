<?php
declare(strict_types=1);
/** /short/fails?security=&from=&to= — the SEC's fails-to-deliver with the share of outstanding and the 60-day median (screen `short-fails`); 180 days by default. */
require_once dirname(__DIR__, 2) . '/app/features/short/screen.php';
require_right('short.read');
$pdo = db();
[, $security] = short_context_or_empty($pdo, 'short-fails', 'Fails to deliver');
$sid = (int) $security['security_id'];
[$from, $to] = short_window(180);
$rows = fails($pdo, $sid, $from, $to);
log_screen_view($pdo, 'short-fails');
if (wants_json()) {
    respond_screen(['security_id' => $sid, 'from' => $from, 'to' => $to, 'series' => array_map('present_fail', $rows)]);
}
render_screen('Fails to deliver', view('short/fails.php', ['security' => $security, 'from' => $from, 'to' => $to, 'rows' => $rows]), ['activeNav' => 'short', 'screen' => 'short-fails']);
