<?php
declare(strict_types=1);
/**
 * Action `rule_save` (log `rule.save`; rules.manage; an agent's pauses — other; undo: restore the prior values): **rule**, params (the thresholds —
 * `params[key]` from the form, or a JSON object), enabled (yes or no), severity_default (info, watch or high), window_days. A field left out stays.
 */
require_once dirname(__DIR__, 2) . '/app/features/signals/screen.php';
si_handler_begin();
require_right('rules.manage');
$pdo = db();
$rule = find_rule($pdo, (int) (request_integer('rule') ?? 0), null) ?? refuse(404, 'Rule not found.');
$base = rule_row($pdo, (int) $rule['rule_id']);
$errors = [];
$f = [];
$given = rule_params_from_request($errors);
if ($given !== null) {
    [$f['params'], $pe] = rule_params_check($base, $given);
    $errors += $pe;
}
if (req_has('enabled')) {
    $f['enabled'] = si_yes('enabled');
}
if (req_has('severity_default')) {
    $sv = (string) req_val('severity_default');
    in_array($sv, ['info', 'watch', 'high'], true) ? $f['severity_default'] = $sv : $errors['severity_default'] = 'The severity is info, watch or high.';
}
$w = si_int('window_days', null, 1, 730, 'The window in days', $errors);
if ($w !== null) {
    $f['window_days'] = $w;
}
if ($errors !== []) {
    si_refuse_fields($errors);
}
si_guard($pdo, static function () use ($pdo, $rule, $f): void {
    $pdo->beginTransaction();
    rule_save($pdo, (int) $rule['rule_id'], $f, (int) current_member_id());
    $pdo->commit();
});
si_done('Saved the rule ' . $rule['name'] . ' for every issuer', (int) $rule['rule_id'], si_land(return_path('/rules'), 'saved', 'rule-row-' . $rule['key']), 'ruleChanged');
