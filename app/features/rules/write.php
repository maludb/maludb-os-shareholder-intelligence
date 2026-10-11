<?php
declare(strict_types=1);

/**
 * The rules' writes (signals-rules.md, signatures fixed; a manager's — `rules.manage` — and an agent's pause: other). A rule's thresholds are
 * the keys its migration seeded, each a number; nothing adds a key or changes a class, a basis or a function. rule_save sets the rule for every
 * issuer; rule_override_save sets one issuer's thresholds and switch (an empty override is removed). Both log what changed, before and after.
 */

/** The rule's base row (signal_rules); DomainException('Not found.') when absent. */
function rule_row(PDO $pdo, int $rule): array
{
    $st = $pdo->prepare('SELECT * FROM signal_rules WHERE id = :id');
    $st->execute(['id' => $rule]);
    return $st->fetch() ?: throw new DomainException('Not found.');
}

/**
 * Check a thresholds object against the rule's own keys: every key one the rule has, every value a number ≥ 0 (a percent, a multiple, a count
 * of days). Answers [params, errors by field name `params[key]`].
 */
function rule_params_check(array $rule, array $given): array
{
    $known = json_decode((string) $rule['params'], true) ?: [];
    $out = [];
    $errors = [];
    foreach ($given as $k => $v) {
        $k = (string) $k;
        if (!array_key_exists($k, $known)) {
            $errors['params[' . $k . ']'] = 'The rule has no threshold “' . $k . '”; it has ' . ($known === [] ? 'none' : implode(', ', array_keys($known))) . '.';
            continue;
        }
        $v = str_replace([',', '%', ' '], '', trim((string) $v));
        if ($v === '') {
            continue;                                                     // left empty: the rule's (an override) or unchanged
        }
        if (!is_numeric($v) || (float) $v < 0 || (float) $v > 1e9) {
            $errors['params[' . $k . ']'] = 'The threshold ' . str_replace('_', ' ', $k) . ' is a number, 0 or more.';
            continue;
        }
        $out[$k] = (float) $v == (int) $v ? (int) $v : (float) $v;
    }
    return [$out, $errors];
}

/** Set a rule for every issuer: $f params (merged into its thresholds), enabled, severity_default, window_days — each optional. Logs `rule.save`. */
function rule_save(PDO $pdo, int $rule, array $f, int $by): void
{
    $r = rule_row($pdo, $rule);
    $before = ['params' => json_decode((string) $r['params'], true) ?: [], 'enabled' => (bool) $r['enabled'], 'severity_default' => $r['severity_default'], 'window_days' => (int) $r['window_days']];
    $after = ['params' => array_merge($before['params'], $f['params'] ?? []), 'enabled' => $f['enabled'] ?? $before['enabled'],
              'severity_default' => $f['severity_default'] ?? $before['severity_default'], 'window_days' => $f['window_days'] ?? $before['window_days']];
    $pdo->prepare('UPDATE signal_rules SET params = CAST(:p AS jsonb), enabled = :e, severity_default = :sv, window_days = :w, updated_by = :by WHERE id = :id')
        ->execute(['p' => json_encode($after['params']), 'e' => $after['enabled'] ? 'true' : 'false', 'sv' => $after['severity_default'], 'w' => $after['window_days'], 'by' => $by, 'id' => $rule]);
    $d = si_diff($before, $after);
    log_activity($pdo, 'rule.save', 'signal_rule', $rule, ['before' => ['key' => $r['key']] + $d['before'], 'after' => ['key' => $r['key']] + $d['after']]);
}

/**
 * Set one issuer's thresholds and switch for a rule: $f params (the issuer's, replacing its earlier override's; an empty value takes the
 * rule's), enabled (true, false, or null for the rule's). An override with no threshold and no switch is removed. Logs `rule.override`.
 */
function rule_override_save(PDO $pdo, int $rule, int $issuer, array $f, int $by): void
{
    $r = rule_row($pdo, $rule);
    $st = $pdo->prepare('SELECT params, enabled FROM signal_rule_overrides WHERE rule_id = :r AND issuer_id = :i');
    $st->execute(['r' => $rule, 'i' => $issuer]);
    $o = $st->fetch();
    $before = ['params' => $o === false ? [] : (json_decode((string) $o['params'], true) ?: []), 'enabled' => $o === false || $o['enabled'] === null ? null : (bool) $o['enabled']];
    $after = ['params' => $f['params'] ?? $before['params'], 'enabled' => array_key_exists('enabled', $f) ? $f['enabled'] : $before['enabled']];
    if ($after['params'] === [] && $after['enabled'] === null) {
        $pdo->prepare('DELETE FROM signal_rule_overrides WHERE rule_id = :r AND issuer_id = :i')->execute(['r' => $rule, 'i' => $issuer]);
    } else {
        $pdo->prepare('INSERT INTO signal_rule_overrides (rule_id, issuer_id, params, enabled, updated_by, updated_at) VALUES (:r, :i, CAST(:p AS jsonb), CAST(:e AS boolean), :by, now())
                       ON CONFLICT (rule_id, issuer_id) DO UPDATE SET params = EXCLUDED.params, enabled = EXCLUDED.enabled, updated_by = EXCLUDED.updated_by, updated_at = now()')
            ->execute(['r' => $rule, 'i' => $issuer, 'p' => json_encode((object) $after['params']), 'e' => $after['enabled'] === null ? null : ($after['enabled'] ? 'true' : 'false'), 'by' => $by]);
    }
    $d = si_diff($before, $after);
    log_activity($pdo, 'rule.override', 'signal_rule', $rule, ['issuer_id' => $issuer, 'before' => ['key' => $r['key']] + $d['before'], 'after' => ['key' => $r['key']] + $d['after']]);
}
