<?php /** The agents (screen `admin-agents`). Data: ov (agents_overview), list (find_dispatches), f (filters), tz, here, notice */
$s = $ov['settings'];
$days = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
$qs = static fn (array $over): string => '/admin/agents?' . http_build_query(array_filter(array_merge($f, $over), static fn ($v) => $v !== null && $v !== ''));
$sel = static fn (string $name, array $opts, ?string $val, string $label): string => '<div class="col-6 col-md"><label class="form-label fs-11 text-muted mb-1" for="agents-filter-' . $name . '">' . e($label) . '</label><select name="' . $name . '" id="agents-filter-' . $name . '" class="form-select form-select-sm btn-touch">'
    . implode('', array_map(static fn ($k, $v) => '<option value="' . e((string) $k) . '"' . ((string) $k === (string) $val ? ' selected' : '') . '>' . e($v) . '</option>', array_keys($opts), $opts)) . '</select></div>';
?>
<?= view('shared/header.php', ['id' => 'admin-agents', 'title' => 'Agents', 'crumbs' => [['Home', '/'], ['Agents', null]]]) ?>
<div class="main-content" id="admin-agents-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card h-100" id="agents-list"><div class="card-header"><h5 class="card-title mb-0">The agents here</h5></div>
            <div class="list-group list-group-flush">
            <?php if ($ov['agents'] === []): ?><div class="list-group-item text-muted" id="agents-empty">No agent is admitted here yet. Hiring and granting are done in the Business OS: an agent that holds a role here appears on its first call.</div><?php endif; ?>
            <?php foreach ($ov['agents'] as $a): $l = $a['last']; ?>
                <div class="list-group-item" id="agent-row-<?= (int) $a['member_id'] ?>">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <span class="fw-semibold"><?= e($a['display_name']) ?></span>
                        <?= (int) $a['member_id'] === $ov['analyst'] ? '<span class="badge text-bg-primary" id="agent-analyst-chip">the Analyst</span>' : '' ?>
                        <?php foreach ($a['roles'] as $r): ?><span class="badge text-bg-light"><?= e($r) ?></span><?php endforeach; ?>
                    </div>
                    <div class="fs-11 text-muted"><?= e((string) ($a['job_title'] ?? '')) ?></div>
                    <div class="fs-12 mt-1"><?php if ($l === null): ?>No dispatch yet.<?php else: ?>Last: <?= e(DISPATCH_KINDS[$l['kind']] ?? $l['kind']) ?> <?= dispatch_status_chip((string) $l['status']) ?> <span class="text-muted"><?= e(format_ts($l['created_at'], $tz, 'M j, g:i A')) ?></span><?php endif; ?>
                        <?php if ($a['week'] !== []): ?><span class="text-muted ms-2">this week: <?= e(implode(', ', array_map(static fn ($k, $n) => $n . ' ' . $k, array_keys($a['week']), $a['week']))) ?></span><?php endif; ?></div>
                </div>
            <?php endforeach; ?>
            </div></div>
        </div>
        <div class="col-lg-6">
            <form method="post" action="/agents/settings.php" hx-post="/agents/settings.php" hx-target="#flash" class="card h-100" id="agents-settings"><?= csrf_field() ?>
                <input type="hidden" name="return_to" value="/admin/agents">
                <div class="card-header"><h5 class="card-title mb-0">The Analyst's settings</h5></div>
                <div class="card-body">
                    <div class="form-check form-switch mb-2"><input type="hidden" name="morning_note_enabled" value="0"><input class="form-check-input" type="checkbox" role="switch" name="morning_note_enabled" value="1" id="agents-settings-morning"<?= $s['morning_note_enabled'] ? ' checked' : '' ?>><label class="form-check-label fs-13" for="agents-settings-morning">The morning note, every market day</label></div>
                    <div class="row g-2 mb-2"><div class="col-6"><label class="form-label fs-12 text-muted" for="agents-settings-hour">At (hour, <?= e($s['market_timezone']) ?>)</label>
                        <select name="morning_note_hour" id="agents-settings-hour" class="form-select btn-touch"><?php for ($h = 0; $h < 24; $h++): ?><option value="<?= $h ?>"<?= $h === $s['morning_note_hour'] ? ' selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option><?php endfor; ?></select></div>
                        <div class="col-6"><label class="form-label fs-12 text-muted" for="agents-settings-packday">The weekly pack is drafted on</label>
                        <select name="pack_day" id="agents-settings-packday" class="form-select btn-touch"><?php foreach ($days as $n => $w): ?><option value="<?= $n ?>"<?= $n === $s['pack_day'] ? ' selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div></div>
                    <div class="form-check form-switch mb-3"><input type="hidden" name="analyst_proposals" value="0"><input class="form-check-input" type="checkbox" role="switch" name="analyst_proposals" value="1" id="agents-settings-proposals"<?= $s['analyst_proposals'] ? ' checked' : '' ?>><label class="form-check-label fs-13" for="agents-settings-proposals">The Analyst proposes (dispositions, matches, kinds) — a person always decides</label></div>
                    <fieldset class="mb-3" id="agents-settings-issuers"><legend class="form-label fs-12 text-muted mb-1">The issuers the Analyst covers <span class="fw-normal">(none ticked = every issuer)</span></legend>
                        <input type="hidden" name="analyst_issuers[]" value="">
                        <?php foreach ($ov['issuers'] as $i): $iid = (int) $i['issuer_id']; ?>
                            <div class="form-check"><input class="form-check-input" type="checkbox" name="analyst_issuers[]" value="<?= $iid ?>" id="agents-settings-issuer-<?= $iid ?>"<?= in_array($iid, $s['analyst_issuer_ids'], true) ? ' checked' : '' ?>><label class="form-check-label fs-13" for="agents-settings-issuer-<?= $iid ?>"><?= e($i['name']) ?><?= $i['ticker'] ? ' · ' . e($i['ticker']) : '' ?></label></div>
                        <?php endforeach; ?>
                        <?php if ($ov['issuers'] === []): ?><div class="fs-12 text-muted">No issuer yet.</div><?php endif; ?>
                    </fieldset>
                    <label class="form-label fs-12 text-muted" id="agents-settings-escalate-label" for="agents-settings-escalate-open">The person told of every high signal</label>
                    <?= picker_field(['id' => 'agents-settings-escalate', 'name' => 'escalate_high_to', 'source' => 'member', 'value' => $s['escalate_high_to'], 'labelledby' => 'agents-settings-escalate-label', 'title' => 'People and agents', 'placeholder' => 'No one']) ?>
                </div>
                <div class="card-footer"><button type="submit" class="btn btn-primary btn-touch w-100" id="agents-settings-save-btn"><i class="feather-save me-1"></i>Save the settings</button></div>
            </form>
        </div>
    </div>
    <div class="card" id="dispatches-card">
        <div class="card-header d-flex flex-wrap align-items-center gap-2"><h5 class="card-title mb-0">Dispatches</h5>
            <span class="fs-12 text-muted ms-auto" id="dispatches-counts"><?= e(implode(' · ', array_map(static fn ($k, $n) => $n . ' ' . strtolower(DISPATCH_STATUSES[$k]), array_keys(array_filter($list['counts'])), array_filter($list['counts'])))) ?: 'none yet' ?></span></div>
        <div class="card-body py-2 border-bottom"><form method="get" action="/admin/agents" hx-get="/admin/agents" hx-target="#page-content" hx-push-url="true" id="dispatches-filters"><div class="row g-2 align-items-end">
            <?= $sel('status', ['' => 'Any'] + DISPATCH_STATUSES, $f['status'], 'Status') ?>
            <?= $sel('kind', ['' => 'Any'] + DISPATCH_KINDS, $f['kind'], 'About') ?>
            <div class="col-12 col-md-auto"><button type="submit" class="btn btn-light btn-sm btn-touch w-100" id="dispatches-filter-btn">Show</button></div>
        </div></form></div>
        <?php if ($list['rows'] === []): ?>
            <div class="card-body text-muted" id="dispatches-empty"><?= $f['status'] !== '' || $f['kind'] !== '' ? 'No dispatch matches these filters.' : 'No dispatch yet. A high signal, a Schedule 13D, an upload that ties and the weekly pack each hand the Analyst one.' ?></div>
        <?php else: ?>
        <div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="dispatches-table">
            <thead class="thead-light"><tr><th>About</th><th class="d-none d-md-table-cell">Agent</th><th>Status</th><th class="d-none d-md-table-cell">Tries</th><th class="d-none d-md-table-cell">Made</th><th class="d-none d-lg-table-cell">Its answer</th><th class="text-end"></th></tr></thead>
            <tbody>
            <?php foreach ($list['rows'] as $d): $did = (int) $d['dispatch_id']; $url = dispatch_record_url($d); ?>
                <tr id="dispatch-row-<?= $did ?>" data-status="<?= e((string) $d['status']) ?>" data-kind="<?= e((string) $d['kind']) ?>">
                    <td class="text-wrap"><?= $url !== null ? hx_link(with_back($url, $here), e(DISPATCH_KINDS[$d['kind']] ?? $d['kind']), 'fw-semibold text-dark') : '<span class="fw-semibold">' . e(DISPATCH_KINDS[$d['kind']] ?? $d['kind']) . '</span>' ?>
                        <div class="fs-11 text-muted d-md-none"><?= e((string) ($d['agent_name'] ?? '')) ?> · attempt <?= (int) $d['attempts'] ?> · <?= e(format_ts($d['created_at'], $tz, 'M j')) ?></div>
                        <?php if (($d['detail'] ?? '') !== ''): ?><div class="fs-11 text-muted text-break"><?= e((string) $d['detail']) ?></div><?php endif; ?></td>
                    <td class="d-none d-md-table-cell"><?= e((string) ($d['agent_name'] ?? '')) ?></td>
                    <td><?= dispatch_status_chip((string) $d['status']) ?></td>
                    <td class="d-none d-md-table-cell"><?= (int) $d['attempts'] ?></td>
                    <td class="d-none d-md-table-cell text-nowrap"><?= e(format_ts($d['created_at'], $tz, 'M j, g:i A')) ?></td>
                    <td class="d-none d-lg-table-cell text-break" style="max-width: 28rem"><?= e((string) ($d['reply_excerpt'] ?? '')) ?></td>
                    <td class="text-end"><?php if (in_array($d['status'], DISPATCH_CANCELLABLE, true)): ?>
                        <form method="post" action="/agents/dispatches/cancel.php" hx-post="/agents/dispatches/cancel.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="dispatch" value="<?= $did ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                            <button type="submit" class="btn btn-light btn-sm btn-touch" id="dispatch-cancel-<?= $did ?>"><i class="feather-x me-1"></i>Cancel</button></form>
                    <?php endif; ?></td>
                </tr>
                <?php if (($d['reply_excerpt'] ?? '') !== ''): ?><tr class="d-lg-none"><td colspan="7" class="fs-11 text-muted border-top-0 pt-0">“<?= e((string) $d['reply_excerpt']) ?>”</td></tr><?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php if ($list['pages'] > 1): ?><nav class="d-flex justify-content-between align-items-center fs-12 p-3" id="dispatches-pages">
            <span class="text-muted"><?= (int) $list['total'] ?> dispatches · page <?= (int) $list['page'] ?> of <?= (int) $list['pages'] ?></span>
            <span class="d-flex gap-2"><?= $list['page'] > 1 ? hx_link($qs(['page' => $list['page'] - 1]), 'Newer', 'btn btn-light btn-sm', 'id="dispatches-prev"') : '' ?><?= $list['page'] < $list['pages'] ? hx_link($qs(['page' => $list['page'] + 1]), 'Older', 'btn btn-light btn-sm', 'id="dispatches-next"') : '' ?></span>
        </nav><?php endif; ?>
        <?php endif; ?>
    </div>
    <p class="fs-11 text-muted mt-3" id="agents-footnote">An agent proposes and a person decides: nothing an agent does here leaves the business, deletes or changes what a number is measured against without a person's approval in the Business OS. Hires, grants and retirements are made there.</p>
</div>
