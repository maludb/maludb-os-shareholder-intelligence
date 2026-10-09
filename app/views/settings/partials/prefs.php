<?php /** How I am told (action prefs_save). Data: prefs, refusal, osChannels, issuers (the issuers I may see), noteHour (the application's) */ ?>
<form method="post" action="/settings/prefs.php" hx-post="/settings/prefs.php" hx-target="#flash" id="prefs">
    <?= csrf_field() ?><input type="hidden" name="return_to" value="/settings/">
    <div class="card mb-3" id="prefs-morning"><div class="card-header"><h5 class="card-title mb-0">The morning note</h5></div><div class="card-body">
        <input type="hidden" name="morning_note" value="no">
        <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="prefs-field-morning"><input type="checkbox" class="form-check-input mt-0" name="morning_note" value="yes" id="prefs-field-morning" <?= $prefs['morning_note'] ? 'checked' : '' ?>><i class="feather-sunrise"></i> Send me the Surveillance Analyst's note on market days</label>
        <div class="row g-2 align-items-center">
            <div class="col-7"><label class="form-label fs-12 text-muted mb-0" for="prefs-field-hour">At (market time)</label></div>
            <div class="col-5"><select name="morning_hour" id="prefs-field-hour" class="form-select btn-touch">
                <option value="">The application's (<?= e(sprintf('%02d:00', $noteHour)) ?>)</option>
                <?php for ($h = 5; $h <= 10; $h++): ?><option value="<?= $h ?>" <?= $prefs['morning_hour'] === $h ? 'selected' : '' ?>><?= e(sprintf('%02d:00', $h)) ?></option><?php endfor; ?>
            </select></div>
        </div>
    </div></div>
    <div class="card mb-3" id="prefs-signals"><div class="card-header"><h5 class="card-title mb-0">Signals worth a notice</h5></div><div class="card-body">
        <div class="d-flex flex-wrap gap-2" role="radiogroup" aria-label="The least severity">
            <?php foreach (['info' => 'Every signal', 'watch' => 'Watch and high', 'high' => 'High only'] as $v => $label): ?>
                <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="prefs-field-sev-<?= e($v) ?>"><input type="radio" class="form-check-input mt-0" name="signals_min_severity" value="<?= e($v) ?>" id="prefs-field-sev-<?= e($v) ?>" <?= $prefs['signals_min_severity'] === $v ? 'checked' : '' ?>><?= e($label) ?></label>
            <?php endforeach; ?>
        </div>
        <?php if (count($issuers) > 1): ?>
            <div class="fs-12 text-muted mt-3 mb-1">Issuers I follow (none ticked = every issuer I may see)</div>
            <input type="hidden" name="issuer_ids[]" value="">
            <div id="prefs-issuers">
            <?php foreach ($issuers as $i): $iid = (int) $i['issuer_id']; ?>
                <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="prefs-field-issuer-<?= $iid ?>"><input type="checkbox" class="form-check-input mt-0" name="issuer_ids[]" value="<?= $iid ?>" id="prefs-field-issuer-<?= $iid ?>" <?= in_array($iid, $prefs['issuer_ids'], true) ? 'checked' : '' ?>><span class="fw-semibold"><?= e($i['ticker'] ?: '—') ?></span> <?= e($i['name']) ?></label>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div></div>
    <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">How you are told</h5></div><div class="card-body">
        <input type="hidden" name="email_enabled" value="no"><input type="hidden" name="text_enabled" value="no">
        <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="prefs-field-email"><input type="checkbox" class="form-check-input mt-0" name="email_enabled" value="yes" id="prefs-field-email" <?= $prefs['email_enabled'] ? 'checked' : '' ?>><i class="feather-mail"></i> Email</label>
        <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="prefs-field-text"><input type="checkbox" class="form-check-input mt-0" name="text_enabled" value="yes" id="prefs-field-text" <?= $prefs['text_enabled'] ? 'checked' : '' ?>><i class="feather-message-square"></i> Text message</label>
        <?php if ($refusal === 'no_verified_phone'): ?>
            <div class="alert alert-warning fs-12 mb-2" id="prefs-text-note">Texts need a phone number verified in the operating system. <a href="<?= e($osChannels) ?>" class="alert-link" id="prefs-text-link">Add or verify your phone</a>. Until then you are emailed.</div>
        <?php elseif ($refusal === 'opted_out'): ?>
            <div class="alert alert-warning fs-12 mb-2" id="prefs-text-note">You turned texts off (or replied STOP). You can turn them back on in the operating system: <a href="<?= e($osChannels) ?>" class="alert-link" id="prefs-text-link">your channels</a>. Until then you are emailed.</div>
        <?php elseif ($refusal === 'no_sender'): ?>
            <div class="alert alert-secondary fs-12 mb-2" id="prefs-text-note">This business has not set up texting yet, so you are emailed.</div>
        <?php else: ?>
            <div class="fs-12 text-muted mb-2" id="prefs-text-hint">Texts go to the phone you verified in the operating system. <a href="<?= e($osChannels) ?>" id="prefs-text-link">Your channels</a></div>
        <?php endif; ?>
    </div></div>
    <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Tell me when</h5></div><div class="card-body" id="prefs-kinds">
        <input type="hidden" name="kinds[]" value="">
        <?php foreach (NOTICE_KINDS as $k => $label): ?>
            <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="prefs-field-kind-<?= e($k) ?>"><input type="checkbox" class="form-check-input mt-0" name="kinds[]" value="<?= e($k) ?>" id="prefs-field-kind-<?= e($k) ?>" <?= in_array($k, $prefs['kinds'], true) ? 'checked' : '' ?>><?= e($label) ?></label>
        <?php endforeach; ?>
    </div></div>
    <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Text me when</h5></div><div class="card-body" id="prefs-text-kinds">
        <div class="fs-12 text-muted mb-2">Only when texts are on. Fewer is better: a text should mean "look now".</div>
        <input type="hidden" name="text_kinds[]" value="">
        <?php foreach (['signal_high', 'reconciliation_untied', 'filing_13d', 'source_blocked', 'pack_drafted'] as $k): ?>
            <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="prefs-field-text-kind-<?= e($k) ?>"><input type="checkbox" class="form-check-input mt-0" name="text_kinds[]" value="<?= e($k) ?>" id="prefs-field-text-kind-<?= e($k) ?>" <?= in_array($k, $prefs['text_kinds'], true) ? 'checked' : '' ?>><?= e(NOTICE_KINDS[$k]) ?></label>
        <?php endforeach; ?>
    </div></div>
    <button type="submit" class="btn btn-primary btn-touch w-100" id="prefs-save-btn">Save</button>
</form>
