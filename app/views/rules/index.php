<?php /** The rules' catalogue (screen `rule-list`). Data: issuer, rules, outcomes, class, canManage, notice */
$thr = static function (?string $json): string {
    $p = json_decode((string) $json, true) ?: [];
    return $p === [] ? '—' : implode(' · ', array_map(static fn ($k, $v) => str_replace('_', ' ', (string) $k) . ' ' . (is_scalar($v) ? (string) $v : json_encode($v)), array_keys($p), $p));
};
?>
<?= view('shared/header.php', ['id' => 'rule-list', 'title' => 'Signal rules', 'crumbs' => [['Home', '/'], ['Signals', '/signals'], ['Rules', null]]]) ?>
<div class="main-content" id="rule-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <p class="fs-12 text-muted">Nineteen rules, each a SQL function with an evidence class printed on every signal it raises. Never a composite score; never a model.
        <?= $issuer !== null ? 'The thresholds shown are those in effect for <span class="fw-semibold">' . e($issuer['name']) . '</span>.' : 'Choose an issuer in the header to see its own thresholds.' ?></p>
    <div class="d-flex flex-wrap gap-2 mb-3 fs-12" id="rule-class-filter">
        <?= hx_link('/rules', 'Every class', 'btn btn-sm btn-touch ' . ($class === null ? 'btn-dark' : 'btn-light'), 'id="rule-class-all"') ?>
        <?php foreach (SIGNAL_CLASS_CHIPS as $k => [$cls, $w]): ?><?= hx_link('/rules?class=' . $k, e($w), 'btn btn-sm btn-touch ' . ($class === $k ? 'btn-dark' : 'btn-light'), 'id="rule-class-' . e($k) . '"') ?><?php endforeach; ?>
    </div>
    <div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 fs-12" id="rule-table">
        <thead class="thead-light"><tr><th>Rule</th><th class="d-none d-md-table-cell">Thresholds</th><th class="d-none d-lg-table-cell text-end">Window</th><th class="d-none d-md-table-cell">Last fired</th><th class="d-none d-sm-table-cell">After 20 days</th><th></th></tr></thead><tbody>
        <?php foreach ($rules as $r): $o = $outcomes[$r['key']] ?? null; $on = (bool) ($r['effective_enabled'] ?? $r['enabled']); ?>
        <tr id="rule-row-<?= e($r['key']) ?>" class="<?= $on ? '' : 'text-muted' ?>" data-enabled="<?= $on ? '1' : '0' ?>">
            <td class="text-wrap" style="min-width: 14rem">
                <div class="d-flex flex-wrap align-items-center gap-1"><span class="fw-semibold"><?= e($r['name']) ?></span>
                    <span class="badge <?= e(SIGNAL_CLASS_CHIPS[$r['evidence_class']][0] ?? 'text-bg-secondary') ?> signal-class"><?= e(SIGNAL_CLASS_CHIPS[$r['evidence_class']][1] ?? '') ?></span>
                    <?= signal_severity_chip((string) $r['severity_default']) ?>
                    <?= $on ? '' : '<span class="badge text-bg-light border" id="rule-off-' . e($r['key']) . '">off' . ($r['override_enabled'] !== null ? ' for this issuer' : '') . '</span>' ?>
                    <?= $r['override_params'] !== null || $r['override_enabled'] !== null ? '<span class="badge bg-soft-primary text-primary" id="rule-override-' . e($r['key']) . '">this issuer\'s own</span>' : '' ?></div>
                <div class="text-muted"><code><?= e($r['key']) ?></code> — <?= e($r['description']) ?></div>
                <div class="fs-11 text-muted fst-italic mt-1">The basis: <?= e($r['basis']) ?></div>
                <div class="d-md-none mt-1"><?= e($thr($r['effective_params'] ?? $r['params'])) ?></div>
            </td>
            <td class="d-none d-md-table-cell text-wrap"><?= e($thr($r['effective_params'] ?? $r['params'])) ?></td>
            <td class="d-none d-lg-table-cell text-end text-nowrap"><?= (int) $r['window_days'] ?> days</td>
            <td class="d-none d-md-table-cell text-nowrap"><?= e($r['last_fired_on'] === null ? 'never' : format_date((string) $r['last_fired_on'])) ?><?= (int) ($r['open_signals'] ?? 0) > 0 ? '<div>' . hx_link('/signals?rule=' . e($r['key']), (int) $r['open_signals'] . ' live', 'fw-semibold') . '</div>' : '' ?></td>
            <td class="d-none d-sm-table-cell text-wrap" id="rule-outcome-<?= e($r['key']) ?>"><?php if ($o === null || (int) $o['n'] < OUTCOMES_ENOUGH): ?><span class="text-muted">too few to say</span> <span class="text-muted">(n = <?= (int) ($o['n'] ?? 0) ?>)</span>
                <?php else: ?><?= e(sprintf('%+.2f%%', (float) $o['mean_return_20d_pct'])) ?> mean · <?= e(number_format((float) $o['share_negative_20d_pct'], 1)) ?>% negative <span class="text-muted">(n = <?= (int) $o['n'] ?>)</span><?php endif; ?></td>
            <td class="text-end"><?= $canManage ? hx_link('/rules/' . (int) $r['rule_id'] . '/edit' . ($issuer !== null ? '?issuer=' . (int) $issuer['issuer_id'] : ''), '<i class="feather-edit-2"></i>', 'btn btn-light btn-sm btn-touch', 'id="rule-edit-' . e($r['key']) . '" aria-label="Edit ' . e($r['name']) . '"') : '' ?></td>
        </tr><?php endforeach; ?>
        </tbody></table></div></div></div>
    <p class="fs-11 text-muted mt-2">"After 20 days" is the mean return of the close 20 trading days after each signal of the rule, and the share that fell: the Auditor's test of the lore. It changes no rule, and a rule with fewer than thirty outcomes is too few to say.</p>
</div>
