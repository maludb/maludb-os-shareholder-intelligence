<?php
declare(strict_types=1);
/** /ownership/144?from=&to= — the Form 144 notices (screen `ownership-144`): seller, relationship, shares, value, the approximate sale date, the broker. */
require_once dirname(__DIR__, 2) . '/app/features/ownership/screen.php';
require_right('institutions.read');
$pdo = db();
[, $security] = ownership_context_or_empty($pdo, 'ownership-144', 'Form 144');
$from = request_date('from');
$to = request_date('to');
$f = ['from' => is_string($from) ? $from : null, 'to' => is_string($to) ? $to : null];
$rows = form144($pdo, (int) $security['issuer_id'], $f);
log_screen_view($pdo, 'ownership-144');
if (wants_json()) {
    respond_screen(['issuer_id' => (int) $security['issuer_id'], 'filters' => $f, 'notices' => array_map(static fn ($n) => ['notice_id' => (int) $n['notice_id'], 'filing_id' => $n['filing_id'] === null ? null : (int) $n['filing_id'], 'filed_on' => $n['filed_on'],
        'seller_name' => $n['seller_name'], 'relationship' => $n['relationship'], 'shares_to_sell' => $n['shares_to_sell'] === null ? null : (float) $n['shares_to_sell'], 'aggregate_market_value' => $n['aggregate_market_value'] === null ? null : (float) $n['aggregate_market_value'],
        'approx_sale_date' => $n['approx_sale_date'], 'broker' => $n['broker']], $rows)]);
}
render_screen('Form 144', view('ownership/144.php', ['security' => $security, 'rows' => $rows, 'f' => $f]), ['activeNav' => 'ownership', 'screen' => 'ownership-144']);
