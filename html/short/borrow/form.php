<?php
declare(strict_types=1);
/** /short/borrow/new?security= — record a borrow observation (screen `borrow-add`; borrow.write). */
require_once dirname(__DIR__, 3) . '/app/features/short/screen.php';
require_right('borrow.write');
$pdo = db();
[, $security] = short_context_or_empty($pdo, 'borrow-add', 'Record borrow');
log_screen_view($pdo, 'borrow-add');
if (wants_json()) {
    respond_screen(['security_id' => (int) $security['security_id'], 'sources' => ['broker_quote', 'manual'], 'fields' => ['observed_on', 'source', 'fee_rate_percent', 'utilization_percent', 'shares_on_loan', 'shares_available', 'note']]);
}
render_screen('Record borrow', view('short/borrow-form.php', ['security' => $security, 'today' => (new DateTimeImmutable('now', new DateTimeZone(member_timezone())))->format('Y-m-d')]), ['activeNav' => 'short', 'screen' => 'borrow-add']);
