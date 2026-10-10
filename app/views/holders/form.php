<?php /** Edit a holder (screen `holder-edit`). Data: h */
$hid = (int) $h['holder_id'];
$f = static fn (string $name, string $label, ?string $value, string $type = 'text', int $max = 200): string => '<div class="mb-3"><label class="form-label" for="holder-form-field-' . $name . '">' . e($label) . '</label>'
    . '<input type="' . $type . '" class="form-control btn-touch" name="' . $name . '" id="holder-form-field-' . $name . '" value="' . e((string) $value) . '" maxlength="' . $max . '"></div>';
?>
<?= view('shared/header.php', ['id' => 'holder-form', 'title' => 'Edit the holder', 'crumbs' => [['Home', '/'], ['Holders', '/holders'], [mb_strimwidth((string) $h['display_name'], 0, 30, '…'), '/holders/' . $hid], ['Edit', null]], 'back' => ['/holders/' . $hid, 'The holder']]) ?>
<div class="main-content" id="holder-form-content">
    <form method="post" action="/holders/save.php" hx-post="/holders/save.php" hx-target="#flash" class="card" id="holder-form"><?= csrf_field() ?><input type="hidden" name="holder" value="<?= $hid ?>">
        <div class="card-body">
            <?= $f('display_name', 'Name', $h['display_name']) ?>
            <div class="row g-2"><div class="col-sm-6 mb-3"><label class="form-label" for="holder-form-field-kind">Kind</label><select name="kind" id="holder-form-field-kind" class="form-select btn-touch"><?php foreach (HOLDER_KINDS as $k => $l): ?><option value="<?= e($k) ?>" <?= $h['kind'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                <div class="col-sm-6 mb-3"><label class="form-label" for="holder-form-field-entity_type">An entity's type</label><select name="entity_type" id="holder-form-field-entity_type" class="form-select btn-touch"><option value="">—</option><?php foreach (HOLDER_ENTITY_TYPES as $t): ?><option value="<?= e($t) ?>" <?= $h['entity_type'] === $t ? 'selected' : '' ?>><?= e(ucfirst($t)) ?></option><?php endforeach; ?></select></div></div>
            <?= $f('address_line', 'Street', $h['address_line'], 'text', 300) ?>
            <div class="row g-2"><div class="col-sm-5"><?= $f('city', 'City', $h['city'], 'text', 120) ?></div><div class="col-4 col-sm-3"><?= $f('state', 'State', $h['state'], 'text', 40) ?></div><div class="col-8 col-sm-4"><?= $f('zip', 'Zip', $h['zip'], 'text', 20) ?></div></div>
            <?= $f('country', 'Country', $h['country'], 'text', 60) ?>
            <div class="mb-0"><label class="form-label" for="holder-form-field-notes">Notes</label><textarea name="notes" id="holder-form-field-notes" class="form-control" rows="3" maxlength="4000"><?= e((string) $h['notes']) ?></textarea></div>
        </div>
        <div class="card-footer d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="holder-form-save-btn">Save</button><?= hx_link('/holders/' . $hid, 'Cancel', 'btn btn-light btn-touch', 'id="holder-form-cancel-btn"') ?></div>
    </form>
</div>
