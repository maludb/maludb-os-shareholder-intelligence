<?php /** The fetchers (screen `admin-sources`). Data: rows (sources_overview), connectors (connector => n), connector (the filter), ua (the EDGAR user-agent or null), tz, here, notice */
$may = static fn (string $r): bool => has_right($r);
?>
<?= view('shared/header.php', ['id' => 'admin-sources', 'title' => 'Sources and settings', 'crumbs' => [['Home', '/'], ['Sources and settings', null]]]) ?>
<div class="main-content" id="admin-sources-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('admin/partials/tabs.php', ['active' => 'sources']) ?>
    <?= view('admin/partials/ua-banner.php', ['ua' => $ua]) ?>
    <div class="card" id="sources-card">
        <div class="card-header d-flex flex-wrap align-items-center gap-2"><h5 class="card-title mb-0">The sources</h5>
            <form method="get" action="/admin/sources" hx-get="/admin/sources" hx-target="#page-content" hx-push-url="true" class="ms-auto d-flex gap-2 align-items-center" id="sources-filter">
                <label class="visually-hidden" for="sources-filter-connector">Connector</label>
                <select name="connector" id="sources-filter-connector" class="form-select form-select-sm btn-touch"><option value="">Every connector</option>
                <?php foreach ($connectors as $c => $n): ?><option value="<?= e($c) ?>"<?= $c === $connector ? ' selected' : '' ?>><?= e($c) ?><?= (int) $n > 1 ? ' (' . (int) $n . ')' : '' ?></option><?php endforeach; ?></select>
                <button type="submit" class="btn btn-light btn-sm btn-touch" id="sources-filter-btn">Show</button>
            </form></div>
        <?php if ($rows === []): ?><div class="card-body text-muted" id="sources-empty">No source<?= $connector !== '' ? ' of that connector' : '' ?>.</div><?php else: ?>
        <div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="sources-table">
            <thead class="thead-light"><tr><th>Source</th><th class="d-none d-md-table-cell">Schedule</th><th class="d-none d-lg-table-cell">Next slot</th><th>Last pull</th><th class="d-none d-md-table-cell">Trouble</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $id = (int) $r['source_id']; $paused = $r['paused_at'] !== null; ?>
                <tr id="source-row-<?= $id ?>" data-connector="<?= e($r['connector']) ?>" data-state="<?= !$r['active'] ? 'inactive' : ($paused ? 'paused' : 'active') ?>">
                    <td class="text-wrap"><?= hx_link('/admin/sources/' . $id, e($r['name']), 'fw-semibold text-dark') ?>
                        <div class="fs-11 text-muted"><code><?= e($r['connector']) ?></code>
                            <?= $paused ? ' <span class="badge text-bg-warning" id="source-row-' . $id . '-paused">paused</span>' : '' ?><?= !$r['active'] ? ' <span class="badge text-bg-secondary">inactive</span>' : '' ?>
                            <?= $r['has_credential'] ? ' <span class="badge text-bg-light">key set</span>' : '' ?></div>
                        <?php if ($paused && $r['paused_reason'] !== null): ?><div class="fs-11 text-muted text-break"><?= e((string) $r['paused_reason']) ?></div><?php endif; ?>
                        <div class="fs-11 text-muted d-md-none"><?= e(schedule_words($r['schedule_minutes'] === null ? null : (int) $r['schedule_minutes'])) ?></div>
                        <div class="d-flex flex-wrap gap-1 mt-2">
                        <?php if ($may('uploads.write') && $r['active'] && !$paused): ?>
                        <form method="post" action="/admin/sources/pull.php" hx-post="/admin/sources/pull.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="source" value="<?= $id ?>"><input type="hidden" name="return_to" value="/admin/sources<?= $connector !== '' ? '?connector=' . rawurlencode($connector) : '' ?>">
                            <button type="submit" class="btn btn-light btn-sm btn-touch" id="source-row-<?= $id ?>-pull-btn">Pull now</button></form>
                        <?php endif; ?>
                        <?php if ($paused): ?>
                        <form method="post" action="/admin/sources/resume.php" hx-post="/admin/sources/resume.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="source" value="<?= $id ?>"><input type="hidden" name="return_to" value="/admin/sources">
                            <button type="submit" class="btn btn-light btn-sm btn-touch" id="source-row-<?= $id ?>-resume-btn">Resume</button></form>
                        <?php else: ?>
                        <form method="post" action="/admin/sources/pause.php" hx-post="/admin/sources/pause.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="source" value="<?= $id ?>"><input type="hidden" name="return_to" value="/admin/sources">
                            <button type="submit" class="btn btn-light btn-sm btn-touch" id="source-row-<?= $id ?>-pause-btn">Pause</button></form>
                        <?php endif; ?>
                        </div>
                    </td>
                    <td class="d-none d-md-table-cell"><?= e(schedule_words($r['schedule_minutes'] === null ? null : (int) $r['schedule_minutes'])) ?></td>
                    <td class="d-none d-lg-table-cell text-nowrap" id="source-row-<?= $id ?>-next"><?= $r['next_slot'] instanceof DateTimeInterface ? e(format_ts($r['next_slot']->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $tz, 'D M j, g:i A')) : '<span class="text-muted">' . e($paused ? 'paused' : (!$r['active'] ? 'inactive' : (($r['schedule_minutes'] ?? 0) <= 0 ? 'manual' : 'not scheduled'))) . '</span>' ?></td>
                    <td><?= pull_status_chip($r['last_status']) ?><?= $r['last_pull_at'] !== null ? '<div class="fs-11 text-muted">' . e(format_ts($r['last_pull_at'], $tz, 'M j, g:i A')) . '</div>' : '' ?>
                        <?php if (($r['last_error'] ?? '') !== '' && $r['last_status'] !== 'ok'): ?><div class="fs-11 text-danger text-break"><?= e(mb_substr((string) $r['last_error'], 0, 90)) ?></div><?php endif; ?></td>
                    <td class="d-none d-md-table-cell"><?php if ((int) $r['consecutive_failures'] > 0): ?><span class="badge text-bg-danger"><?= (int) $r['consecutive_failures'] ?> failed</span><?php endif; ?>
                        <?php if ($r['backoff_until'] !== null && strtotime((string) $r['backoff_until']) > time()): ?><div class="fs-11 text-muted">backed off until <?= e(format_ts($r['backoff_until'], $tz, 'M j, g:i A')) ?></div><?php endif; ?>
                        <?php if ($r['needs_user_agent'] && !$r['user_agent_set']): ?><div class="fs-11 text-danger">no EDGAR contact</div><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
    <p class="fs-12 text-muted mt-2 mb-0">Pull now reads a source once, within its own rules: the user-agent it declares, the rate it keeps, robots honoured, a refusal backing it off. A paused source is left alone until it is resumed.</p>
</div>
