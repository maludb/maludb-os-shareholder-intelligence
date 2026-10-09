<?php /** A participant's form (screen `participant-edit`): the class (a person's class is never overwritten by the directory), notes, tags. Data: p, classes */ $pid = (int) $p['participant_id']; ?>
<?= view('shared/header.php', ['id' => 'participant-form', 'title' => 'Edit ' . $p['number'] . ' ' . $p['name'], 'crumbs' => [['Home', '/'], ['Participants', '/participants'], [$p['number'], '/participants/' . $pid], ['Edit', null]], 'back' => ['/participants/' . $pid, $p['name']]]) ?>
<div class="main-content" id="participant-form-content">
    <form method="post" action="/participants/save.php" hx-post="/participants/save.php" hx-target="#flash" class="card" id="participant-form"><?= csrf_field() ?><input type="hidden" name="participant" value="<?= $pid ?>">
        <div class="card-body">
            <div class="mb-3"><label class="form-label d-block">Class</label>
                <div class="d-flex flex-column gap-2" id="participant-form-classes">
                <?php foreach ($classes as $k => [$name, $side, $desc]): ?>
                    <label class="btn btn-light btn-touch text-start" for="participant-form-class-<?= e($k) ?>"><input type="radio" class="form-check-input me-2" name="class" value="<?= e($k) ?>" id="participant-form-class-<?= e($k) ?>" <?= $p['class_key'] === $k ? 'checked' : '' ?>><span class="fw-semibold"><?= e($name) ?></span> <span class="fs-11 text-muted"><?= e($desc) ?></span></label>
                <?php endforeach; ?></div>
                <div class="form-text">Saving makes the class yours: the monthly directory never changes it again.</div></div>
            <div class="mb-3"><label class="form-label" for="participant-form-field-notes">Notes</label><textarea class="form-control" id="participant-form-field-notes" name="notes" rows="3" maxlength="2000"><?= e((string) $p['notes']) ?></textarea></div>
            <div class="mb-0"><label class="form-label" for="participant-form-field-tags">Tags</label><input type="text" class="form-control btn-touch" id="participant-form-field-tags" name="tags" value="<?= e(implode(', ', pg_text_array((string) $p['tags']))) ?>" placeholder="separated by commas"></div>
        </div>
        <div class="card-footer d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="participant-form-save-btn">Save</button><?= hx_link('/participants/' . $pid, 'Cancel', 'btn btn-light btn-touch', 'id="participant-form-cancel-btn"') ?></div>
    </form>
</div>
