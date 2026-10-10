<?php /** The uploads of the current issuer (screen `upload-list`): a table (a ledger), the filters, the Upload button. Data: issuer, rows, filters, total, page, here, notice, tz */
$f = static fn (array $over): string => '/uploads?' . http_build_query(array_filter($over + $filters, static fn ($v) => $v !== null && $v !== ''));
?>
<?= view('shared/header.php', ['id' => 'upload-list', 'title' => 'Uploads', 'crumbs' => [['Home', '/'], ['Uploads', null]],
    'action' => has_right('uploads.write') ? hx_link('/uploads/new', '<i class="feather-upload-cloud me-1"></i>Upload a file', 'btn btn-primary btn-touch', 'id="upload-add-btn"') : '']) ?>
<div class="main-content" id="upload-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-1 mb-2" id="upload-kinds">
        <?= hx_link($f(['kind' => null]), 'Every kind', 'btn btn-sm btn-touch ' . (($filters['kind'] ?? '') === '' ? 'btn-primary' : 'btn-light'), 'id="upload-kind-all"') ?>
        <?php foreach (UPLOAD_KINDS as $k => $l): ?><?= hx_link($f(['kind' => $k]), e($l), 'btn btn-sm btn-touch ' . (($filters['kind'] ?? '') === $k ? 'btn-primary' : 'btn-light'), 'id="upload-kind-' . e($k) . '"') ?><?php endforeach; ?>
    </div>
    <div class="d-flex flex-wrap gap-1 mb-3" id="upload-states">
        <?php foreach (['' => 'Live', 'tied' => 'Tied', 'untied' => 'Untied', 'overridden' => 'Overridden', 'failed' => 'Failed', 'queued' => 'Queued'] + (has_right('uploads.delete') ? ['deleted' => 'Tombstone'] : []) as $k => $l): ?>
            <?= hx_link($f(['status' => $k]), e($l), 'btn btn-sm btn-touch ' . (($filters['status'] ?? '') === $k ? 'btn-primary' : 'btn-light'), 'id="upload-status-' . e($k ?: 'live') . '"') ?>
        <?php endforeach; ?>
    </div>
    <div class="card" id="upload-list-card"><div class="card-body p-0">
    <?php if ($rows === []): ?><div class="p-3 text-muted" id="upload-list-empty">No upload<?= ($filters['kind'] ?? '') !== '' || ($filters['status'] ?? '') !== '' ? ' matches' : ' yet. The weekly DTC position report is the first file to upload' ?>.</div>
    <?php else: ?><div class="table-responsive"><table class="table mb-0 fs-12" id="upload-table"><thead class="thead-light"><tr><th>File</th><th>Dates</th><th>State</th><th class="d-none d-md-table-cell text-end">Positions</th><th class="d-none d-md-table-cell">Uploaded</th></tr></thead><tbody>
        <?php foreach ($rows as $u): [$c, $l] = upload_state($u); $id = (int) $u['import_id']; ?><tr id="upload-row-<?= $id ?>">
            <td class="text-wrap"><?= hx_link(with_back('/uploads/' . $id, $here), e(UPLOAD_KINDS[$u['kind']] ?? $u['kind']), 'fw-semibold') ?><div class="text-muted text-break"><?= e($u['file_name']) ?></div></td>
            <td class="text-nowrap"><?= $u['period_from'] !== null ? e(date('M j', strtotime($u['period_from']))) . ($u['period_from'] !== $u['period_to'] ? '–' . e(date('M j, Y', strtotime($u['period_to']))) : ', ' . e(date('Y', strtotime($u['period_from'])))) : e(format_date($u['record_date'])) ?></td>
            <td><span class="badge text-bg-<?= e($c) ?>" title="<?= e((string) $u['tie_note']) ?>"><?= e($l) ?></span><?= $u['superseded_by'] !== null ? '<div class="text-muted fs-11">overlapped by ' . (int) $u['superseded_by'] . '</div>' : '' ?></td>
            <td class="d-none d-md-table-cell text-end"><?= e(number_format((int) $u['rows_made'])) ?></td>
            <td class="d-none d-md-table-cell text-muted"><?= e(format_ts($u['created_at'], $tz, 'M j, g:i A')) ?><?= $u['created_by_name'] ? '<div>' . e($u['created_by_name']) . '</div>' : '' ?></td>
        </tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
    </div></div>
    <?php if ($total > $page * UPLOADS_PAGE): ?><div class="mt-2"><?= hx_link($f(['page' => $page + 1]), 'Older uploads', 'btn btn-light btn-touch w-100', 'id="upload-more"') ?></div><?php endif; ?>
</div>
