<?php /** Record or change an event (screens `event-add`, `event-edit`). Data: e, v, issuer, securities */
$title = $e === null ? 'Record an event' : 'Edit the event';
$fld = static fn (string $n, string $l, $val, string $type = 'text', string $hint = '', string $extra = '') => '<div class="mb-3"><label class="form-label" for="event-form-field-' . $n . '">' . e($l) . '</label><input type="' . $type . '" class="form-control btn-touch" name="' . $n . '" id="event-form-field-' . $n . '" value="' . e((string) $val) . '" ' . $extra . '>' . ($hint ? '<div class="form-text">' . e($hint) . '</div>' : '') . '</div>';
$tags = is_array($v['tags'] ?? null) ? $v['tags'] : pg_text_array((string) ($v['tags'] ?? ''));
?>
<?= view('shared/header.php', ['id' => 'event-form', 'title' => $title, 'crumbs' => [['Home', '/'], ['Events', '/events'], [$title, null]], 'back' => back_link()]) ?>
<div class="main-content" id="event-form-content">
    <form method="post" action="/events/save.php" hx-post="/events/save.php" hx-target="#flash" class="card" id="event-form"><?= csrf_field() ?>
        <?php if ($e !== null): ?><input type="hidden" name="event" value="<?= (int) $e['event_id'] ?>"><?php else: ?><input type="hidden" name="issuer" value="<?= (int) $issuer['issuer_id'] ?>"><?php endif; ?>
        <div class="card-body">
            <div class="row g-2">
                <div class="col-sm-4"><?= $fld('on_date', 'Date', $v['on_date'], 'date', '', 'required') ?></div>
                <div class="col-sm-4 mb-3"><label class="form-label" for="event-form-field-kind">Kind</label><select name="kind" id="event-form-field-kind" class="form-select btn-touch"><?php foreach (EVENT_KINDS as $k => $l): ?><option value="<?= e($k) ?>" <?= $v['kind'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                <div class="col-sm-4 mb-3"><label class="form-label" for="event-form-field-severity">Severity</label><select name="severity" id="event-form-field-severity" class="form-select btn-touch"><?php foreach (EVENT_SEVERITIES as $k => $l): ?><option value="<?= e($k) ?>" <?= $v['severity'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            </div>
            <?= $fld('title', 'What happened', $v['title'], 'text', 'One line, up to 300 characters.', 'required maxlength="300"') ?>
            <div class="mb-3"><label class="form-label" for="event-form-field-body">Details</label><textarea name="body" id="event-form-field-body" class="form-control" rows="5" maxlength="10000"><?= e((string) ($v['body'] ?? '')) ?></textarea>
                <div class="form-text">Markdown: **bold**, *italic*, lists with “-”, [links](https://…).</div></div>
            <?= $fld('url', 'Link to the source', $v['url'] ?? '', 'url', 'The press release, the notice or the filing page.', 'maxlength="1000"') ?>
            <div class="mb-3"><label class="form-label" id="event-form-filing-label" for="event-form-field-filing-open">The filing</label><?= picker_field(['id' => 'event-form-field-filing', 'name' => 'filing', 'source' => 'filing', 'value' => $v['filing_id'] ?? '', 'labelledby' => 'event-form-filing-label', 'params' => ['issuer' => (int) $issuer['issuer_id']]]) ?></div>
            <?php if (count($securities) > 1): ?><div class="mb-3"><label class="form-label" for="event-form-field-security">Security</label><select name="security" id="event-form-field-security" class="form-select btn-touch"><option value="">The whole company</option><?php foreach ($securities as $s): ?><option value="<?= (int) $s['security_id'] ?>" <?= (int) ($v['security_id'] ?? 0) === (int) $s['security_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
            <div class="mb-0"><label class="form-label" for="event-form-field-tags">Tags</label><input type="text" class="form-control btn-touch" id="event-form-field-tags" name="tags" value="<?= e(implode(', ', $tags)) ?>" placeholder="separated by commas"></div>
        </div>
        <div class="card-footer d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="event-form-save-btn">Save</button><?= hx_link($e === null ? '/events' : '/events/' . (int) $e['event_id'], 'Cancel', 'btn btn-light btn-touch', 'id="event-form-cancel-btn"') ?></div>
    </form>
</div>
