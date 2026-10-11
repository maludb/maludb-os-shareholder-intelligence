<?php
declare(strict_types=1);
/** Action `rules_run` (log `rules.run`; signals.work): **security** (every enabled rule now), as_of (a date — today in market time when left out). */
require_once dirname(__DIR__, 2) . '/app/features/signals/screen.php';
si_handler_begin();
require_right('signals.work');
$pdo = db();
$sec = find_security($pdo, (int) (request_integer('security') ?? 0)) ?? refuse(404, 'Security not found.');
$asOf = request_date('as_of');
if ($asOf === false) {
    si_refuse_fields(['as_of' => 'A date, such as ' . signals_today($pdo) . '.']);
}
$asOf ??= signals_today($pdo);
$r = si_guard($pdo, static function () use ($pdo, $sec, $asOf): array {
    $pdo->beginTransaction();
    $r = rules_run($pdo, (int) $sec['security_id'], $asOf);
    log_activity($pdo, 'rules.run', 'security', (int) $sec['security_id'], ['issuer_id' => (int) $sec['issuer_id'], 'security_id' => (int) $sec['security_id'],
        'after' => ['as_of' => $asOf, 'raised' => $r['raised'], 'refreshed' => $r['refreshed'], 'expired' => $r['expired']]]);
    $pdo->commit();
    return $r;
});
$did = 'Ran the rules as of ' . $asOf . ': ' . $r['raised'] . ' raised, ' . $r['refreshed'] . ' refreshed, ' . $r['expired'] . ' expired';
si_done($did, (int) $sec['security_id'], si_land(return_path('/signals?security=' . (int) $sec['security_id']), 'ran'), 'signalChanged',
    ['raised' => $r['raised'], 'refreshed' => $r['refreshed'], 'expired' => $r['expired'], 'raised_ids' => $r['raised_ids']]);
