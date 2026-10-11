<?php /** A rule's settings for every issuer and for the current one (screen `rule-edit`). Data: rule, issuer, back */
$rid = (int) $rule['rule_id'];
$params = json_decode((string) $rule['params'], true) ?: [];
$own = json_decode((string) ($rule['override_params'] ?? '{}'), true) ?: [];
$label = static fn (string $k): string => ucfirst(str_replace('_', ' ', $k));
?>
<?= view('shared/header.php', ['id' => 'rule-edit', 'title' => $rule['name'], 'crumbs' => [['Home', '/'], ['Signals', '/signals'], ['Rules', '/rules'], [$rule['name'], null]], 'back' => $back]) ?>
<div class="main-content" id="rule-edit-content">
    <div class="card mb-3" id="rule-about"><div class="card-body fs-12">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-2"><code><?= e($rule['key']) ?></code><?= signal_class_chip((string) $rule['evidence_class'], 'rule-class') ?></div>
        <div><?= e($rule['description']) ?></div>
        <div class="text-muted fst-italic mt-1">The basis: <?= e($rule['basis']) ?></div>
        <div class="text-muted mt-1">The key, the class and the function are set by the application's migrations; a manager sets the thresholds, the severity, the window and the switch.</div>
    </div></div>
    <div class="row g-3">
        <div class="col-lg-6"><form method="post" action="/rules/save.php" hx-post="/rules/save.php" hx-target="#flash" class="card" id="rule-form"><div class="card-header"><h5 class="card-title mb-0">For every issuer</h5></div><div class="card-body">
            <?= csrf_field() ?><input type="hidden" name="rule" value="<?= $rid ?>"><input type="hidden" name="return_to" value="/rules<?= $issuer !== null ? '?issuer=' . (int) $issuer['issuer_id'] : '' ?>">
            <?php if ($params === []): ?><p class="fs-12 text-muted" id="rule-form-no-params">This rule has no threshold to set: it fires on a fact (a list, a filing, an event, a reconciliation).</p><?php endif; ?>
            <?php foreach ($params as $k => $v): ?>
                <label class="form-label fs-12" for="rule-form-param-<?= e($k) ?>"><?= e($label((string) $k)) ?></label>
                <input type="text" inputmode="decimal" name="params[<?= e($k) ?>]" id="rule-form-param-<?= e($k) ?>" value="<?= e(is_scalar($v) ? (string) $v : '') ?>" class="form-control form-control-sm mb-2">
            <?php endforeach; ?>
            <label class="form-label fs-12" for="rule-form-severity">The severity it raises at by default</label>
            <select name="severity_default" id="rule-form-severity" class="form-select form-select-sm mb-2"><?php foreach (['info', 'watch', 'high'] as $sv): ?><option value="<?= $sv ?>"<?= $sv === $rule['severity_default'] ? ' selected' : '' ?>><?= $sv ?></option><?php endforeach; ?></select>
            <label class="form-label fs-12" for="rule-form-window">The window, in days</label>
            <input type="number" min="1" max="730" name="window_days" id="rule-form-window" value="<?= (int) $rule['window_days'] ?>" class="form-control form-control-sm mb-2">
            <input type="hidden" name="enabled" value="0">
            <div class="form-check form-switch mb-1"><input class="form-check-input" type="checkbox" name="enabled" value="1" id="rule-form-enabled"<?= $rule['enabled'] ? ' checked' : '' ?>><label class="form-check-label fs-12" for="rule-form-enabled">The rule runs</label></div>
        </div><div class="card-footer"><button type="submit" class="btn btn-primary btn-touch w-100" id="rule-form-save-btn">Save for every issuer</button></div></form></div>
        <div class="col-lg-6">
        <?php if ($issuer === null): ?><div class="card"><div class="card-body fs-12 text-muted" id="rule-override-none">Choose an issuer in the header to set this rule's thresholds for it alone.</div></div>
        <?php else: ?>
        <form method="post" action="/rules/override.php" hx-post="/rules/override.php" hx-target="#flash" class="card" id="rule-override-form"><div class="card-header"><h5 class="card-title mb-0">For <?= e($issuer['name']) ?> only</h5></div><div class="card-body">
            <?= csrf_field() ?><input type="hidden" name="rule" value="<?= $rid ?>"><input type="hidden" name="issuer" value="<?= (int) $issuer['issuer_id'] ?>"><input type="hidden" name="return_to" value="/rules?issuer=<?= (int) $issuer['issuer_id'] ?>">
            <p class="fs-12 text-muted">Leave a threshold empty to take the rule's. These replace the issuer's earlier thresholds for this rule.</p>
            <?php foreach ($params as $k => $v): ?>
                <label class="form-label fs-12" for="rule-override-param-<?= e($k) ?>"><?= e($label((string) $k)) ?> <span class="text-muted">(the rule's: <?= e(is_scalar($v) ? (string) $v : '') ?>)</span></label>
                <input type="text" inputmode="decimal" name="params[<?= e($k) ?>]" id="rule-override-param-<?= e($k) ?>" value="<?= e(array_key_exists($k, $own) && is_scalar($own[$k]) ? (string) $own[$k] : '') ?>" class="form-control form-control-sm mb-2">
            <?php endforeach; ?>
            <label class="form-label fs-12" for="rule-override-enabled">For this issuer the rule</label>
            <select name="enabled" id="rule-override-enabled" class="form-select form-select-sm">
                <option value=""<?= $rule['override_enabled'] === null ? ' selected' : '' ?>>follows the rule (<?= $rule['enabled'] ? 'runs' : 'off' ?>)</option>
                <option value="1"<?= $rule['override_enabled'] === true ? ' selected' : '' ?>>runs</option>
                <option value="0"<?= $rule['override_enabled'] === false ? ' selected' : '' ?>>is off</option>
            </select>
        </div><div class="card-footer"><button type="submit" class="btn btn-primary btn-touch w-100" id="rule-override-save-btn">Save for <?= e($issuer['name']) ?></button></div></form>
        <?php endif; ?>
        </div>
    </div>
</div>
