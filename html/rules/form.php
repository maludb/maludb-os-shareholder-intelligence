<?php
declare(strict_types=1);
/**
 * /rules/{id}/edit?issuer= — a rule's thresholds, severity, window and switch for every issuer, and the current issuer's own thresholds and switch
 * (screen `rule-edit`; managers). The key, the class, the basis and the function are the migration's.
 */
require_once dirname(__DIR__, 2) . '/app/features/signals/screen.php';
require_right('rules.manage');
$pdo = db();
$issuer = current_issuer($pdo);
$rule = find_rule($pdo, (int) (request_integer('id') ?? 0), $issuer === null ? null : (int) $issuer['issuer_id']) ?? refuse(404, 'Rule not found.');
log_screen_view($pdo, 'rule-edit');
if (wants_json()) {
    respond_screen(['rule' => present_signal_rule($rule), 'issuer_id' => $issuer === null ? null : (int) $issuer['issuer_id'], 'fields' => ['params', 'enabled', 'severity_default', 'window_days'],
        'override_fields' => ['issuer', 'params', 'enabled']]);
}
render_screen('Edit ' . $rule['name'], view('rules/form.php', ['rule' => $rule, 'issuer' => $issuer, 'back' => back_link()]), ['activeNav' => 'signals', 'screen' => 'rule-edit', 'entity' => 'signal_rule', 'recordId' => (string) $rule['rule_id']]);
