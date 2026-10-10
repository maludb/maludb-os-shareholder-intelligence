<?php /** Edit an institution (screen `institution-edit`). Data: i */ $iid = (int) $i['institution_id']; ?>
<?= view('shared/header.php', ['id' => 'institution-form', 'title' => 'Edit the institution', 'crumbs' => [['Home', '/'], ['Institutions', '/institutions'], [mb_strimwidth((string) $i['name'], 0, 30, '…'), '/institutions/' . $iid], ['Edit', null]], 'back' => ['/institutions/' . $iid, 'The institution']]) ?>
<div class="main-content" id="institution-form-content">
    <form method="post" action="/institutions/save.php" hx-post="/institutions/save.php" hx-target="#flash" class="card" id="institution-form"><?= csrf_field() ?><input type="hidden" name="institution" value="<?= $iid ?>">
        <div class="card-body">
            <div class="mb-3"><label class="form-label" for="institution-form-field-kind">Kind</label><select name="kind" id="institution-form-field-kind" class="form-select btn-touch"><?php foreach (INSTITUTION_KINDS as $k => $l): ?><option value="<?= e($k) ?>" <?= $i['kind'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="mb-3"><label class="btn btn-light btn-touch text-start w-100" for="institution-form-field-is_activist"><input type="hidden" name="is_activist" value="no"><input type="checkbox" class="form-check-input me-2" name="is_activist" value="yes" id="institution-form-field-is_activist" <?= $i['is_activist'] ? 'checked' : '' ?>>An activist</label></div>
            <div class="mb-3"><label class="form-label" for="institution-form-field-website">Website</label><input type="url" name="website" id="institution-form-field-website" class="form-control btn-touch" maxlength="300" value="<?= e((string) $i['website']) ?>"></div>
            <div class="mb-0"><label class="form-label" for="institution-form-field-notes">Notes</label><textarea name="notes" id="institution-form-field-notes" class="form-control" rows="3" maxlength="4000"><?= e((string) $i['notes']) ?></textarea></div>
        </div>
        <div class="card-footer d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="institution-form-save-btn">Save</button><?= hx_link('/institutions/' . $iid, 'Cancel', 'btn btn-light btn-touch', 'id="institution-form-cancel-btn"') ?></div>
    </form>
</div>
