<?php
declare(strict_types=1);
/**
 * Action `settings_save` (log `settings.save` with the changed keys; settings.manage; PEOPLE; an agent's would pause — other): business_name, edgar_contact_email, default_exchange, market_timezone, market_holidays[] (dates),
 * edgar_rate_per_second (0.1 to 10), reconciliation_tolerance_shares, auto_match_threshold (0.5 to 1), pii_in_exports (analyst, admin or nobody), escalate_high_to (a person; blank for none), signal_grace_days, tombstone_days,
 * retain_raw_rows_days (30 or more; blank for forever), key_rate_per_minute, key_rate_per_day, max_upload_bytes (or max_upload_mb), price_source_default, timezone. A field left out stays. record_id = 1, the settings.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/write.php';
si_handler_begin();
require_human();
require_right('settings.manage');
$pdo = db();
$errors = [];
$f = [];
$text = static function (string $name, int $max, string $label) use (&$f, &$errors): void {
    if (!req_has($name)) { return; }
    $v = (string) req_val($name);
    if (mb_strlen($v) > $max || preg_match('~[\r\n]~', $v)) { $errors[$name] = $label . ' is one line of up to ' . $max . ' characters.'; } else { $f[$name] = $v === '' ? null : $v; }
};
$text('business_name', 120, 'The business name');
if (req_has('edgar_contact_email')) {
    $v = (string) req_val('edgar_contact_email');
    if ($v !== '' && (mb_strlen($v) > 200 || filter_var($v, FILTER_VALIDATE_EMAIL) === false)) { $errors['edgar_contact_email'] = 'The contact is an email address.'; } else { $f['edgar_contact_email'] = $v === '' ? null : $v; }
}
foreach (['default_exchange' => EXCHANGES, 'pii_in_exports' => PII_IN_EXPORTS, 'price_source_default' => PRICE_SOURCES] as $name => $allowed) {
    if (!req_has($name)) { continue; }
    $v = (string) req_val($name);
    if (!isset($allowed[$v])) { $errors[$name] = 'Choose one of: ' . implode(', ', array_keys($allowed)) . '.'; } else { $f[$name] = $v; }
}
foreach (['market_timezone', 'timezone'] as $name) {
    if (!req_has($name)) { continue; }
    $v = (string) req_val($name);
    if (!in_array($v, DateTimeZone::listIdentifiers(), true)) { $errors[$name] = 'That is not a time zone name (such as America/New_York or UTC).'; } else { $f[$name] = $v; }
}
if (req_has('market_holidays')) {
    $raw = $_POST['market_holidays'];
    $items = is_array($raw) ? array_map('strval', $raw) : (preg_split('~[\s,;]+~', (string) $raw, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    $days = [];
    foreach ($items as $d) {
        $d = trim($d);
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
        if ($d === '') { continue; }
        if ($dt === false || $dt->format('Y-m-d') !== $d) { $errors['market_holidays'] = '"' . mb_substr($d, 0, 20) . '" is not a date (YYYY-MM-DD).'; break; }
        $days[$d] = true;
    }
    if (count($days) > 400) { $errors['market_holidays'] = 'At most 400 holidays.'; }
    if (!isset($errors['market_holidays'])) { $f['market_holidays'] = array_keys($days); }
}
if (req_has('edgar_rate_per_second')) {
    $v = (string) req_val('edgar_rate_per_second');
    if (!is_numeric($v) || (float) $v < 0.1 || (float) $v > 10) { $errors['edgar_rate_per_second'] = 'The EDGAR rate is 0.1 to 10 requests a second.'; } else { $f['edgar_rate_per_second'] = round((float) $v, 2); }
}
if (req_has('auto_match_threshold')) {
    $v = (string) req_val('auto_match_threshold');
    if (!is_numeric($v) || (float) $v < 0.5 || (float) $v > 1) { $errors['auto_match_threshold'] = 'The auto-match threshold is 0.5 to 1.'; } else { $f['auto_match_threshold'] = round((float) $v, 2); }
}
$ints = ['reconciliation_tolerance_shares' => [0, 1000000000000, 'The tolerance'], 'signal_grace_days' => [0, 365, 'The signal grace'], 'tombstone_days' => [1, 3650, 'The tombstone window'],
         'key_rate_per_minute' => [1, 100000, 'A key\'s calls a minute'], 'key_rate_per_day' => [1, 10000000, 'A key\'s calls a day']];
foreach ($ints as $name => [$lo, $hi, $label]) {
    $v = si_int($name, null, $lo, $hi, $label, $errors);
    if (req_has($name) && !isset($errors[$name])) { $f[$name] = $v; }
}
if (req_has('retain_raw_rows_days')) {
    $v = si_int('retain_raw_rows_days', null, 30, 36500, 'The retention', $errors, true);
    if (!isset($errors['retain_raw_rows_days'])) { $f['retain_raw_rows_days'] = $v; }
}
if (req_has('max_upload_bytes') || req_has('max_upload_mb')) {
    $bytes = req_has('max_upload_bytes') ? (string) req_val('max_upload_bytes') : '';
    $mb = req_has('max_upload_mb') ? (string) req_val('max_upload_mb') : '';
    $n = $bytes !== '' ? $bytes : ($mb !== '' && ctype_digit($mb) ? (string) ((int) $mb * 1048576) : $mb);
    if (!ctype_digit($n) || (int) $n < 1048576 || (int) $n > 1073741824) { $errors[$bytes !== '' ? 'max_upload_bytes' : 'max_upload_mb'] = 'The largest upload is 1 MB to 1,024 MB.'; } else { $f['max_upload_bytes'] = (int) $n; }
}
if (req_has('escalate_high_to')) {
    $v = (string) req_val('escalate_high_to');
    if ($v === '' || $v === '0') { $f['escalate_high_to'] = null; }
    else {
        $m = ctype_digit($v) ? one_value($pdo, "SELECT 1 FROM members WHERE id = :id AND member_kind = 'human' AND status = 'active' AND capability IS NOT NULL", ['id' => (int) $v]) : false;
        if (!$m) { $errors['escalate_high_to'] = 'Choose a person admitted here.'; } else { $f['escalate_high_to'] = (int) $v; }
    }
}
if ($errors !== []) {
    si_refuse_fields($errors);
}
$r = si_guard($pdo, static function () use ($pdo, $f): array {
    $pdo->beginTransaction();
    $r = settings_save($pdo, $f);
    $pdo->commit();
    return $r;
});
si_done($r['after'] === [] ? 'Nothing changed in the settings' : 'Saved the settings (' . implode(', ', array_map(static fn (string $k): string => str_replace('_', ' ', $k), array_keys($r['after']))) . ')', 1,
    si_land(return_path('/admin/settings'), 'saved'), 'settingsChanged', ['changed' => array_keys($r['after'])] + ($r['sources_lowered'] > 0 ? ['sources_lowered' => $r['sources_lowered']] : []));
