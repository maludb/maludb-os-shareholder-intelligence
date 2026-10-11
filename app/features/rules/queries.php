<?php
declare(strict_types=1);

/**
 * The rules' catalogue (signals-rules.md "Query functions"): every rule with its class, basis, thresholds and last firing (mcp_signal_rules),
 * and — for an issuer the caller may see — that issuer's override and the thresholds in effect (si_rule_params / si_rule_enabled, the same
 * functions the rules read). A rule's key, class and basis are fixed by its migration; a manager sets thresholds, severity, window and enabled.
 */

/** Every rule in its run order; with an issuer, its override, the thresholds and the switch in effect, and its open signals. */
function rules_catalogue(PDO $pdo, ?int $issuer): array
{
    $st = $pdo->prepare('SELECT r.*, o.params AS override_params, o.enabled AS override_enabled, o.updated_at AS override_updated_at,
                                (SELECT display_name FROM mcp_members m WHERE m.member_id = o.updated_by) AS override_by_name,
                                CASE WHEN CAST(:i1 AS bigint) IS NULL THEN NULL ELSE si_rule_params(r.key, CAST(:i2 AS bigint)) END AS effective_params,
                                CASE WHEN CAST(:i3 AS bigint) IS NULL THEN r.enabled ELSE si_rule_enabled(r.key, CAST(:i4 AS bigint)) END AS effective_enabled,
                                (SELECT count(*) FROM mcp_signals s WHERE s.rule_id = r.rule_id AND s.status IN (\'open\', \'escalated\') AND (CAST(:i5 AS bigint) IS NULL OR s.issuer_id = CAST(:i6 AS bigint))) AS open_signals
                           FROM mcp_signal_rules r
                           LEFT JOIN signal_rule_overrides o ON o.rule_id = r.rule_id AND o.issuer_id = CAST(:i7 AS bigint)
                          ORDER BY r.sort_order, r.key');
    $st->execute(['i1' => $issuer, 'i2' => $issuer, 'i3' => $issuer, 'i4' => $issuer, 'i5' => $issuer, 'i6' => $issuer, 'i7' => $issuer]);
    return $st->fetchAll();
}

/** One rule (by id) as the catalogue shows it, with the issuer's override; null when there is no such rule. */
function find_rule(PDO $pdo, int $id, ?int $issuer): ?array
{
    foreach (rules_catalogue($pdo, $issuer) as $r) {
        if ((int) $r['rule_id'] === $id) {
            return $r;
        }
    }
    return null;
}

/** A rule's id by its key (the `rule` resolver's other door), or null. */
function rule_id_by_key(PDO $pdo, string $key): ?int
{
    $v = one_value($pdo, 'SELECT rule_id FROM mcp_signal_rules WHERE key = :k', ['k' => $key]);
    return $v === false || $v === null ? null : (int) $v;
}
