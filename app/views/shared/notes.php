<?php /** A record's notes, newest first, and the field that adds one (note_add). Data: kind, record, notes, here, tz */ $may = has_right('notes.write'); ?>
<div id="notes">
<?php if ($may): ?>
<form method="post" action="/notes/add.php" hx-post="/notes/add.php" hx-target="#flash" class="mb-3" id="note-form"><?= csrf_field() ?>
    <input type="hidden" name="kind" value="<?= e($kind) ?>"><input type="hidden" name="record" value="<?= (int) $record ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
    <label class="form-label fs-12 text-muted" for="note-form-field-body">Add a note</label>
    <textarea name="body" id="note-form-field-body" class="form-control mb-2" rows="2" maxlength="4000" required></textarea>
    <button type="submit" class="btn btn-light btn-touch" id="note-form-save-btn"><i class="feather-message-square me-1"></i>Add the note</button>
</form>
<?php endif; ?>
<?php if ($notes === []): ?><div class="fs-12 text-muted" id="notes-empty">No notes yet.</div><?php endif; ?>
<?php foreach ($notes as $n): ?>
    <div class="border-start border-3 ps-3 mb-3" id="note-<?= (int) $n['note_id'] ?>"><div class="fs-11 text-muted"><?= e($n['author'] ?? 'Someone') ?> · <?= e(format_ts($n['created_at'], $tz, 'M j, Y g:i A')) ?></div><div class="si-note-body"><?= nl2br(e($n['body'])) ?></div></div>
<?php endforeach; ?>
</div>
