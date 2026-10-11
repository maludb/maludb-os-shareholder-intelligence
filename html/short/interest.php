<?php
declare(strict_types=1);
/** /short/interest?security=&from=&to= — FINRA's short interest by settlement date with its revisions (screen `short-interest`); two years by default. */
require_once dirname(__DIR__, 2) . '/app/features/short/screen.php';
require_right('short.read');
$pdo = db();
[, $security] = short_context_or_empty($pdo, 'short-interest', 'Short interest');
$sid = (int) $security['security_id'];
[$from, $to] = short_window(730);
$rows = short_interest($pdo, $sid, $from, $to);
log_screen_view($pdo, 'short-interest');
if (wants_json()) {
    respond_screen(['security_id' => $sid, 'from' => $from, 'to' => $to, 'series' => array_map('present_short_interest', $rows)]);
}
render_screen('Short interest', view('short/interest.php', ['security' => $security, 'from' => $from, 'to' => $to, 'rows' => $rows]), ['activeNav' => 'short', 'screen' => 'short-interest']);
