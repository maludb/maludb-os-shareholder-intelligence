<?php /** The tombstones (screen `admin-purge`). Data: rows (tombstones), kind (the filter), days (tombstone_days), restore (kind => action path), tz, here, notice */
$kinds = ['upload' => 'Uploads', 'holder' => 'Holders', 'event' => 'Events', 'report' => 'Reports', 'issuer' => 'Issuers'];
$due = count(array_filter($rows, static fn (array $r): bool => (bool) $r['due']));
?>
<?= view('shared/header.php', ['id' => 'admin-purge', 'title' => 'Purge', 'crumbs' => [['Home', '/'], ['Sources and settings', has_right('sources.manage') ? '/admin/sources' : null], ['Purge', null]]]) ?>
<div class="main-content" id="admin-purge-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('admin/partials/tabs.php', ['active' => 'purge']) ?>
    <div class="card" id="purge-card">
        <div class="card-header d-flex flex-wrap align-items-center gap-2"><h5 class="card-title mb-0">Waiting in the tombstone</h5>
            <span class="fs-12 text-muted" id="purge-counts"><?= count($rows) ?> waiting · <?= $due ?> past their <?= (int) $days ?> days</span>
            <?php if ($due > 0): ?><form method="post" action="/admin/purge/run.php" hx-post="/admin/purge/run.php" hx-target="#flash" hx-confirm="Delete for good everything past its <?= (int) $days ?> days<?= $kind !== '' ? ' (' . e($kinds[$kind] ?? $kind) . ' only)' : '' ?>? This cannot be undone." class="ms-auto"><?= csrf_field() ?>
                <input type="hidden" name="kind" value="<?= e($kind === '' ? 'all' : $kind) ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                <button type="submit" class="btn btn-danger btn-sm btn-touch" id="purge-run-btn"><i class="feather-trash-2 me-1"></i>Purge what is due (<?= $due ?>)</button></form><?php endif; ?></div>
        <div class="card-body py-2 border-bottom"><form method="get" action="/admin/purge" hx-get="/admin/purge" hx-target="#page-content" hx-push-url="true" class="row g-2 align-items-end" id="purge-filter">
            <div class="col-8 col-md-4"><label class="form-label fs-11 text-muted mb-1" for="purge-filter-kind">Kind</label>
                <select name="kind" id="purge-filter-kind" class="form-select form-select-sm btn-touch"><option value="">Every kind</option><?php foreach ($kinds as $k => $w): ?><option value="<?= e($k) ?>"<?= $k === $kind ? ' selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
            <div class="col-4 col-md-auto"><button type="submit" class="btn btn-light btn-sm btn-touch w-100" id="purge-filter-btn">Show</button></div></form></div>
        <?php if ($rows === []): ?><div class="card-body text-muted" id="purge-empty">Nothing waits in the tombstone<?= $kind !== '' ? ' of that kind' : '' ?>.</div><?php else: ?>
        <div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="purge-table">
            <thead class="thead-light"><tr><th>What</th><th class="d-none d-md-table-cell">Deleted</th><th>Purges after</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $k = $r['kind']; $rid = (int) $r['record_id']; ?>
                <tr id="purge-row-<?= e($k) ?>-<?= $rid ?>" data-kind="<?= e($k) ?>" data-due="<?= $r['due'] ? '1' : '0' ?>">
                    <td class="text-wrap"><span class="badge text-bg-light me-1"><?= e($k) ?></span><span class="text-break"><?= e($r['label']) ?></span>
                        <div class="fs-11 text-muted d-md-none"><?= e(format_ts($r['deleted_at'], $tz, 'M j, Y')) ?><?= $r['deleted_by_name'] !== null ? ' by ' . e($r['deleted_by_name']) : '' ?></div>
                        <div class="d-flex flex-wrap gap-1 mt-2">
                        <?php if (isset($restore[$k])): ?>
                        <form method="post" action="<?= e($restore[$k][0]) ?>" hx-post="<?= e($restore[$k][0]) ?>" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="<?= e($restore[$k][1]) ?>" value="<?= $rid ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                            <button type="submit" class="btn btn-light btn-sm btn-touch" id="purge-row-<?= e($k) ?>-<?= $rid ?>-restore-btn">Restore</button></form>
                        <?php endif; ?>
                        <?php if ($r['due']): ?>
                        <form method="post" action="/admin/purge/run.php" hx-post="/admin/purge/run.php" hx-target="#flash" hx-confirm="Delete this <?= e($k) ?> for good? This cannot be undone." class="d-inline"><?= csrf_field() ?><input type="hidden" name="kind" value="<?= e($k) ?>"><input type="hidden" name="record" value="<?= $rid ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                            <button type="submit" class="btn btn-danger btn-sm btn-touch" id="purge-row-<?= e($k) ?>-<?= $rid ?>-purge-btn">Purge now</button></form>
                        <?php endif; ?>
                        </div>
                    </td>
                    <td class="d-none d-md-table-cell text-nowrap"><?= e(format_ts($r['deleted_at'], $tz, 'M j, Y g:i A')) ?><?= $r['deleted_by_name'] !== null ? '<div class="fs-11 text-muted">by ' . e($r['deleted_by_name']) . '</div>' : '' ?></td>
                    <td class="text-nowrap"><?= e(format_ts($r['purge_after'], $tz, 'M j, Y')) ?><?= $r['due'] ? ' <span class="badge text-bg-danger">due</span>' : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div><?php endif; ?>
    </div>
    <p class="fs-12 text-muted mt-2 mb-0">A deleted upload or record waits <?= (int) $days ?> days (Settings) and the worker then removes it for good — its rows, its kept file. Until then Restore brings it back. Purge acts only on what has waited out its window. A report has no restore.</p>
</div>
