<?php /** A source's credential (screen `admin-source-credential`): the label and last four when set, never the secret; the form. Data: s, notice, back */
$c = $s['credential'];
$sid = (int) $s['source_id'];
$isAv = $s['connector'] === 'prices_alphavantage';
?>
<?= view('shared/header.php', ['id' => 'admin-source-credential', 'title' => 'Credential', 'crumbs' => [['Home', '/'], ['Sources and settings', '/admin/sources'], [$s['name'], null]], 'back' => $back]) ?>
<div class="main-content" id="admin-source-credential-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3"><div class="col-lg-7">
    <div class="card mb-3" id="credential-source"><div class="card-body fs-12"><dl class="row mb-0">
        <dt class="col-5 col-md-4 text-muted fw-normal">Source</dt><dd class="col-7 col-md-8"><?= e($s['name']) ?> <code><?= e($s['connector']) ?></code></dd>
        <dt class="col-5 col-md-4 text-muted fw-normal">State</dt><dd class="col-7 col-md-8" id="credential-source-state"><?= $s['paused_at'] !== null ? '<span class="badge text-bg-warning">paused</span> ' . e((string) $s['paused_reason']) : '<span class="badge text-bg-success">active</span>' ?></dd>
        <dt class="col-5 col-md-4 text-muted fw-normal">Credential</dt><dd class="col-7 col-md-8" id="credential-current"><?= $c === null ? 'None set.' : e($c['label']) . ' · ' . e(CREDENTIAL_KINDS[$c['kind']] ?? $c['kind']) . ' ending <code>' . e($c['last4']) . '</code> · ' . ($c['rotated_at'] !== null ? 'rotated ' . e(format_date(substr((string) $c['rotated_at'], 0, 10))) : 'set ' . e(format_date(substr((string) $c['created_at'], 0, 10)))) ?></dd>
    </dl></div></div>
    <form method="post" action="/admin/sources/credential.php" hx-post="/admin/sources/credential.php" hx-target="#flash" class="card" id="credential-form" autocomplete="off"><div class="card-header"><h5 class="card-title mb-0"><?= $c === null ? 'Set the credential' : 'Rotate the credential' ?></h5></div><div class="card-body">
        <?= csrf_field() ?><input type="hidden" name="source" value="<?= $sid ?>">
        <label class="form-label fs-12" for="credential-form-kind">Kind</label>
        <select name="kind" id="credential-form-kind" class="form-select mb-2"><?php foreach (CREDENTIAL_KINDS as $k => $w): ?><option value="<?= e($k) ?>"<?= ($c['kind'] ?? 'api_key') === $k ? ' selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select>
        <label class="form-label fs-12" for="credential-form-label">Label</label>
        <input type="text" name="label" id="credential-form-label" class="form-control mb-2" maxlength="80" required value="<?= e((string) ($c['label'] ?? ($isAv ? 'Alpha Vantage key' : ''))) ?>">
        <label class="form-label fs-12" for="credential-form-secret">The secret</label>
        <input type="password" name="secret" id="credential-form-secret" class="form-control mb-1" autocomplete="new-password" required minlength="4" maxlength="2000">
        <div class="form-text">Stored encrypted under this installation's key; shown back only as its last four characters; never in a log.</div>
    </div><div class="card-footer"><button type="submit" class="btn btn-primary btn-touch w-100" id="credential-form-save-btn"><?= $c === null ? 'Set the credential' : 'Rotate the credential' ?></button></div></form>
    </div>
    <?php if ($isAv): ?><div class="col-lg-5"><div class="card" id="credential-help"><div class="card-body fs-12">
        <div class="fw-semibold mb-1">Alpha Vantage</div>
        <p class="mb-1">The default price source: one daily series a security (TIME_SERIES_DAILY, unadjusted), read on market days from 18:00 New York time.</p>
        <p class="mb-1">A free key (alphavantage.co → Get your free API key) gives the last 100 trading days and 25 requests a day; the fetcher counts them and leaves the rest for the next market day. A premium key reads a security's full history the first time.</p>
        <p class="mb-0 text-muted">Older prices can be uploaded as a CSV (Uploads → a price file) at any time.</p>
    </div></div></div><?php endif; ?>
    </div>
</div>
