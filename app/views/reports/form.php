<?php /** Draft or edit a pack (screens `report-add`, `report-edit`). Data: r, v, issuer */
$title = $r === null ? 'Draft a pack' : 'Edit the pack';
$chosen = report_sections_list($v['sections']);
?>
<?= view('shared/header.php', ['id' => 'report-form', 'title' => $title, 'crumbs' => [['Home', '/'], ['Reports', '/reports'], [$title, null]], 'back' => back_link()]) ?>
<div class="main-content" id="report-form-content">
    <form method="post" action="/reports/save.php" hx-post="/reports/save.php" hx-target="#flash" class="card" id="report-form"><?= csrf_field() ?>
        <?php if ($r !== null): ?><input type="hidden" name="report" value="<?= (int) $r['report_id'] ?>"><?php else: ?><input type="hidden" name="issuer" value="<?= (int) $issuer['issuer_id'] ?>"><?php endif; ?>
        <div class="card-body">
            <div class="row g-2">
                <div class="col-sm-4 mb-3"><label class="form-label" for="report-form-field-kind">Kind</label><select name="kind" id="report-form-field-kind" class="form-select btn-touch"><?php foreach (REPORT_KINDS as $k => $l): ?><option value="<?= e($k) ?>" <?= $v['kind'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                <div class="col-sm-4 mb-3"><label class="form-label" for="report-form-field-period_from">From</label><input type="date" class="form-control btn-touch" name="period_from" id="report-form-field-period_from" value="<?= e((string) $v['period_from']) ?>" required></div>
                <div class="col-sm-4 mb-3"><label class="form-label" for="report-form-field-period_to">To</label><input type="date" class="form-control btn-touch" name="period_to" id="report-form-field-period_to" value="<?= e((string) $v['period_to']) ?>" required></div>
            </div>
            <div class="mb-3"><label class="form-label" for="report-form-field-title">Title</label><input type="text" class="form-control btn-touch" name="title" id="report-form-field-title" maxlength="200" value="<?= e((string) $v['title']) ?>" placeholder="Left blank, the kind and the period make it">
            <?php if ($r === null): ?><div class="form-text">The pack is for <?= e((string) $issuer['name']) ?>.</div><?php endif; ?></div>
            <fieldset class="mb-3" id="report-form-sections"><legend class="form-label fs-14">Sections</legend>
                <div class="row g-1"><?php foreach (REPORT_SECTIONS as $k => $l): ?>
                    <div class="col-md-6"><label class="btn btn-light btn-touch text-start w-100" for="report-form-section-<?= e($k) ?>"><input type="checkbox" class="form-check-input me-2" name="sections[]" value="<?= e($k) ?>" id="report-form-section-<?= e($k) ?>" <?= in_array($k, $chosen, true) ? 'checked' : '' ?>><?= e($l) ?></label></div>
                <?php endforeach; ?></div>
            </fieldset>
            <div class="mb-0"><label class="form-label" for="report-form-field-narrative">Narrative</label><textarea name="narrative" id="report-form-field-narrative" class="form-control" rows="9" maxlength="20000"><?= e((string) ($v['narrative'] ?? '')) ?></textarea>
                <div class="form-text">Markdown: # headings, **bold**, *italic*, lists with “-”, [links](https://…). The only text of the pack that is written, not drawn from the data; frozen when a manager approves.</div></div>
        </div>
        <div class="card-footer d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="report-form-save-btn">Save</button><?= hx_link($r === null ? '/reports' : '/reports/' . (int) $r['report_id'], 'Cancel', 'btn btn-light btn-touch', 'id="report-form-cancel-btn"') ?></div>
    </form>
</div>
