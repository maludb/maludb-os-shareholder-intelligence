<?php /** One source (screen `admin-source-view`). Data: s (find_source), base (find_source_base), pulls, next (DateTimeImmutable|null), ua, tz, here, notice, back */
$id = (int) $s['source_id'];
$paused = $s['paused_at'] !== null;
$c = $s['credential'];
$settings = source_settings_array($s);
?>
<?= view('shared/header.php', ['id' => 'admin-source-view', 'title' => $s['name'], 'crumbs' => [['Home', '/'], ['Sources and settings', '/admin/sources'], [$s['name'], null]], 'back' => $back]) ?>
<div class="main-content" id="admin-source-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($s['connector'] !== null && (str_starts_with($s['connector'], 'edgar_') || $s['connector'] === 'sec_ftd')): ?><?= view('admin/partials/ua-banner.php', ['ua' => $ua]) ?><?php endif; ?>
    <div class="row g-3 mb-3">
        <div class="col-lg-7">
            <div class="card h-100" id="source-card"><div class="card-header d-flex flex-wrap align-items-center gap-2"><h5 class="card-title mb-0">The source</h5>
                <span class="ms-auto d-flex flex-wrap gap-2">
                    <?= hx_link('/admin/sources/' . $id . '/edit', '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-sm btn-touch', 'id="source-edit-btn"') ?>
                    <?php if (has_right('credentials.manage')): ?><?= hx_link('/admin/sources/' . $id . '/credential', '<i class="feather-key me-1"></i>' . ($c === null ? 'Set the credential' : 'Rotate the credential'), 'btn btn-light btn-sm btn-touch', 'id="source-credential-btn"') ?><?php endif; ?>
                </span></div>
            <div class="card-body fs-12"><dl class="row mb-0">
                <dt class="col-5 col-md-4 text-muted fw-normal">Connector</dt><dd class="col-7 col-md-8"><code><?= e($s['connector']) ?></code> · <?= e($s['role']) ?></dd>
                <dt class="col-5 col-md-4 text-muted fw-normal">State</dt><dd class="col-7 col-md-8" id="source-state"><?= !$s['active'] ? '<span class="badge text-bg-secondary">inactive</span> ' : '' ?><?= $paused ? '<span class="badge text-bg-warning">paused</span> ' . e((string) $s['paused_reason']) : ($s['active'] ? '<span class="badge text-bg-success">active</span>' : '') ?></dd>
                <dt class="col-5 col-md-4 text-muted fw-normal">Base URL</dt><dd class="col-7 col-md-8 text-break"><?= $s['base_url'] === null ? '—' : e($s['base_url']) ?></dd>
                <dt class="col-5 col-md-4 text-muted fw-normal">Schedule</dt><dd class="col-7 col-md-8" id="source-schedule-words"><?= e(schedule_words($s['schedule_minutes'] === null ? null : (int) $s['schedule_minutes'])) ?></dd>
                <dt class="col-5 col-md-4 text-muted fw-normal">Next slot</dt><dd class="col-7 col-md-8" id="source-next"><?= $next !== null ? e(format_ts($next->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $tz, 'D M j, g:i A')) : '<span class="text-muted">' . e($paused ? 'paused' : (!$s['active'] ? 'inactive' : (($s['schedule_minutes'] ?? 0) <= 0 ? 'manual only' : 'not scheduled'))) . '</span>' ?></dd>
                <dt class="col-5 col-md-4 text-muted fw-normal">Rate</dt><dd class="col-7 col-md-8"><?= rtrim(rtrim(number_format((float) $s['rate_per_second'], 2, '.', ''), '0'), '.') ?> a second · robots <?= e($s['robots_state']) ?></dd>
                <dt class="col-5 col-md-4 text-muted fw-normal">Last good pull</dt><dd class="col-7 col-md-8"><?= $s['last_ok_at'] === null ? 'never' : e(format_ts($s['last_ok_at'], $tz, 'M j, Y g:i A')) ?></dd>
                <dt class="col-5 col-md-4 text-muted fw-normal">Trouble</dt><dd class="col-7 col-md-8"><?= (int) $s['consecutive_failures'] > 0 ? '<span class="badge text-bg-danger">' . (int) $s['consecutive_failures'] . ' failed in a row</span> ' : 'none ' ?><?= $s['backoff_until'] !== null && strtotime((string) $s['backoff_until']) > time() ? 'backed off until ' . e(format_ts($s['backoff_until'], $tz, 'M j, g:i A')) : '' ?></dd>
                <dt class="col-5 col-md-4 text-muted fw-normal">Credential</dt><dd class="col-7 col-md-8" id="source-credential"><?= $c === null ? 'None set.' : e($c['label']) . ' · ending <code>' . e($c['last4']) . '</code>' ?></dd>
                <?php if (($base['user_agent'] ?? '') !== ''): ?><dt class="col-5 col-md-4 text-muted fw-normal">User-agent</dt><dd class="col-7 col-md-8 text-break"><?= e((string) $base['user_agent']) ?></dd><?php endif; ?>
            </dl></div></div>
        </div>
        <div class="col-lg-5">
            <div class="card mb-3" id="source-schedule-card"><div class="card-header"><h5 class="card-title mb-0">Schedule</h5></div>
                <form method="post" action="/admin/sources/schedule.php" hx-post="/admin/sources/schedule.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="source" value="<?= $id ?>"><input type="hidden" name="return_to" value="/admin/sources/<?= $id ?>">
                <div class="card-body"><label class="form-label fs-12 text-muted" for="source-schedule-minutes">Minutes between pulls (0 = manual only)</label>
                    <input type="number" inputmode="numeric" min="0" max="525600" name="schedule_minutes" id="source-schedule-minutes" class="form-control btn-touch" required value="<?= (int) ($s['schedule_minutes'] ?? 0) ?>">
                    <div class="form-text">The worker also keeps the connector's own calendar (market days and hours): a source is pulled when both say so.</div></div>
                <div class="card-footer"><button type="submit" class="btn btn-primary btn-touch w-100" id="source-schedule-save-btn">Save the schedule</button></div></form></div>
            <div class="card" id="source-pause-card"><div class="card-body">
                <?php if ($paused): ?>
                <form method="post" action="/admin/sources/resume.php" hx-post="/admin/sources/resume.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="source" value="<?= $id ?>"><input type="hidden" name="return_to" value="/admin/sources/<?= $id ?>">
                    <button type="submit" class="btn btn-primary btn-touch w-100" id="source-resume-btn">Resume the source</button></form>
                <?php else: ?>
                <form method="post" action="/admin/sources/pause.php" hx-post="/admin/sources/pause.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="source" value="<?= $id ?>"><input type="hidden" name="return_to" value="/admin/sources/<?= $id ?>">
                    <label class="form-label fs-12 text-muted" for="source-pause-reason">Why (shown on the list)</label>
                    <input type="text" name="reason" id="source-pause-reason" class="form-control btn-touch mb-2" maxlength="300" placeholder="Paused while the host is down">
                    <button type="submit" class="btn btn-light btn-touch w-100" id="source-pause-btn">Pause the source</button></form>
                <?php endif; ?>
            </div></div>
        </div>
    </div>
    <div class="card mb-3" id="source-settings-card"><div class="card-header"><h5 class="card-title mb-0">Its settings</h5></div>
        <?php if ($settings === []): ?><div class="card-body text-muted fs-12">No settings.</div><?php else: ?>
        <div class="card-body fs-12"><dl class="row mb-0" id="source-settings">
        <?php foreach ($settings as $k => $v): ?><dt class="col-5 col-md-3 text-muted fw-normal text-break"><?= e((string) $k) ?></dt><dd class="col-7 col-md-9 text-break"><code><?= e(is_scalar($v) || $v === null ? json_encode($v) : json_encode($v, JSON_UNESCAPED_SLASHES)) ?></code></dd><?php endforeach; ?>
        </dl></div><?php endif; ?></div>
    <div class="card" id="source-pulls-card"><div class="card-header"><h5 class="card-title mb-0">The last pulls</h5></div>
        <?php if ($pulls === []): ?><div class="card-body text-muted fs-12" id="source-pulls-empty">This source has not been pulled yet.</div><?php else: ?>
        <div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="source-pulls">
            <thead class="thead-light"><tr><th>When</th><th>Status</th><th class="d-none d-md-table-cell">Seen · new · changed</th><th class="d-none d-md-table-cell">Requests · bytes</th><th>The policy it followed</th></tr></thead>
            <tbody>
            <?php foreach ($pulls as $p): $pid = (int) $p['pull_id']; $pol = present_pull($p)['policy']; ?>
                <tr id="pull-row-<?= $pid ?>" data-status="<?= e($p['status']) ?>">
                    <td class="text-nowrap"><?= e(format_ts($p['started_at'], $tz, 'M j, g:i A')) ?><div class="fs-11 text-muted"><?= e($p['kind']) ?></div></td>
                    <td><?= pull_status_chip($p['status']) ?><?php if (($p['error'] ?? '') !== ''): ?><div class="fs-11 text-danger text-break"><?= e((string) $p['error']) ?></div><?php endif; ?>
                        <div class="fs-11 text-muted d-md-none"><?= (int) $p['items_seen'] ?> · <?= (int) $p['items_new'] ?> · <?= (int) $p['items_changed'] ?></div></td>
                    <td class="d-none d-md-table-cell"><?= (int) $p['items_seen'] ?> · <?= (int) $p['items_new'] ?> · <?= (int) $p['items_changed'] ?></td>
                    <td class="d-none d-md-table-cell"><?= (int) $p['http_requests'] ?> · <?= e(number_format((int) $p['bytes'])) ?></td>
                    <td class="text-break fs-11"><?= $pol['user_agent'] !== null ? e((string) $pol['user_agent']) : '<span class="text-muted">no user-agent</span>' ?>
                        <div class="text-muted"><?= $pol['rate_per_second'] !== null ? e(rtrim(rtrim(number_format((float) $pol['rate_per_second'], 2, '.', ''), '0'), '.')) . '/s' : '' ?>
                            <?= $pol['robots'] !== null ? ' · robots ' . e(is_scalar($pol['robots']) ? (string) $pol['robots'] : json_encode($pol['robots'])) : '' ?><?= $pol['cached'] ? ' · ' . (int) $pol['cached'] . ' not modified' : '' ?><?= $pol['blocked'] ? ' · ' . (int) $pol['blocked'] . ' refused' : '' ?><?= $pol['robots_skipped'] ? ' · ' . (int) $pol['robots_skipped'] . ' skipped by robots' : '' ?><?= $pol['refused_before_request'] ? ' · none sent (no contact)' : '' ?></div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div><?php endif; ?>
    </div>
</div>
