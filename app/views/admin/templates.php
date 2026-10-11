<?php /** The import templates (screen `admin-templates`). Data: rows (find_templates), kind (the filter), tz, notice */
$by = [];
foreach ($rows as $t) { $by[$t['kind']][] = $t; }
?>
<?= view('shared/header.php', ['id' => 'admin-templates', 'title' => 'Import templates', 'crumbs' => [['Home', '/'], ['Sources and settings', has_right('sources.manage') ? '/admin/sources' : null], ['Import templates', null]],
    'action' => hx_link('/admin/templates/new', '<i class="feather-plus me-1"></i>New template', 'btn btn-primary btn-touch', 'id="template-new-btn"')]) ?>
<div class="main-content" id="admin-templates-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('admin/partials/tabs.php', ['active' => 'templates']) ?>
    <div class="card mb-3"><div class="card-body py-2"><form method="get" action="/admin/templates" hx-get="/admin/templates" hx-target="#page-content" hx-push-url="true" class="row g-2 align-items-end" id="templates-filter">
        <div class="col-8 col-md-4"><label class="form-label fs-11 text-muted mb-1" for="templates-filter-kind">Kind</label>
            <select name="kind" id="templates-filter-kind" class="form-select form-select-sm btn-touch"><option value="">Every kind</option><?php foreach (TEMPLATE_KINDS as $k => $w): ?><option value="<?= e($k) ?>"<?= $k === $kind ? ' selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
        <div class="col-4 col-md-auto"><button type="submit" class="btn btn-light btn-sm btn-touch w-100" id="templates-filter-btn">Show</button></div></form></div></div>
    <div id="templates">
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="templates-empty">No template<?= $kind !== '' ? ' of that kind' : '' ?>.</div></div><?php endif; ?>
    <?php foreach ($by as $k => $list): ?>
    <div class="card mb-3" id="templates-kind-<?= e($k) ?>"><div class="card-header"><h5 class="card-title mb-0"><?= e(TEMPLATE_KINDS[$k] ?? $k) ?></h5></div>
        <div class="list-group list-group-flush">
        <?php foreach ($list as $t): $tid = (int) $t['template_id']; $m = json_decode((string) $t['mapping'], true) ?: []; ?>
            <div class="list-group-item d-flex flex-wrap align-items-center gap-2" id="template-row-<?= $tid ?>">
                <div class="me-auto"><?= hx_link('/admin/templates/' . $tid . '/edit', e($t['name']), 'fw-semibold text-dark') ?>
                    <div class="fs-11 text-muted"><code><?= e($t['connector']) ?></code> · <?= count($m) ?> mapping key<?= count($m) === 1 ? '' : 's' ?> · changed <?= e(format_ts($t['updated_at'], $tz, 'M j, Y')) ?></div></div>
                <?= hx_link('/admin/templates/' . $tid . '/edit', 'Edit', 'btn btn-light btn-sm btn-touch', 'id="template-row-' . $tid . '-edit-btn"') ?>
                <?php if ($t['connector'] !== 'ta_extract_csv'): ?>
                <form method="post" action="/admin/templates/delete.php" hx-post="/admin/templates/delete.php" hx-target="#flash" hx-confirm="Delete the template <?= e($t['name']) ?>?" class="d-inline"><?= csrf_field() ?><input type="hidden" name="template" value="<?= $tid ?>">
                    <button type="submit" class="btn btn-light btn-sm btn-touch" id="template-row-<?= $tid ?>-delete-btn">Delete</button></form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div></div>
    <?php endforeach; ?>
    </div>
    <p class="fs-12 text-muted mb-0">A template is a saved column mapping. The layouts of the files the application reads are its connectors' code; of a mapping the parsers read today only the transfer agent's extract, whose <code>drop_columns</code> are stripped before the file is stored.</p>
</div>
