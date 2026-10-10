<?php /** Upload a file (screen `upload-add`): the kind, the security (the current issuer's), the file; the connector is detected. Data: issuer, securities, security, kind, max */ ?>
<?= view('shared/header.php', ['id' => 'upload-form', 'title' => 'Upload a file', 'crumbs' => [['Home', '/'], ['Uploads', '/uploads'], ['Upload', null]], 'back' => ['/uploads', 'Uploads']]) ?>
<div class="main-content" id="upload-form-content">
    <form method="post" action="/uploads/save.php" enctype="multipart/form-data" hx-post="/uploads/save.php" hx-encoding="multipart/form-data" hx-target="#flash" class="card" id="upload-form"
          hx-on::before-request="document.getElementById('upload-form-save-btn').disabled = true" hx-on::after-request="document.getElementById('upload-form-save-btn').disabled = false">
        <?= csrf_field() ?>
        <div class="card-body">
            <div class="mb-3"><label class="form-label d-block">What the file is</label>
                <div class="d-flex flex-column gap-2" id="upload-form-kinds">
                <?php foreach (['' => 'Detect it from the file'] + UPLOAD_KINDS as $k => $l): $on = $k !== 'prices_csv'; ?>
                    <label class="btn btn-light btn-touch text-start<?= $on ? '' : ' disabled opacity-50' ?>" for="upload-form-kind-<?= e($k ?: 'detect') ?>"><input type="radio" class="form-check-input me-2" name="kind" value="<?= e($k) ?>" id="upload-form-kind-<?= e($k ?: 'detect') ?>" <?= $kind === $k ? 'checked' : '' ?> <?= $on ? '' : 'disabled' ?>>
                        <span class="fw-semibold"><?= e($l) ?></span> <span class="fs-11 text-muted d-block d-sm-inline"><?= e(match ($k) { '' => 'the layout names itself', 'spr' => 'DTC\'s weekly or one-time Security Position Report', 'nobo' => 'Broadridge\'s NOBO list (the job file or the names export)',
                            'register' => 'the transfer agent\'s Shareholder Extract or STC\'s position detail', 'share_range' => 'Broadridge\'s Share Range Analysis (.xls)', default => 'from slice 6' }) ?></span></label>
                <?php endforeach; ?></div></div>
            <div class="mb-3"><label class="form-label" for="upload-form-field-security">Security</label>
                <?php if (count($securities) <= 1): ?><input type="hidden" name="security" value="<?= (int) ($security['security_id'] ?? 0) ?>"><div class="form-control-plaintext fw-semibold" id="upload-form-field-security"><?= e(($security['name'] ?? '—') . ' · ' . ($security['cusip'] ?? 'no CUSIP')) ?></div>
                <?php else: ?><select class="form-select btn-touch" name="security" id="upload-form-field-security"><?php foreach ($securities as $s): ?><option value="<?= (int) $s['security_id'] ?>" <?= (int) $s['security_id'] === (int) ($security['security_id'] ?? 0) ? 'selected' : '' ?>><?= e($s['name'] . ' · ' . ($s['cusip'] ?? 'no CUSIP')) ?></option><?php endforeach; ?></select><?php endif; ?>
                <div class="form-text">The file's CUSIP must be this security's.</div></div>
            <div class="mb-3"><label class="form-label" for="upload-form-field-record_date">Record date <span class="text-muted fw-normal">(when the file does not say it)</span></label>
                <input type="date" class="form-control btn-touch" name="record_date" id="upload-form-field-record_date">
                <div class="form-text">The agent's extract and Broadridge's names export carry none; the file's name is read when it holds one (06-30-24).</div></div>
            <div class="mb-0"><label class="form-label" for="upload-form-field-file">The file</label>
                <input type="file" class="form-control btn-touch" name="file" id="upload-form-field-file" accept=".csv,.xlsx,.xls,text/csv" required>
                <div class="form-text">Up to <?= e((string) round($max / 1048576)) ?> MB. The file is kept (a register without its tax ids, birth dates, phones and emails); what it says of itself — its totals and counts — is checked against its rows.</div></div>
        </div>
        <div class="card-footer d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="upload-form-save-btn"><i class="feather-upload-cloud me-1"></i>Upload</button><?= hx_link('/uploads', 'Cancel', 'btn btn-light btn-touch', 'id="upload-form-cancel-btn"') ?></div>
    </form>
</div>
