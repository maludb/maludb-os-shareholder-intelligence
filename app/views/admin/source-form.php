<?php /** Change a source (screen `admin-source-edit`). Data: b (find_source_base), errors, old (the form's values after a refusal), ceiling, tz, back */
$id = (int) $b['id'];
$v = static fn (string $k, mixed $default) => $old[$k] ?? $default;
$settingsText = $old['settings'] ?? json_encode(json_decode((string) $b['settings'], true) ?: new stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$edgar = str_starts_with((string) $b['connector'], 'edgar_');
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
?>
<?= view('shared/header.php', ['id' => 'admin-source-edit', 'title' => 'Change ' . $b['name'], 'crumbs' => [['Home', '/'], ['Sources and settings', '/admin/sources'], [$b['name'], '/admin/sources/' . $id], ['Change', null]], 'back' => $back]) ?>
<div class="main-content" id="admin-source-edit-content">
    <form method="post" action="/admin/sources/save.php" hx-post="/admin/sources/save.php" hx-target="#flash" class="card" id="source-form"><?= csrf_field() ?>
        <input type="hidden" name="source" value="<?= $id ?>">
        <div class="card-header"><h5 class="card-title mb-0">The source <code class="fs-12"><?= e($b['connector']) ?></code></h5></div>
        <div class="card-body">
            <label class="form-label fs-12 text-muted" for="source-form-name">Name</label>
            <input type="text" name="name" id="source-form-name" class="form-control btn-touch mb-1" required maxlength="160" value="<?= e((string) $v('name', $b['name'])) ?>"><?= $err('name') ?>
            <label class="form-label fs-12 text-muted mt-2" for="source-form-base-url">Base URL</label>
            <input type="url" name="base_url" id="source-form-base-url" class="form-control btn-touch mb-1" maxlength="500" value="<?= e((string) $v('base_url', (string) $b['base_url'])) ?>"><?= $err('base_url') ?>
            <div class="row g-2 mt-1"><div class="col-6"><label class="form-label fs-12 text-muted" for="source-form-rate">Requests a second (0.1 to 10<?= $edgar ? ', at most ' . e(rtrim(rtrim(number_format($ceiling, 2, '.', ''), '0'), '.')) . ' for EDGAR' : '' ?>)</label>
                <input type="number" step="0.05" min="0.1" max="10" name="rate_per_second" id="source-form-rate" class="form-control btn-touch" required value="<?= e((string) $v('rate_per_second', rtrim(rtrim(number_format((float) $b['rate_per_second'], 2, '.', ''), '0'), '.'))) ?>"><?= $err('rate_per_second') ?></div>
                <div class="col-6 d-flex align-items-end"><div class="form-check form-switch mb-2"><input type="hidden" name="active" value="0"><input class="form-check-input" type="checkbox" role="switch" name="active" value="1" id="source-form-active"<?= $b['active'] ? ' checked' : '' ?>><label class="form-check-label fs-13" for="source-form-active">Active</label></div></div></div>
            <label class="form-label fs-12 text-muted mt-2" for="source-form-user-agent">User-agent override<?= $edgar ? ' (an EDGAR source always sends the business name and contact from Settings)' : '' ?></label>
            <input type="text" name="user_agent" id="source-form-user-agent" class="form-control btn-touch mb-1" maxlength="200" value="<?= e((string) $v('user_agent', (string) $b['user_agent'])) ?>" placeholder="Blank: the business name and contact from Settings"><?= $err('user_agent') ?>
            <label class="form-label fs-12 text-muted mt-2" for="source-form-settings">Settings (a JSON object; the connector's own bookkeeping lives here too)</label>
            <textarea name="settings" id="source-form-settings" class="form-control font-monospace fs-12 mb-1" rows="10" spellcheck="false"><?= e((string) $settingsText) ?></textarea><?= $err('settings') ?>
            <div class="form-text">No key or password here: a secret belongs on the credential.</div>
        </div>
        <div class="card-footer d-flex flex-wrap gap-2"><button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="source-form-save-btn"><i class="feather-save me-1"></i>Save the source</button>
            <?= hx_link('/admin/sources/' . $id, 'Cancel', 'btn btn-light btn-touch', 'id="source-form-cancel"') ?></div>
    </form>
</div>
