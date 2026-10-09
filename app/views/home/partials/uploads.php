<?php /** The latest upload of each kind with its tie (position-reports.md §5). Data: uploads, tz, may */
$kinds = ['spr' => 'DTC position report', 'nobo' => 'NOBO list', 'register' => 'Register', 'share_range' => 'Share range analysis', 'prices_csv' => 'Prices', 'holders_csv' => 'Holders file', 'voted_unvoted' => 'Voted and unvoted'];
?>
<div class="card mb-3" id="home-uploads"><div class="card-header d-flex align-items-center"><h5 class="card-title mb-0">Latest uploads</h5><?= hx_link('/uploads', 'All uploads', 'ms-auto fs-12 fw-semibold') ?></div><div class="card-body p-0">
<?php if ($uploads === []): ?>
    <div class="text-muted fs-12 p-3" id="home-uploads-empty">Nothing uploaded yet. The weekly DTC position report is the first file to upload<?= $may['upload'] ? ': ' . hx_link('/uploads/new', 'upload it', 'fw-semibold') : '' ?>.</div>
<?php else: ?><div class="table-responsive"><table class="table mb-0 fs-12" id="home-uploads-table"><thead class="thead-light"><tr><th>Kind</th><th>Date</th><th>State</th><th class="d-none d-md-table-cell">Uploaded</th></tr></thead><tbody>
    <?php foreach ($uploads as $u): $state = $u['override_at'] !== null ? ['warning', 'overridden'] : ($u['tied'] === true ? ['success', 'tied'] : ($u['tied'] === false ? ['danger', 'untied'] : ['secondary', $u['status']])); ?>
        <tr id="home-upload-<?= (int) $u['import_id'] ?>"><td><?= hx_link(with_back('/uploads/' . (int) $u['import_id'], '/'), e($kinds[$u['kind']] ?? $u['kind'])) ?></td>
            <td><?= e(format_date($u['record_date'] ?? $u['period_to'])) ?></td>
            <td><span class="badge text-bg-<?= e($state[0]) ?>" title="<?= e((string) $u['tie_note']) ?>"><?= e($state[1]) ?></span></td>
            <td class="d-none d-md-table-cell text-muted"><?= e(format_ts($u['created_at'], $tz, 'M j, g:i A')) ?></td></tr>
    <?php endforeach; ?>
</tbody></table></div><?php endif; ?>
</div></div>
