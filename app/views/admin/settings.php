<?php /** The application's settings (screen `admin-settings`). Data: s (settings_form), tz, notice */
$num = static fn (mixed $v): string => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
$field = static fn (string $id, string $label, string $input, string $help = ''): string => '<div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="settings-form-' . $id . '">' . e($label) . '</label>' . $input . ($help !== '' ? '<div class="form-text">' . $help . '</div>' : '') . '</div>';
$in = static fn (string $name, string $type, mixed $value, string $extra = ''): string => '<input type="' . $type . '" name="' . $name . '" id="settings-form-' . str_replace('_', '-', $name) . '" class="form-control btn-touch" value="' . e((string) $value) . '" ' . $extra . '>';
$opts = static fn (array $o, mixed $cur): string => implode('', array_map(static fn ($k, $w): string => '<option value="' . e((string) $k) . '"' . ((string) $k === (string) $cur ? ' selected' : '') . '>' . e($w) . '</option>', array_keys($o), $o));
?>
<?= view('shared/header.php', ['id' => 'admin-settings', 'title' => 'Settings', 'crumbs' => [['Home', '/'], ['Sources and settings', has_right('sources.manage') ? '/admin/sources' : null], ['Settings', null]]]) ?>
<div class="main-content" id="admin-settings-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('admin/partials/tabs.php', ['active' => 'settings']) ?>
    <?= view('admin/partials/ua-banner.php', ['ua' => $s['edgar_user_agent']]) ?>
    <form method="post" action="/admin/settings/save.php" hx-post="/admin/settings/save.php" hx-target="#flash" id="settings-form"><?= csrf_field() ?>
        <input type="hidden" name="return_to" value="/admin/settings">
        <div class="card mb-3" id="settings-edgar"><div class="card-header"><h5 class="card-title mb-0">The business and the EDGAR contact</h5></div><div class="card-body"><div class="row g-3">
            <?= $field('business-name', 'Business name', $in('business_name', 'text', $s['business_name'], 'maxlength="120" autocomplete="organization"'), 'The first half of the user-agent the SEC reads.') ?>
            <?= $field('edgar-contact-email', 'Contact email', $in('edgar_contact_email', 'email', $s['edgar_contact_email'], 'maxlength="200" autocomplete="email"'), 'An address the SEC may write to; with the name it makes <code>' . ($s['edgar_user_agent'] !== null ? e($s['edgar_user_agent']) : 'name contact') . '</code>.') ?>
        </div></div></div>
        <div class="card mb-3" id="settings-market"><div class="card-header"><h5 class="card-title mb-0">The exchange, time zones and holidays</h5></div><div class="card-body"><div class="row g-3">
            <?= $field('default-exchange', 'Default exchange', '<select name="default_exchange" id="settings-form-default-exchange" class="form-select btn-touch">' . $opts(EXCHANGES, $s['default_exchange']) . '</select>') ?>
            <?= $field('market-timezone', 'Market time zone', $in('market_timezone', 'text', $s['market_timezone'], 'list="settings-timezones" maxlength="60"'), 'The market-day clock: the fetch calendar, the morning note.') ?>
            <?= $field('timezone', 'Time zone for dates', $in('timezone', 'text', $s['timezone'], 'list="settings-timezones" maxlength="60"')) ?>
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="settings-form-market-holidays">Market holidays (one date a line, YYYY-MM-DD)</label>
                <textarea name="market_holidays" id="settings-form-market-holidays" class="form-control font-monospace fs-12" rows="5" spellcheck="false"><?= e(implode("\n", $s['market_holidays'])) ?></textarea></div>
            <datalist id="settings-timezones"><?php foreach (DateTimeZone::listIdentifiers() as $z): ?><option value="<?= e($z) ?>"><?php endforeach; ?></datalist>
        </div></div></div>
        <div class="card mb-3" id="settings-fetch"><div class="card-header"><h5 class="card-title mb-0">The fetch rates and prices</h5></div><div class="card-body"><div class="row g-3">
            <?= $field('edgar-rate-per-second', 'EDGAR requests a second (at most 10)', $in('edgar_rate_per_second', 'number', $num($s['edgar_rate_per_second']), 'step="0.1" min="0.1" max="10" inputmode="decimal"'), 'The SEC allows 10; 2 is polite. Lowering it lowers any EDGAR source above it.') ?>
            <?= $field('price-source-default', 'The price source', '<select name="price_source_default" id="settings-form-price-source-default" class="form-select btn-touch">' . $opts(PRICE_SOURCES, $s['price_source_default']) . '</select>') ?>
        </div></div></div>
        <div class="card mb-3" id="settings-rules"><div class="card-header"><h5 class="card-title mb-0">Tolerances and thresholds</h5></div><div class="card-body"><div class="row g-3">
            <?= $field('reconciliation-tolerance-shares', 'Reconciliation tolerance (shares)', $in('reconciliation_tolerance_shares', 'number', $s['reconciliation_tolerance_shares'], 'min="0" step="1" inputmode="numeric"'), 'The register and the SPR are untied beyond this many shares.') ?>
            <?= $field('auto-match-threshold', 'Auto-match threshold (0.5 to 1)', $in('auto_match_threshold', 'number', $num($s['auto_match_threshold']), 'step="0.01" min="0.5" max="1" inputmode="decimal"'), 'A holder match at or above this is accepted and logged as automatic.') ?>
            <?= $field('signal-grace-days', 'Signal grace (days)', $in('signal_grace_days', 'number', $s['signal_grace_days'], 'min="0" max="365" step="1" inputmode="numeric"'), 'A signal expires this long after its window ends.') ?>
        </div></div></div>
        <div class="card mb-3" id="settings-people"><div class="card-header"><h5 class="card-title mb-0">Names in exports, and who is told</h5></div><div class="card-body"><div class="row g-3">
            <?= $field('pii-in-exports', 'Who may export names and addresses', '<select name="pii_in_exports" id="settings-form-pii-in-exports" class="form-select btn-touch">' . $opts(PII_IN_EXPORTS, $s['pii_in_exports']) . '</select>') ?>
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" id="settings-form-escalate-label" for="settings-form-escalate-open">The person told of every high signal</label>
                <?= picker_field(['id' => 'settings-form-escalate', 'name' => 'escalate_high_to', 'source' => 'member', 'value' => $s['escalate_high_to'], 'labelledby' => 'settings-form-escalate-label', 'title' => 'People and agents', 'placeholder' => 'No one']) ?></div>
        </div></div></div>
        <div class="card mb-3" id="settings-keys"><div class="card-header"><h5 class="card-title mb-0">Keys and uploads</h5></div><div class="card-body"><div class="row g-3">
            <?= $field('key-rate-per-minute', 'A key\'s calls a minute', $in('key_rate_per_minute', 'number', $s['key_rate_per_minute'], 'min="1" max="100000" step="1" inputmode="numeric"'), 'The default for a new key.') ?>
            <?= $field('key-rate-per-day', 'A key\'s calls a day', $in('key_rate_per_day', 'number', $s['key_rate_per_day'], 'min="1" max="10000000" step="1" inputmode="numeric"')) ?>
            <?= $field('max-upload-mb', 'Largest upload (MB)', $in('max_upload_mb', 'number', (int) round((int) $s['max_upload_bytes'] / 1048576), 'min="1" max="1024" step="1" inputmode="numeric"'), 'The web server\'s own limit (64 MB) applies as well.') ?>
            <?= $field('tombstone-days', 'A deleted record waits (days)', $in('tombstone_days', 'number', $s['tombstone_days'], 'min="1" max="3650" step="1" inputmode="numeric"'), 'Then the worker purges it for good.') ?>
            <?= $field('retain-raw-rows-days', 'Keep an upload\'s raw rows (days; blank = forever)', $in('retain_raw_rows_days', 'number', $s['retain_raw_rows_days'] ?? '', 'min="30" max="36500" step="1" inputmode="numeric"')) ?>
        </div></div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100" id="settings-form-save-btn"><i class="feather-save me-1"></i>Save the settings</button>
    </form>
</div>
