<?php
declare(strict_types=1);
/** /short/volume?security=&market=&from=&to= — FINRA's daily off-exchange short volume with its 5- and 20-day means and the caveat (screen `short-volume`); 90 days by default, CNMS. */
require_once dirname(__DIR__, 2) . '/app/features/short/screen.php';
require_right('short.read');
$pdo = db();
[, $security] = short_context_or_empty($pdo, 'short-volume', 'Short volume');
$sid = (int) $security['security_id'];
$market = strtoupper(request_string('market', 'CNMS'));
$market = isset(SHORT_VOLUME_MARKETS[$market]) ? $market : 'CNMS';
[$from, $to] = short_window(90);
$rows = short_volume($pdo, $sid, $market, $from, $to);
log_screen_view($pdo, 'short-volume');
if (wants_json()) {
    respond_screen(['security_id' => $sid, 'market' => $market, 'from' => $from, 'to' => $to, 'caveat' => SI_SHORT_VOLUME_CAVEAT, 'series' => array_map('present_short_volume', $rows)]);
}
render_screen('Short volume', view('short/volume.php', ['security' => $security, 'from' => $from, 'to' => $to, 'market' => $market, 'rows' => $rows]), ['activeNav' => 'short', 'screen' => 'short-volume']);
