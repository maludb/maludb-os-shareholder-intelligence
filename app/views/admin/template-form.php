<?php /** A template (screen `admin-template-edit`). Data: t (find_template or null for a new one), mappingText, tz, back */
$id = $t === null ? null : (int) $t['template_id'];
$m = json_decode((string) $mappingText, true);
$cols = is_array($m) && is_array($m['columns'] ?? null) ? $m['columns'] : [];
$cell = static fn ($v): string => is_array($v) ? implode(' + ', array_map('strval', $v)) : (string) $v;
$locked = $t !== null && $t['connector'] === 'ta_extract_csv';
?>
<?= view('shared/header.php', ['id' => 'admin-template-edit', 'title' => $t === null ? 'New template' : 'Change ' . $t['name'], 'crumbs' => [['Home', '/'], ['Import templates', '/admin/templates'], [$t === null ? 'New' : $t['name'], null]], 'back' => $back]) ?>
<div class="main-content" id="admin-template-edit-content">
    <div class="row g-3">
        <div class="col-lg-7">
            <form method="post" action="/admin/templates/save.php" hx-post="/admin/templates/save.php" hx-target="#flash" class="card" id="template-form"><?= csrf_field() ?>
                <?php if ($id !== null): ?><input type="hidden" name="template" value="<?= $id ?>"><?php endif; ?>
                <div class="card-header"><h5 class="card-title mb-0">The template</h5></div>
                <div class="card-body">
                    <label class="form-label fs-12 text-muted" for="template-form-name">Name</label>
                    <input type="text" name="name" id="template-form-name" class="form-control btn-touch mb-2" required maxlength="160" value="<?= e((string) ($t['name'] ?? '')) ?>">
                    <div class="row g-2 mb-2"><div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="template-form-kind">Kind</label>
                        <select name="kind" id="template-form-kind" class="form-select btn-touch"><?php foreach (TEMPLATE_KINDS as $k => $w): ?><option value="<?= e($k) ?>"<?= ($t['kind'] ?? 'register') === $k ? ' selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
                        <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="template-form-connector">Connector</label>
                        <input type="text" name="connector" id="template-form-connector" class="form-control btn-touch" required maxlength="60" pattern="[a-z][a-z0-9_]*" value="<?= e((string) ($t['connector'] ?? 'generic_mapped')) ?>"<?= $locked ? ' readonly' : '' ?>></div></div>
                    <label class="form-label fs-12 text-muted" for="template-form-mapping">The mapping (a JSON object)</label>
                    <textarea name="mapping" id="template-form-mapping" class="form-control font-monospace fs-12" rows="14" spellcheck="false" required><?= e((string) $mappingText) ?></textarea>
                    <?php if ($locked): ?><div class="form-text">The <code>drop_columns</code> may grow, never lose a column the application never stores (tax id, date of birth, phone, email).</div><?php endif; ?>
                </div>
                <div class="card-footer d-flex flex-wrap gap-2"><button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="template-form-save-btn"><i class="feather-save me-1"></i>Save the template</button>
                    <?= hx_link('/admin/templates', 'Cancel', 'btn btn-light btn-touch', 'id="template-form-cancel"') ?></div>
            </form>
        </div>
        <div class="col-lg-5">
            <div class="card" id="template-sample"><div class="card-header"><h5 class="card-title mb-0">What the mapping reads</h5></div>
            <?php if ($cols === []): ?><div class="card-body fs-12 text-muted" id="template-sample-empty">This mapping names no <code>columns</code>. Save a mapping with a <code>columns</code> object ({"field": "column"}) to see it here.</div>
            <?php else: ?><div class="table-responsive"><table class="table mb-0 fs-12" id="template-sample-table"><thead class="thead-light"><tr><th>Field</th><th>Read from</th></tr></thead><tbody>
                <?php foreach ($cols as $field => $col): ?><tr><td><?= e((string) $field) ?></td><td><code class="text-break"><?= e($cell($col)) ?></code></td></tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
            <?php if (is_array($m) && is_array($m['drop_columns'] ?? null)): ?><div class="card-footer fs-12" id="template-sample-drop"><span class="text-muted">Never stored:</span> <?= e(implode(', ', array_map('strval', $m['drop_columns']))) ?></div><?php endif; ?></div>
            <?php if ($id !== null && !$locked): ?>
            <form method="post" action="/admin/templates/delete.php" hx-post="/admin/templates/delete.php" hx-target="#flash" hx-confirm="Delete the template <?= e($t['name']) ?>?" class="mt-3"><?= csrf_field() ?><input type="hidden" name="template" value="<?= $id ?>">
                <button type="submit" class="btn btn-light btn-touch w-100" id="template-delete-btn"><i class="feather-trash-2 me-1"></i>Delete the template</button></form>
            <?php endif; ?>
        </div>
    </div>
</div>
