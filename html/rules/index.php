<?php
declare(strict_types=1);
/**
 * /rules?class= — the rules' catalogue (screen `rule-list`): key, name, evidence class, basis, severity, thresholds, window, last fired, enabled — with the
 * current issuer's overrides in effect — and what the price did after each rule's signals (n, the mean 20-day return, the share negative; "too few to say"
 * below thirty).
 */
require_once dirname(__DIR__, 2) . '/app/features/signals/screen.php';
require_right('signals.read');
$pdo = db();
$issuer = current_issuer($pdo);
$iid = $issuer === null ? null : (int) $issuer['issuer_id'];
$class = request_string('class') ?: null;
$rules = array_values(array_filter(rules_catalogue($pdo, $iid), static fn ($r) => $class === null || $r['evidence_class'] === $class));
$outcomes = array_column(outcomes_by_rule($pdo, $iid), null, 'key');
log_screen_view($pdo, 'rule-list');
if (wants_json()) {
    respond_screen(['issuer_id' => $iid, 'class' => $class, 'rules' => array_map(static fn ($r) => present_signal_rule($r) + ['outcome' => isset($outcomes[$r['key']]) ? present_rule_outcome($outcomes[$r['key']]) : null], $rules)]);
}
$notices = ['saved' => ['success', 'Saved the rule for every issuer.'], 'overridden' => ['success', 'Saved the rule for this issuer.']];
render_screen('Signal rules', view('rules/index.php', ['issuer' => $issuer, 'rules' => $rules, 'outcomes' => $outcomes, 'class' => $class, 'canManage' => has_right('rules.manage'),
    'notice' => $notices[$_GET['notice'] ?? ''] ?? null]), ['activeNav' => 'signals', 'screen' => 'rule-list']);
