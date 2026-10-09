<?php /** The dated share counts (screen `issuer-share-counts`): by security and kind, each with its source; a manager records one. Data: i, securities, security, kind, rows, latest, notice, tz, back */
$id = (int) $i['id'];
$manage = has_right('issuers.manage');
$qs = static fn (array $p): string => '/issuers/' . $id . '/share-counts?' . http_build_query(array_filter($p, static fn ($v) => $v !== null && $v !== ''));
?>
<?= view('shared/header.php', ['id' => 'issuer-share-counts', 'title' => $i['name'] . ' — share counts', 'crumbs' => [['Home', '/'], ['Issuers', '/issuers'], [$i['ticker'] ?: $i['name'], '/issuers/' . $id], ['Share counts', null]], 'back' => $back]) ?>
<div class="main-content" id="issuer-share-counts-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('issuers/partials/tabs.php', ['i' => $i, 'active' => 'share-counts']) ?>
    <?php if ($security === null): ?>
        <div class="card"><div class="card-body text-muted" id="share-counts-no-security">Add a security first: counts belong to a security.</div></div>
    <?php else: $sid = (int) $security['security_id']; ?>
    <div class="d-flex flex-wrap gap-1 mb-2" id="share-counts-securities"><?php foreach ($securities as $s): ?><?= hx_link($qs(['security' => (int) $s['security_id'], 'kind' => $kind]), e($s['name']), 'btn btn-sm btn-touch ' . ((int) $s['security_id'] === $sid ? 'btn-primary' : 'btn-light'), 'id="share-counts-security-' . (int) $s['security_id'] . '"') ?><?php endforeach; ?></div>
    <div class="d-flex flex-wrap gap-1 mb-3" id="share-counts-kinds">
        <?= hx_link($qs(['security' => $sid]), 'Every kind', 'btn btn-sm btn-touch ' . ($kind === null ? 'btn-primary' : 'btn-light'), 'id="share-counts-kind-all"') ?>
        <?php foreach (array_keys($latest) as $k): ?><?= hx_link($qs(['security' => $sid, 'kind' => $k]), e(SHARE_COUNT_KINDS[$k] ?? $k), 'btn btn-sm btn-touch ' . ($kind === $k ? 'btn-primary' : 'btn-light'), 'id="share-counts-kind-' . e($k) . '"') ?><?php endforeach; ?>
    </div>
    <div class="row g-3">
        <div class="<?= $manage ? 'col-xl-8' : 'col-12' ?>">
            <div class="card" id="share-counts-card"><div class="card-body p-0">
                <?php if ($rows === []): ?><div class="p-3 fs-12 text-muted" id="share-counts-empty">No count recorded.</div>
                <?php else: ?><div class="table-responsive"><table class="table mb-0 fs-12" id="share-counts-table"><thead class="thead-light"><tr><th>As of</th><th>Kind</th><th class="text-end">Value</th><th class="d-none d-md-table-cell">Source</th><th class="d-none d-md-table-cell">Note</th><th class="text-end"></th></tr></thead><tbody>
                    <?php foreach ($rows as $c): $cid = (int) $c['share_count_id']; ?><tr id="share-count-<?= $cid ?>">
                        <td class="text-nowrap"><?= e(date('M j, Y', strtotime((string) $c['as_of']))) ?></td><td class="text-wrap"><?= e(SHARE_COUNT_KINDS[$c['kind']] ?? $c['kind']) ?><div class="d-md-none text-muted"><?= e(SHARE_COUNT_SOURCES[$c['source']] ?? $c['source']) ?></div></td>
                        <td class="text-end"><?= e($c['kind'] === 'public_float_value' ? '$' . number_format((float) $c['value'], 0) : fmt_n($c['value'])) ?></td>
                        <td class="d-none d-md-table-cell"><?= e(SHARE_COUNT_SOURCES[$c['source']] ?? $c['source']) ?><?= $c['xbrl_frame'] ? ' <span class="text-muted">' . e($c['xbrl_frame']) . '</span>' : '' ?></td><td class="d-none d-md-table-cell text-muted"><?= e((string) $c['note']) ?></td>
                        <td class="text-end"><?php if ($manage): ?><form method="post" action="/issuers/share-counts/delete.php" hx-post="/issuers/share-counts/delete.php" hx-target="#flash" hx-confirm="Remove the <?= e(SHARE_COUNT_KINDS[$c['kind']] ?? $c['kind']) ?> count of <?= e($c['as_of']) ?>?"><?= csrf_field() ?><input type="hidden" name="share_count" value="<?= $cid ?>">
                            <button type="submit" class="avatar-text avatar-sm border-0" id="share-count-<?= $cid ?>-delete-btn" aria-label="Remove this count"><i class="feather-trash-2"></i></button></form><?php endif; ?></td>
                    </tr><?php endforeach; ?>
                </tbody></table></div><?php endif; ?>
            </div></div>
        </div>
        <?php if ($manage): ?>
        <div class="col-xl-4">
            <form method="post" action="/issuers/share-counts/save.php" hx-post="/issuers/share-counts/save.php" hx-target="#flash" class="card" id="share-count-form"><?= csrf_field() ?>
                <div class="card-header"><h5 class="card-title mb-0">Record a count</h5></div><input type="hidden" name="security" value="<?= $sid ?>"><input type="hidden" name="return_to" value="<?= e($qs(['security' => $sid, 'kind' => $kind])) ?>">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6"><label class="form-label" for="share-count-form-field-kind">Kind</label><select class="form-select btn-touch" id="share-count-form-field-kind" name="kind"><?php foreach (SHARE_COUNT_KINDS as $k => $l): ?><option value="<?= e($k) ?>" <?= ($kind ?? 'outstanding') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                        <div class="col-6"><label class="form-label" for="share-count-form-field-as-of">As of</label><input type="date" class="form-control btn-touch" id="share-count-form-field-as-of" name="as_of" value="<?= e(date('Y-m-d')) ?>" required></div>
                        <div class="col-12"><label class="form-label" for="share-count-form-field-value">Shares</label><input type="text" class="form-control btn-touch" id="share-count-form-field-value" name="value" inputmode="numeric" required placeholder="17,053,900"></div>
                        <div class="col-12"><label class="form-label" for="share-count-form-field-source">From</label><select class="form-select btn-touch" id="share-count-form-field-source" name="source">
                            <option value="manual">Entered by me</option><option value="transfer_agent">The transfer agent</option><option value="dtc_letter">A DTC letter</option></select></div>
                        <div class="col-12"><label class="form-label" for="share-count-form-field-note">Note</label><input type="text" class="form-control btn-touch" id="share-count-form-field-note" name="note" maxlength="500"></div>
                    </div>
                </div>
                <div class="card-footer"><button type="submit" class="btn btn-primary btn-touch w-100" id="share-count-form-save-btn">Record</button></div>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
