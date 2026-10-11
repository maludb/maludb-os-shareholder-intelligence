<?php /** My exports (screen `export-list`). Data: rows, kind, all, shared, may, holders, issuer, securities, tz, here, back, notice */
$kinds = $holders ? EXPORT_KINDS : array_diff_key(EXPORT_KINDS, ['holders_csv' => 1]);
?>
<?= view('shared/header.php', ['id' => 'export-list', 'title' => 'Exports', 'crumbs' => [['Home', '/'], ['Reports', '/reports'], ['Exports', null]], 'back' => $back]) ?>
<div class="main-content" id="export-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($may && $issuer !== null && $securities !== []): ?>
    <form method="post" action="/exports/create.php" hx-post="/exports/create.php" hx-target="#flash" class="card mb-3" id="export-form"><?= csrf_field() ?>
        <div class="card-header"><h5 class="card-title mb-0">Export a table of <?= e((string) $issuer['name']) ?></h5></div>
        <div class="card-body"><div class="row g-2 align-items-end">
            <div class="col-md-3"><label class="form-label fs-12" for="export-form-field-kind">What</label><select name="kind" id="export-form-field-kind" class="form-select btn-touch"><?php foreach ($kinds as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label fs-12" for="export-form-field-security">Security</label><select name="security" id="export-form-field-security" class="form-select btn-touch"><?php foreach ($securities as $s): ?><option value="<?= (int) $s['security_id'] ?>"><?= e((string) ($s['ticker'] ?: $s['name'])) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-2"><label class="form-label fs-12" for="export-form-field-from">From</label><input type="date" class="form-control btn-touch" name="from" id="export-form-field-from"></div>
            <div class="col-6 col-md-2"><label class="form-label fs-12" for="export-form-field-to">To</label><input type="date" class="form-control btn-touch" name="to" id="export-form-field-to"></div>
            <div class="col-12 col-md-2"><button type="submit" class="btn btn-primary btn-touch w-100" id="export-form-btn"><i class="feather-download me-1"></i>Export</button></div>
        </div>
        <p class="fs-11 text-muted mt-2 mb-0">The period applies to short interest, signals, events and filings (the last 90 days when blank). Holders and 13F exports take the latest list or quarter; positions the latest report. An export is built from what you may see and kept for seven days.</p></div>
    </form>
    <?php endif; ?>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2 fs-12" id="exports-scope">
        <?= hx_link('/exports', 'Mine', 'btn btn-sm btn-touch ' . ($all ? 'btn-light' : 'btn-primary'), 'id="exports-mine"') ?>
        <?php if ($shared): ?><?= hx_link('/exports?all=1', 'Everyone\'s', 'btn btn-sm btn-touch ' . ($all ? 'btn-primary' : 'btn-light'), 'id="exports-all"') ?><?php endif; ?>
    </div>
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body text-muted" id="exports-empty">No export yet.</div></div>
    <?php else: ?>
        <div class="card"><div class="list-group list-group-flush" id="exports-list">
        <?php foreach ($rows as $x): $xid = (int) $x['export_id']; $expired = $x['status'] === 'done' && strtotime((string) $x['expires_at']) <= time(); ?>
            <div class="list-group-item d-flex flex-wrap align-items-center gap-2" id="export-row-<?= $xid ?>" data-kind="<?= e((string) $x['kind']) ?>" data-status="<?= e((string) $x['status']) ?>">
                <div class="flex-grow-1 min-w-0"><div class="fw-semibold"><?= e(EXPORT_KINDS[$x['kind']] ?? (string) $x['kind']) ?> <span class="fs-12 text-muted fw-normal"><?= e((string) ($x['ticker'] ?? '')) ?></span>
                    <?= $x['includes_pii'] ? '<span class="badge text-bg-danger ms-1" id="export-pii-' . $xid . '">contains names</span>' : '' ?></div>
                    <div class="fs-11 text-muted"><?= $x['status'] === 'done' ? number_format((int) $x['item_count']) . ' rows · ' . e(number_format(((int) $x['byte_size']) / 1024, 1)) . ' KB' : e((string) $x['status']) ?> · asked <?= e(format_ts($x['created_at'], $tz, 'M j, g:i A')) ?><?= $all ? ' by ' . e((string) ($x['created_by_name'] ?? 'someone')) : '' ?> ·
                        <?= $expired ? 'expired' : 'expires ' . e(format_ts($x['expires_at'], $tz, 'M j')) ?></div></div>
                <?php if ($x['status'] === 'done' && !$expired): ?><a class="btn btn-light btn-touch btn-sm" href="/exports/<?= $xid ?>" id="export-download-<?= $xid ?>"><i class="feather-download me-1"></i>Download</a><?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div></div>
    <?php endif; ?>
    <p class="fs-11 text-muted mt-3" id="exports-footnote">A holders export is logged with its row count. Natural persons' names are in it only when the settings allow it for you; otherwise a person is “Individual holder”.</p>
</div>
