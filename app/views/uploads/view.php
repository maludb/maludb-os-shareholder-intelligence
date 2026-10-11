<?php /** One upload (screen `upload-view`): the state, the file's totals against the rows', the difference named, the rows (failed first), the override, delete, restore, read again. Data: u, totals, rows, rowsPage, notice, tz, back */
$id = (int) $u['import_id'];
[$c, $l] = upload_state($u);
$n = static fn ($v) => $v === null ? '—' : number_format((float) $v, 0);
$queued = in_array($u['status'], ['queued', 'running'], true);
$ctl = $totals['control'];
?>
<?= view('shared/header.php', ['id' => 'upload-view', 'title' => UPLOAD_KINDS[$u['kind']] ?? $u['kind'], 'crumbs' => [['Home', '/'], ['Uploads', '/uploads'], ['Upload ' . $id, null]], 'back' => $back]) ?>
<div class="main-content" id="upload-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="upload-summary"><div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <span class="badge text-bg-<?= e($c) ?> fs-12" id="upload-status"<?= $queued ? ' hx-get="/uploads/' . $id . '/status" hx-trigger="every 2s" hx-swap="outerHTML"' : '' ?>><?= e($l) ?></span>
            <span class="fw-semibold text-break"><?= e($u['file_name']) ?></span>
        </div>
        <dl class="row mb-0 fs-12">
            <dt class="col-5 col-md-3 text-muted fw-normal">Security</dt><dd class="col-7 col-md-9"><?= e($u['security_name']) ?> · <?= e((string) $u['cusip']) ?></dd>
            <?php if ($u['kind'] !== 'spr' && $u['record_date'] !== null): ?><dt class="col-5 col-md-3 text-muted fw-normal">Record date</dt><dd class="col-7 col-md-9" id="upload-record-date"><?= e(format_date($u['record_date'])) ?></dd>
            <?php elseif ($u['period_from'] !== null): ?><dt class="col-5 col-md-3 text-muted fw-normal">Position dates</dt><dd class="col-7 col-md-9" id="upload-period"><?= e(format_date($u['period_from'])) ?><?= $u['period_from'] !== $u['period_to'] ? ' to ' . e(format_date($u['period_to'])) : '' ?></dd><?php endif; ?>
            <dt class="col-5 col-md-3 text-muted fw-normal">Read as</dt><dd class="col-7 col-md-9"><?= e(match ($u['connector']) { 'dtc_spr_csv' => 'DTC\'s weekly report (CSV)', 'dtc_spr_onetime_csv' => 'DTC\'s one-time report (CSV)', 'dtc_spr_xlsx' => 'DTC\'s report (workbook)', 'broadridge_nobo_csv' => 'Broadridge\'s NOBO job file (CSV)', 'broadridge_nobo_xlsx' => 'Broadridge\'s NOBO job file (workbook)',
                'broadridge_nobo_names_xlsx' => 'Broadridge\'s NOBO names export', 'ta_extract_csv' => 'the transfer agent\'s Shareholder Extract', 'stc_position_detail' => 'STC\'s position detail',
                'broadridge_share_range_xls' => 'Broadridge\'s Share Range Analysis', 'generic_mapped' => (($tn = (json_decode((string) ($u['control_totals'] ?? ''), true) ?: [])['template'] ?? null) ? 'the import template ' . $tn : 'an import template') . ' — only its mapped columns kept',
                default => (string) $u['connector'] }) ?></dd>
            <dt class="col-5 col-md-3 text-muted fw-normal"><?= $u['kind'] === 'spr' ? 'Positions' : 'Rows' ?></dt><dd class="col-7 col-md-9"><?= e(number_format((int) $u['rows_made'])) ?><?= (int) $u['rows_failed'] > 0 ? ' · <span class="text-danger">' . (int) $u['rows_failed'] . ' row(s) failed to type</span>' : '' ?></dd>
            <dt class="col-5 col-md-3 text-muted fw-normal">Uploaded</dt><dd class="col-7 col-md-9"><?= e(format_ts($u['created_at'], $tz, 'M j, Y g:i A')) ?><?= $u['created_by_name'] ? ' by ' . e($u['created_by_name']) : '' ?></dd>
            <?php if ($u['superseded_by'] !== null): ?><dt class="col-5 col-md-3 text-muted fw-normal">Overlapped</dt><dd class="col-7 col-md-9"><?= hx_link('/uploads/' . (int) $u['superseded_by'], 'upload ' . (int) $u['superseded_by'], '') ?> carries the dates they share (DTC repeats the previous Friday as the Opening)</dd><?php endif; ?>
        </dl>
        <?php if ($u['status'] === 'failed'): ?><div class="alert alert-danger fs-12 mt-3 mb-0" id="upload-failed"><?= e((string) $u['log']) ?></div><?php endif; ?>
        <?php if ($u['override_at'] !== null): ?><div class="alert alert-warning fs-12 mt-3 mb-0" id="upload-override-note">Accepted with its difference by <?= e((string) $u['override_by_name']) ?> on <?= e(format_ts($u['override_at'], $tz, 'M j, Y')) ?>: <?= e((string) $u['override_note']) ?></div><?php endif; ?>
    </div></div>
    <?php if ($u['kind'] !== 'spr' && in_array($u['status'], ['done'], true)): ?><?= view('uploads/partials/holders.php', ['u' => $u, 'facts' => $facts ?? null, 'totals' => $totals]) ?>
    <?php elseif ($totals['by_date'] !== []): ?>
    <div class="card mb-3" id="upload-totals"><div class="card-header"><h5 class="card-title mb-0">What the file says of itself, against its rows</h5></div><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12" id="upload-totals-table">
        <thead class="thead-light"><tr><th>Date</th><th class="text-end">The file's total</th><th class="text-end">The rows</th><th class="text-end">Difference</th></tr></thead><tbody>
        <?php foreach ($totals['by_date'] as $t): $ok = $t['difference'] === '0'; ?><tr id="upload-total-<?= e($t['date']) ?>"><td class="text-nowrap"><?= e(date('D M j', strtotime($t['date']))) ?></td><td class="text-end"><?= e($n($t['file'])) ?></td><td class="text-end"><?= e($n($t['rows'])) ?></td>
            <td class="text-end <?= $ok ? 'text-success' : 'text-danger fw-semibold' ?>"><?= $t['difference'] === null ? '—' : ($ok ? '<i class="feather-check"></i> 0' : e(((int) $t['difference'] > 0 ? '+' : '') . number_format((int) $t['difference']))) ?></td></tr><?php endforeach; ?>
        <?php foreach ($totals['absent'] as $d): ?><tr class="text-muted"><td><?= e(date('D M j', strtotime($d))) ?></td><td colspan="3">no balance that day (a holiday)</td></tr><?php endforeach; ?>
        </tbody></table></div>
        <div class="p-3 fs-12 text-muted"><?= ($ctl['layout'] ?? '') === 'one_time' ? 'A one-time report carries no Total row: it is tied to the sum of its own rows.' : 'The file\'s Total row on each date is its control: every participant\'s balance must add up to it.' ?>
            <?= $u['tie_note'] ? '<div class="text-danger mt-1" id="upload-tie-note">' . e((string) $u['tie_note']) . '</div>' : '' ?></div></div></div>
    <?php endif; ?>
    <div class="row g-3">
        <?php if ($u['kind'] === 'spr'): ?>
        <div class="col-xl-8">
            <div class="card" id="upload-rows"><div class="card-header"><h5 class="card-title mb-0">The rows as read</h5></div><div class="card-body p-0">
                <?php if ($rows === []): ?><div class="p-3 fs-12 text-muted">No row<?= $queued ? ' yet: the file is being read' : '' ?>.</div>
                <?php else: ?><div class="table-responsive"><table class="table table-sm mb-0 fs-12" id="upload-rows-table"><thead class="thead-light"><tr><th>Row</th><th>Participant</th><th>Date</th><th class="text-end">Shares</th></tr></thead><tbody>
                    <?php foreach ($rows as $r): ?><tr class="<?= $r['error'] ? 'table-danger' : '' ?>"><td><?= (int) $r['row_no'] ?></td><td class="text-wrap"><?= e((string) $r['participant_number']) ?> <span class="text-muted"><?= e((string) $r['participant_name']) ?></span><?= $r['error'] ? '<div class="text-danger">' . e($r['error']) . '</div>' : '' ?></td>
                        <td class="text-nowrap"><?= e(date('M j', strtotime((string) $r['position_date']))) ?></td><td class="text-end"><?= e($n($r['shares'])) ?></td></tr><?php endforeach; ?>
                </tbody></table></div>
                <?php $total = (int) $rows[0]['total']; if ($total > $rowsPage * UPLOAD_ROWS_PAGE): ?><div class="p-2"><?= hx_link('/uploads/' . $id . '?rows=' . ($rowsPage + 1), 'More rows (' . number_format($total) . ' in all)', 'btn btn-light btn-touch w-100', 'id="upload-rows-more"') ?></div><?php endif; ?>
                <?php endif; ?>
            </div></div>
        </div>
        <?php endif; ?>
        <div class="<?= $u['kind'] === 'spr' ? 'col-xl-4' : 'col-12 col-xl-6' ?>">
            <?php if (!$u['deleted'] && $u['tied'] === false && $u['override_at'] === null && $u['status'] === 'done' && has_right('issuers.manage')): ?>
            <form method="post" action="/uploads/override.php" hx-post="/uploads/override.php" hx-target="#flash" class="card mb-3" id="upload-override-form" hx-confirm="Accept this upload with its difference? Its rows will be used as if it tied, the note on record."><?= csrf_field() ?>
                <div class="card-header"><h5 class="card-title mb-0">Accept the difference</h5></div><input type="hidden" name="upload" value="<?= $id ?>">
                <div class="card-body"><label class="form-label" for="upload-override-field-note">Why</label><textarea name="note" id="upload-override-field-note" class="form-control" rows="3" maxlength="1000" required placeholder="DTC confirmed the Total row is wrong by…"></textarea></div>
                <div class="card-footer"><button type="submit" class="btn btn-warning btn-touch w-100" id="upload-override-btn">Accept</button></div>
            </form>
            <?php endif; ?>
            <div class="card" id="upload-actions"><div class="card-body d-flex flex-column gap-2">
                <?php if (!$u['deleted'] && has_right('uploads.write')): ?><form method="post" action="/uploads/reparse.php" hx-post="/uploads/reparse.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="upload" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch w-100" id="upload-reparse-btn"><i class="feather-refresh-cw me-1"></i>Read the file again</button></form><?php endif; ?>
                <?php if (has_right('uploads.delete')): ?>
                    <?php if ($u['deleted']): ?><form method="post" action="/uploads/restore.php" hx-post="/uploads/restore.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="upload" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch w-100" id="upload-restore-btn">Restore</button></form>
                    <?php else: ?><form method="post" action="/uploads/delete.php" hx-post="/uploads/delete.php" hx-target="#flash" hx-confirm="Move this upload to the tombstone? Its positions go now; a manager restores it."><?= csrf_field() ?><input type="hidden" name="upload" value="<?= $id ?>"><button type="submit" class="btn btn-outline-danger btn-touch w-100" id="upload-delete-btn">Delete</button></form><?php endif; ?>
                <?php endif; ?>
                <?php if ($u['kind'] === 'spr'): ?><?= hx_link('/positions?security=' . (int) $u['security_id'] . ($u['period_to'] ? '&date=' . e($u['period_to']) : ''), '<i class="feather-bar-chart-2 me-1"></i>The positions', 'btn btn-light btn-touch w-100', 'id="upload-positions-link"') ?>
                <?php elseif ($u['kind'] === 'nobo'): ?><?= hx_link('/holders?channel=nobo&record_date=' . e((string) $u['record_date']), '<i class="feather-users me-1"></i>The holders on this list', 'btn btn-light btn-touch w-100', 'id="upload-holders-link"') ?>
                    <?php if (has_right('uploads.write') && !$u['deleted'] && ($u['tied'] || $u['override_at'] !== null)): ?><form method="post" action="/holders/matches/run.php" hx-post="/holders/matches/run.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="upload" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch w-100" id="upload-matcher-run-btn"><i class="feather-shuffle me-1"></i>Match the rows again</button></form><?php endif; ?>
                <?php elseif ($u['kind'] === 'register'): ?><?= hx_link('/holders/register?record_date=' . e((string) $u['record_date']), '<i class="feather-book me-1"></i>The register', 'btn btn-light btn-touch w-100', 'id="upload-holders-link"') ?>
                    <?php if (has_right('uploads.write') && !$u['deleted'] && ($u['tied'] || $u['override_at'] !== null)): ?><form method="post" action="/holders/matches/run.php" hx-post="/holders/matches/run.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="upload" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch w-100" id="upload-matcher-run-btn"><i class="feather-shuffle me-1"></i>Match the rows again</button></form><?php endif; ?>
                <?php else: ?><?= hx_link('/holders/ranges?record_date=' . e((string) $u['record_date']), '<i class="feather-bar-chart me-1"></i>The ranges', 'btn btn-light btn-touch w-100', 'id="upload-holders-link"') ?><?php endif; ?>
            </div></div>
        </div>
    </div>
</div>
