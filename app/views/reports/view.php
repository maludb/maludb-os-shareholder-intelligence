<?php /** One pack (screen `report-view`). Data: r, sections (HTML), storedAt, notes, canRun, canApprove, hasHtml, hasPdf, tz, back, notice */
$rid = (int) $r['report_id'];
$here = '/reports/' . $rid;
$draft = $r['status'] === 'draft';
$post = static fn (string $action, string $id, string $label, string $icon, string $class, string $confirm = ''): string => '<form method="post" action="/reports/' . $action . '.php" hx-post="/reports/' . $action . '.php" hx-target="#flash"' . ($confirm !== '' ? ' hx-confirm="' . e($confirm) . '"' : '') . ' class="d-inline">'
    . csrf_field() . '<input type="hidden" name="report" value="' . $rid . '"><button type="submit" class="btn ' . $class . ' btn-touch btn-sm" id="' . $id . '"><i class="' . $icon . ' me-1"></i>' . e($label) . '</button></form>';
$action = '';
if ($canRun && $draft) {
    $action .= hx_link(with_back('/reports/' . $rid . '/edit', $here), '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch btn-sm me-1', 'id="report-edit-link"')
        . $post('render', 'report-render-btn', 'Render', 'feather-refresh-cw', 'btn-light me-1');
}
if ($canApprove && $draft) { $action .= $post('approve', 'report-approve-btn', 'Approve', 'feather-check-circle', 'btn-primary me-1', 'Approve this pack? Its narrative is frozen and it is kept as rendered now.'); }
if ($hasHtml) { $action .= '<a class="btn btn-light btn-touch btn-sm me-1" href="/reports/' . $rid . '/file?format=html" id="report-download-html"><i class="feather-download me-1"></i>HTML</a>'; }
if ($hasPdf) { $action .= '<a class="btn btn-light btn-touch btn-sm me-1" href="/reports/' . $rid . '/file?format=pdf" id="report-download-pdf"><i class="feather-file me-1"></i>PDF</a>'; }
?>
<?= view('shared/header.php', ['id' => 'report-view', 'title' => $r['title'], 'crumbs' => [['Home', '/'], ['Reports', '/reports'], [mb_strimwidth((string) $r['title'], 0, 40, '…'), null]], 'back' => $back, 'action' => $action]) ?>
<div class="main-content" id="report-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="report-meta"><div class="card-body d-flex flex-wrap gap-2 align-items-center">
        <span class="badge bg-soft-primary text-primary"><?= e(REPORT_KINDS[$r['kind']] ?? (string) $r['kind']) ?></span><?= report_status_chip((string) $r['status'], ' id="report-status"') ?>
        <span class="fs-12"><?= e(format_date((string) $r['period_from'])) ?> to <?= e(format_date((string) $r['period_to'])) ?></span>
        <span class="fs-12 text-muted ms-auto"><?= $r['approved_by_name'] ? 'Approved by ' . e((string) $r['approved_by_name']) . ' on ' . e(format_ts($r['approved_at'], $tz, 'M j, Y')) : 'Drafted by ' . e((string) ($r['drafted_by_name'] ?? 'someone')) . ($r['drafted_by_kind'] === 'agent' ? ' (agent)' : '') ?></span>
    </div></div>
    <div class="card mb-3" id="report-narrative"><div class="card-header"><h5 class="card-title mb-0">Narrative</h5></div><div class="card-body si-markdown">
        <?= trim((string) $r['narrative']) === '' ? '<span class="text-muted">No narrative yet.</span>' : markdown_html((string) $r['narrative']) ?>
        <?php if (!$draft): ?><div class="fs-11 text-muted mt-2" id="report-frozen"><i class="feather-lock me-1"></i>Frozen: the narrative of an approved pack does not change.</div><?php endif; ?>
    </div></div>
    <div class="card mb-3" id="report-sections"><div class="card-header d-flex flex-wrap align-items-center gap-2"><h5 class="card-title mb-0">The sections</h5>
        <span class="fs-11 text-muted ms-auto"><?= $storedAt ? 'As rendered when the pack was approved' : 'Drawn from the data on record now' ?></span></div>
        <div class="card-body" id="report-sections-body"><?= $sections ?></div></div>
    <?php if ($canApprove && !$draft): ?>
    <form method="post" action="/reports/send.php" hx-post="/reports/send.php" hx-target="#flash" hx-confirm="Send this pack by email to the members you chose? A pack goes to members only." class="card mb-3" id="report-send-form"><?= csrf_field() ?>
        <input type="hidden" name="report" value="<?= $rid ?>">
        <div class="card-header"><h5 class="card-title mb-0">Send to…</h5></div>
        <div class="card-body">
            <p class="fs-12 text-muted">Members only — people who may see this issuer. Each gets the narrative and a link; the tables are read after signing in.</p>
            <?php for ($k = 1; $k <= 3; $k++): ?>
                <div class="mb-2"><label class="form-label fs-12" id="report-send-to-<?= $k ?>-label" for="report-send-field-to-<?= $k ?>-open">Recipient <?= $k ?></label>
                    <?= picker_field(['id' => 'report-send-field-to-' . $k, 'name' => 'recipients[]', 'source' => 'member', 'labelledby' => 'report-send-to-' . $k . '-label', 'title' => 'Send the pack to']) ?></div>
            <?php endfor; ?>
            <div class="mb-0"><label class="form-label fs-12" for="report-send-field-message">A word with it (optional)</label><textarea name="message" id="report-send-field-message" class="form-control" rows="2" maxlength="1000"></textarea></div>
        </div>
        <div class="card-footer"><button type="submit" class="btn btn-primary btn-touch w-100" id="report-send-btn"><i class="feather-send me-1"></i>Send</button></div>
    </form>
    <?php endif; ?>
    <div class="card mb-3" id="report-notes-card"><div class="card-header"><h5 class="card-title mb-0">Notes</h5></div><div class="card-body"><?= view('shared/notes.php', ['kind' => 'report', 'record' => $rid, 'notes' => $notes, 'here' => $here, 'tz' => $tz]) ?></div></div>
    <?php if ($canApprove): ?><form method="post" action="/reports/delete.php" hx-post="/reports/delete.php" hx-target="#flash" hx-confirm="Delete this pack? It goes to the tombstone." class="text-end"><?= csrf_field() ?><input type="hidden" name="report" value="<?= $rid ?>">
        <button type="submit" class="btn btn-light-danger btn-touch btn-sm" id="report-delete-btn"><i class="feather-trash-2 me-1"></i>Delete the pack</button></form><?php endif; ?>
</div>
