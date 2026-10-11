<?php /** The participant classes (screen `admin-participant-classes`). Data: classes (classes_overview), class (the key chosen or ''), people (the participants of it), more (how many past the list), tz, here, notice */
$vocab = array_column($classes, 'name', 'class_key');
?>
<?= view('shared/header.php', ['id' => 'admin-participant-classes', 'title' => 'Participant classes', 'crumbs' => [['Home', '/'], ['Sources and settings', has_right('sources.manage') ? '/admin/sources' : null], ['Participant classes', null]]]) ?>
<div class="main-content" id="admin-participant-classes-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('admin/partials/tabs.php', ['active' => 'classes']) ?>
    <div class="row g-3 mb-3">
        <div class="col-lg-7">
            <div class="card h-100" id="classes"><div class="card-header"><h5 class="card-title mb-0">The classes</h5></div>
            <div class="list-group list-group-flush">
            <?php foreach ($classes as $c): $k = $c['class_key']; ?>
                <a class="list-group-item list-group-item-action<?= $k === $class ? ' active' : '' ?>" id="class-row-<?= e($k) ?>" href="/admin/participant-classes?class=<?= e($k) ?>" hx-get="/admin/participant-classes?class=<?= e($k) ?>" hx-target="#page-content" hx-push-url="true">
                    <div class="d-flex flex-wrap align-items-center gap-2"><span class="fw-semibold"><?= e($c['name']) ?></span><code class="fs-11"><?= e($k) ?></code><span class="badge text-bg-light"><?= e($c['side']) ?></span>
                        <span class="ms-auto badge text-bg-secondary"><?= (int) $c['count'] ?> participant<?= (int) $c['count'] === 1 ? '' : 's' ?></span></div>
                    <div class="fs-11 <?= $k === $class ? '' : 'text-muted' ?>"><?= e($c['description']) ?><?= (int) $c['manual'] > 0 ? ' · ' . (int) $c['manual'] . ' set by a person' : '' ?></div></a>
            <?php endforeach; ?>
            </div></div>
        </div>
        <div class="col-lg-5">
            <form method="post" action="/admin/participant-classes/set.php" hx-post="/admin/participant-classes/set.php" hx-target="#flash" class="card h-100" id="class-form"><?= csrf_field() ?>
                <input type="hidden" name="return_to" value="<?= e($here) ?>">
                <div class="card-header"><h5 class="card-title mb-0">Reclass a participant</h5></div>
                <div class="card-body">
                    <label class="form-label fs-12 text-muted" id="class-form-participant-label" for="class-form-participant-open">The participant</label>
                    <?= picker_field(['id' => 'class-form-participant', 'name' => 'participant', 'source' => 'participant', 'labelledby' => 'class-form-participant-label', 'required' => true, 'title' => 'DTC participants', 'placeholder' => 'Choose a participant']) ?>
                    <label class="form-label fs-12 text-muted mt-3" for="class-form-class">Its class</label>
                    <select name="class" id="class-form-class" class="form-select btn-touch" required><?php foreach ($vocab as $k => $n): ?><option value="<?= e($k) ?>"><?= e($n) ?></option><?php endforeach; ?></select>
                    <div class="form-text">A class set here is a person's: the directory's own reading of a name never overwrites it.</div>
                </div>
                <div class="card-footer"><button type="submit" class="btn btn-primary btn-touch w-100" id="class-form-save-btn">Reclass</button></div>
            </form>
        </div>
    </div>
    <?php if ($class !== ''): ?>
    <div class="card" id="class-participants"><div class="card-header d-flex flex-wrap align-items-center gap-2"><h5 class="card-title mb-0"><?= e($vocab[$class] ?? $class) ?></h5>
        <span class="fs-12 text-muted ms-auto"><?= count($people) ?><?= $more > 0 ? ' of ' . (count($people) + $more) : '' ?> shown</span></div>
        <?php if ($people === []): ?><div class="card-body text-muted" id="class-participants-empty">No participant is in this class.</div><?php else: ?>
        <div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="class-participants-table">
            <thead class="thead-light"><tr><th>Participant</th><th class="d-none d-md-table-cell">Set by</th><th class="text-end">Move to</th></tr></thead>
            <tbody>
            <?php foreach ($people as $p): $pid = (int) $p['participant_id']; ?>
                <tr id="participant-row-<?= $pid ?>">
                    <td class="text-wrap"><?= hx_link(with_back('/participants/' . $pid, $here), e($p['number'] . ' ' . $p['name']), 'fw-semibold text-dark') ?><div class="fs-11 text-muted d-md-none"><?= e(str_replace('_', ' ', $p['class_source'])) ?></div></td>
                    <td class="d-none d-md-table-cell"><?= e(str_replace('_', ' ', $p['class_source'])) ?></td>
                    <td class="text-end"><form method="post" action="/admin/participant-classes/set.php" hx-post="/admin/participant-classes/set.php" hx-target="#flash" class="d-flex gap-1 justify-content-end"><?= csrf_field() ?>
                        <input type="hidden" name="participant" value="<?= $pid ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                        <label class="visually-hidden" for="participant-row-<?= $pid ?>-class">Class of <?= e($p['number']) ?></label>
                        <select name="class" id="participant-row-<?= $pid ?>-class" class="form-select form-select-sm btn-touch" style="max-width: 11rem"><?php foreach ($vocab as $k => $n): ?><option value="<?= e($k) ?>"<?= $k === $class ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select>
                        <button type="submit" class="btn btn-light btn-sm btn-touch" id="participant-row-<?= $pid ?>-save-btn">Move</button></form></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div><?php endif; ?>
        <?php if ($more > 0): ?><div class="card-footer fs-12 text-muted" id="class-participants-more"><?= (int) $more ?> more: find one with the participant picker above.</div><?php endif; ?>
    </div>
    <?php endif; ?>
</div>
