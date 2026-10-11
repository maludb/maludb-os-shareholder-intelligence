<?php
declare(strict_types=1);
/**
 * Action `rule_override_save` (log `rule.override`; rules.manage; an agent's pauses — other; undo: restore the prior override): **rule**, **issuer**,
 * params (the issuer's thresholds; an empty one takes the rule's), enabled (yes, no, or blank for the rule's). An override left with nothing is removed.
 */
require_once dirname(__DIR__, 2) . '/app/features/signals/screen.php';
si_handler_begin();
require_right('rules.manage');
$pdo = db();
$rule = find_rule($pdo, (int) (request_integer('rule') ?? 0), null) ?? refuse(404, 'Rule not found.');
$issuer = find_visible_issuer($pdo, (string) (request_integer('issuer') ?? '')) ?? refuse(404, 'Issuer not found.');
$base = rule_row($pdo, (int) $rule['rule_id']);
$errors = [];
$f = [];
$given = rule_params_from_request($errors);
if ($given !== null) {
    [$f['params'], $pe] = rule_params_check($base, $given);
    $errors += $pe;
}
if (req_has('enabled')) {
    $v = strtolower((string) req_val('enabled'));
    $f['enabled'] = match ($v) { '' , 'rule', 'inherit' => null, default => si_yes('enabled') };
}
if ($errors !== []) {
    si_refuse_fields($errors);
}
si_guard($pdo, static function () use ($pdo, $rule, $issuer, $f): void {
    $pdo->beginTransaction();
    rule_override_save($pdo, (int) $rule['rule_id'], (int) $issuer['issuer_id'], $f, (int) current_member_id());
    $pdo->commit();
});
si_done('Saved the rule ' . $rule['name'] . ' for ' . $issuer['name'], (int) $rule['rule_id'], si_land(return_path('/rules?issuer=' . (int) $issuer['issuer_id']), 'overridden', 'rule-row-' . $rule['key']), 'ruleChanged');
