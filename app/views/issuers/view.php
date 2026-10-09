<?php
/** One issuer (screen `issuer-view`): Overview (the EDGAR entity, the securities, the latest counts with their sources, the last uploads), Sources, Notes. Data: i, o (issuer_overview), tab, sources, tz, notice, here, back */
$id = (int) $i['id'];
$entity = json_decode((string) $i['edgar_entity'], true) ?: [];
$addr = $entity['addresses']['business'] ?? ($entity['addresses']['mailing'] ?? null);
$manage = has_right('issuers.manage');
$action = '';
if ($manage) {
    $action = '<div class="d-flex gap-2">' . hx_link('/issuers/' . $id . '/edit', '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch', 'id="issuer-edit-btn"')
        . ($i['cik'] !== null ? '<form method="post" action="/issuers/edgar-sync.php" hx-post="/issuers/edgar-sync.php" hx-target="#flash">' . csrf_field() . '<input type="hidden" name="issuer" value="' . $id . '">'
            . '<button type="submit" class="btn btn-light btn-touch" id="issuer-sync-btn"><i class="feather-refresh-cw me-1"></i>Read from EDGAR</button></form>' : '') . '</div>';
}
$kinds = ['spr' => 'DTC position report', 'nobo' => 'NOBO list', 'register' => 'Register', 'share_range' => 'Share range analysis', 'prices_csv' => 'Prices'];
?>
<?= view('shared/header.php', ['id' => 'issuer-view', 'title' => $i['name'], 'crumbs' => [['Home', '/'], ['Issuers', '/issuers'], [$i['ticker'] ?: $i['name'], null]], 'back' => $back, 'action' => $action]) ?>
<div class="main-content" id="issuer-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3 fs-12" id="issuer-facts">
        <?php if ($i['ticker']): ?><span class="badge bg-soft-dark text-dark fs-12"><?= e($i['ticker']) ?></span><?php endif; ?>
        <span class="badge bg-soft-secondary text-secondary"><?= e(ISSUER_EXCHANGES[$i['exchange']] ?? $i['exchange']) ?></span>
        <?php if ($i['cik']): ?><a href="https://www.sec.gov/cgi-bin/browse-edgar?action=getcompany&amp;CIK=<?= e($i['cik']) ?>" target="_blank" rel="noopener" id="issuer-cik-link">CIK <?= e(ltrim((string) $i['cik'], '0')) ?> <i class="feather-external-link"></i></a><?php endif; ?>
        <?php if ($i['restricted']): ?><span class="badge bg-soft-warning text-warning" id="issuer-restricted"><i class="feather-lock me-1"></i>restricted to named members</span><?php endif; ?>
        <?php if ($i['transfer_agent']): ?><span class="text-muted">Transfer agent: <?= e($i['transfer_agent']) ?></span><?php endif; ?>
    </div>
    <?= view('issuers/partials/tabs.php', ['i' => $i, 'active' => $tab]) ?>
    <?php if ($tab === 'overview'): ?>
    <div class="row g-3">
        <div class="col-xl-7">
            <div class="card mb-3" id="issuer-counts"><div class="card-header d-flex align-items-center"><h5 class="card-title mb-0">Share counts</h5><?= hx_link('/issuers/' . $id . '/share-counts', 'All counts', 'ms-auto fs-12 fw-semibold') ?></div><div class="card-body">
                <?php if ($o['counts'] === []): ?><div class="fs-12 text-muted" id="issuer-counts-empty">No count yet. EDGAR's cover page fills the outstanding count (slice 4); record one by hand on Share counts.</div>
                <?php else: ?><div class="row g-3"><?php foreach ($o['counts'] as $k => $c): ?>
                    <div class="col-6 col-md-4" id="issuer-count-<?= e($k) ?>"><div class="fs-12 text-muted text-uppercase fw-semibold"><?= e(SHARE_COUNT_KINDS[$k] ?? $k) ?></div><div class="fs-5 fw-bold"><?= e($k === 'public_float_value' ? '$' . number_format((float) $c['value'], 0) : fmt_n($c['value'])) ?></div>
                        <?= view('shared/source-line.php', ['source' => SHARE_COUNT_SOURCES[$c['source']] ?? $c['source'], 'date' => $c['as_of']]) ?></div>
                <?php endforeach; ?></div><?php endif; ?>
            </div></div>
            <div class="card mb-3" id="issuer-securities-card"><div class="card-header d-flex align-items-center"><h5 class="card-title mb-0">Securities</h5><?= hx_link('/issuers/' . $id . '/securities', $manage ? 'Add or edit' : 'All', 'ms-auto fs-12 fw-semibold') ?></div><div class="card-body p-0">
                <?php if ($o['securities'] === []): ?><div class="fs-12 text-muted p-3" id="issuer-securities-empty">No security yet<?= $manage ? ': ' . hx_link('/issuers/' . $id . '/securities', 'add the common stock', 'fw-semibold') : '' ?>.</div>
                <?php else: ?><div class="table-responsive"><table class="table mb-0 fs-12"><thead class="thead-light"><tr><th>Security</th><th>CUSIP</th><th>Ticker</th><th class="d-none d-md-table-cell">Last SPR</th></tr></thead><tbody>
                    <?php foreach ($o['securities'] as $s): ?><tr id="issuer-security-<?= (int) $s['security_id'] ?>"><td><?= e($s['name']) ?><?= $s['is_primary'] ? ' <span class="badge bg-soft-primary text-primary">primary</span>' : '' ?></td><td><?= e($s['cusip'] ?? '—') ?></td><td><?= e($s['ticker'] ?? '—') ?></td><td class="d-none d-md-table-cell"><?= e($s['last_position_date'] !== null ? format_date($s['last_position_date']) : '—') ?></td></tr><?php endforeach; ?>
                </tbody></table></div><?php endif; ?>
            </div></div>
            <div class="card mb-3" id="issuer-uploads"><div class="card-header d-flex align-items-center"><h5 class="card-title mb-0">Latest uploads</h5></div><div class="card-body p-0">
                <?php if ($o['uploads'] === []): ?><div class="fs-12 text-muted p-3">Nothing uploaded yet.</div>
                <?php else: ?><div class="table-responsive"><table class="table mb-0 fs-12"><tbody>
                    <?php foreach ($o['uploads'] as $u): $state = $u['override_at'] !== null ? ['warning', 'overridden'] : ($u['tied'] === true ? ['success', 'tied'] : ($u['tied'] === false ? ['danger', 'untied'] : ['secondary', $u['status']])); ?>
                        <tr><td><?= hx_link(with_back('/uploads/' . (int) $u['import_id'], $here), e($kinds[$u['kind']] ?? $u['kind'])) ?></td><td><?= e(format_date($u['record_date'] ?? $u['period_to'])) ?></td><td><span class="badge text-bg-<?= e($state[0]) ?>"><?= e($state[1]) ?></span></td></tr>
                    <?php endforeach; ?></tbody></table></div><?php endif; ?>
            </div></div>
        </div>
        <div class="col-xl-5">
            <div class="card mb-3" id="issuer-entity"><div class="card-header d-flex align-items-center"><h5 class="card-title mb-0">EDGAR</h5></div><div class="card-body fs-12">
                <?php if ($entity === []): ?><div class="text-muted" id="issuer-entity-empty"><?= $i['cik'] === null ? 'Add the CIK to read the company from EDGAR.' : 'Not read from EDGAR yet.' ?></div>
                <?php else: ?><dl class="row mb-0">
                    <dt class="col-5 text-muted fw-normal">Name at the SEC</dt><dd class="col-7"><?= e($entity['name'] ?? '') ?></dd>
                    <?php if (!empty($entity['sicDescription'])): ?><dt class="col-5 text-muted fw-normal">Industry</dt><dd class="col-7"><?= e($entity['sicDescription']) ?> (SIC <?= e($i['sic'] ?? ($entity['sic'] ?? '')) ?>)</dd><?php endif; ?>
                    <?php if ($i['state_of_incorporation']): ?><dt class="col-5 text-muted fw-normal">Incorporated in</dt><dd class="col-7"><?= e($i['state_of_incorporation']) ?></dd><?php endif; ?>
                    <?php if ($i['fiscal_year_end']): ?><dt class="col-5 text-muted fw-normal">Fiscal year ends</dt><dd class="col-7"><?= e(date('F j', mktime(0, 0, 0, (int) substr($i['fiscal_year_end'], 0, 2), (int) substr($i['fiscal_year_end'], 2, 2), 2001))) ?></dd><?php endif; ?>
                    <?php if (!empty($entity['category'])): ?><dt class="col-5 text-muted fw-normal">Filer category</dt><dd class="col-7"><?= e(trim(strip_tags(str_replace('<br>', ' · ', (string) $entity['category'])), ' ·')) ?></dd><?php endif; ?>
                    <?php if (!empty($entity['tickers'])): ?><dt class="col-5 text-muted fw-normal">Tickers</dt><dd class="col-7"><?= e(implode(', ', (array) $entity['tickers'])) ?></dd><?php endif; ?>
                    <?php if ($addr): ?><dt class="col-5 text-muted fw-normal">Business address</dt><dd class="col-7"><?= e(implode(', ', array_filter([$addr['street1'] ?? null, $addr['street2'] ?? null, $addr['city'] ?? null, $addr['stateOrCountry'] ?? null, $addr['zipCode'] ?? null]))) ?></dd><?php endif; ?>
                    <?php foreach ((array) ($entity['formerNames'] ?? []) as $fn): ?><dt class="col-5 text-muted fw-normal">Formerly</dt><dd class="col-7" id="issuer-former-name"><?= e($fn['name'] ?? '') ?> <span class="text-muted">until <?= e(substr((string) ($fn['to'] ?? ''), 0, 10)) ?></span></dd><?php endforeach; ?>
                </dl>
                <?= view('shared/source-line.php', ['source' => 'EDGAR submissions', 'extra' => 'read ' . format_ts($i['edgar_synced_at'], $tz, 'M j, Y g:i A')]) ?>
                <?php endif; ?>
            </div></div>
            <div class="card mb-3" id="issuer-tags"><div class="card-header d-flex align-items-center"><h5 class="card-title mb-0">Tags</h5></div><div class="card-body"><?= view('shared/tags.php', ['kind' => 'issuer', 'record' => $id, 'tags' => pg_text_array((string) $i['tags']), 'here' => '/issuers/' . $id]) ?></div></div>
            <?php if ($manage): ?><div class="card mb-3" id="issuer-danger"><div class="card-body d-flex align-items-center gap-3"><div class="fs-12 text-muted flex-grow-1">Deleting moves the issuer and everything under it to the tombstone; a manager restores it from the issuers list.</div>
                <form method="post" action="/issuers/delete.php" hx-post="/issuers/delete.php" hx-target="#flash" hx-confirm="Move <?= e($i['name']) ?> and everything under it to the tombstone?"><?= csrf_field() ?><input type="hidden" name="issuer" value="<?= $id ?>">
                    <button type="submit" class="btn btn-outline-danger btn-touch" id="issuer-delete-btn">Delete</button></form></div></div><?php endif; ?>
        </div>
    </div>
    <?php elseif ($tab === 'sources'): ?>
    <div class="card" id="issuer-sources"><div class="card-header d-flex align-items-center"><h5 class="card-title mb-0">The public record read for this issuer</h5></div><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12">
        <thead class="thead-light"><tr><th>Source</th><th>Last read</th><th>State</th></tr></thead><tbody>
        <?php foreach ($sources as $s): $st = !$s['active'] ? ['secondary', 'off'] : ($s['paused'] ? ['warning', 'paused'] : (($s['needs_user_agent'] && !$s['user_agent_set']) ? ['danger', 'waiting for the EDGAR contact'] : ($s['backoff_until'] !== null && strtotime((string) $s['backoff_until']) > time() ? ['warning', 'backed off'] : ($s['last_status'] === 'ok' ? ['success', 'ok'] : ($s['last_status'] === null ? ['secondary', 'not read yet'] : ['danger', $s['last_status']]))))); ?>
            <tr id="issuer-source-<?= (int) $s['source_id'] ?>"><td><?= e($s['name']) ?></td><td><?= e($s['last_ok_at'] !== null ? format_ts($s['last_ok_at'], $tz, 'M j, g:i A') : '—') ?></td><td><span class="badge text-bg-<?= e($st[0]) ?>"><?= e($st[1]) ?></span><?= $s['last_error'] ? '<div class="text-muted">' . e($s['last_error']) . '</div>' : '' ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div></div></div>
    <?php else: ?>
    <div class="card" id="issuer-notes"><div class="card-body"><?= view('shared/notes.php', ['kind' => 'issuer', 'record' => $id, 'notes' => $o['notes'], 'here' => '/issuers/' . $id . '?tab=notes', 'tz' => $tz]) ?></div></div>
    <?php endif; ?>
</div>
