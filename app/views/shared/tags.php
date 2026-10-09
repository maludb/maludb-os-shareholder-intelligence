<?php
/** A record's tags as chips, each removable, and the field that adds one (tag_apply / tag_remove). Data: kind, record, tags (labels), here (return_to), suggestions? (labels) */
$may = has_right('tags.apply');
$suggestions = $suggestions ?? [];
?>
<div class="d-flex flex-wrap align-items-center gap-1" id="<?= e($kind) ?>-<?= (int) $record ?>-tags">
    <?php foreach ($tags as $t): ?>
        <span class="badge bg-soft-primary text-primary d-inline-flex align-items-center gap-1 py-2 px-2"><i class="feather-tag"></i><?= e($t) ?>
            <?php if ($may): ?><form method="post" action="/tags/remove.php" hx-post="/tags/remove.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?>
                <input type="hidden" name="kind" value="<?= e($kind) ?>"><input type="hidden" name="record" value="<?= (int) $record ?>"><input type="hidden" name="tag" value="<?= e($t) ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                <button type="submit" class="btn btn-link p-0 text-primary lh-1" aria-label="Remove the tag <?= e($t) ?>" title="Remove"><i class="feather-x"></i></button></form><?php endif; ?>
        </span>
    <?php endforeach; ?>
    <?php if ($tags === []): ?><span class="fs-12 text-muted">No tags.</span><?php endif; ?>
</div>
<?php if ($may): ?>
<form method="post" action="/tags/apply.php" hx-post="/tags/apply.php" hx-target="#flash" class="d-flex gap-2 mt-2" id="<?= e($kind) ?>-<?= (int) $record ?>-tag-form"><?= csrf_field() ?>
    <input type="hidden" name="kind" value="<?= e($kind) ?>"><input type="hidden" name="record" value="<?= (int) $record ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
    <input type="text" name="tag" class="form-control btn-touch" maxlength="60" placeholder="Add a tag" list="<?= e($kind) ?>-tag-suggestions" aria-label="A tag" id="<?= e($kind) ?>-<?= (int) $record ?>-tag-field" required>
    <datalist id="<?= e($kind) ?>-tag-suggestions"><?php foreach ($suggestions as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?></datalist>
    <button type="submit" class="btn btn-light btn-touch" id="<?= e($kind) ?>-<?= (int) $record ?>-tag-btn" aria-label="Add the tag"><i class="feather-plus"></i></button>
</form>
<?php endif; ?>
