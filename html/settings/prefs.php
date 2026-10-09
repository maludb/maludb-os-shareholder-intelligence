<?php
declare(strict_types=1);
/**
 * Action `prefs_save` (log `settings.prefs`): a person's own notification choices — morning_note (yes/no), morning_hour (0–23, blank = the
 * application's setting), signals_min_severity (info, watch or high), email_enabled and text_enabled (yes/no), kinds[] (the events to be told
 * about), text_kinds[] (the events worth a text), issuer_ids[] (the issuers to be told about; empty = every issuer I may see).
 * A field left out stays as it was. Both channels off is allowed: that person is told only in the app. Always about oneself.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/queries.php';
require_post();
require_login();
verify_csrf();
$pdo = db();
$me = (int) current_member_id();
$has = static fn (string $k): bool => array_key_exists($k, $_POST);
$errors = [];
$yes = static function (string $k) use (&$errors): bool {
    $v = strtolower(trim((string) $_POST[$k]));
    if (in_array($v, ['1', 'true', 'on', 'yes'], true)) {
        return true;
    }
    if (!in_array($v, ['0', 'false', 'off', 'no', ''], true)) {
        $errors[] = 'Say yes or no for ' . str_replace('_', ' ', $k) . '.';
    }
    return false;
};
$list = static function (string $k) use (&$errors): ?array {
    if (!array_key_exists($k, $_POST)) {
        return null;
    }
    $v = $_POST[$k];
    $v = is_array($v) ? $v : explode(',', (string) $v);
    $v = array_values(array_unique(array_filter(array_map(static fn ($x): string => trim((string) $x), $v), static fn (string $x): bool => $x !== '')));
    $bad = array_diff($v, array_keys(NOTICE_KINDS));
    if ($bad !== []) {
        $errors[] = 'Unknown event: ' . implode(', ', array_map(static fn (string $x): string => mb_substr($x, 0, 30), $bad)) . '.';
    }
    return $v;
};
$f = [];
foreach (['email_enabled', 'text_enabled', 'morning_note'] as $k) {
    if ($has($k)) {
        $f[$k] = $yes($k);
    }
}
if ($has('morning_hour')) {
    $h = trim((string) $_POST['morning_hour']);
    if ($h === '') {
        $f['morning_hour'] = null;
    } elseif (ctype_digit($h) && (int) $h <= 23) {
        $f['morning_hour'] = (int) $h;
    } else {
        $errors[] = 'The morning note\'s hour is 0 to 23, or blank for the application\'s setting.';
    }
}
if ($has('signals_min_severity')) {
    $sev = strtolower(trim((string) $_POST['signals_min_severity']));
    if (in_array($sev, ['info', 'watch', 'high'], true)) {
        $f['signals_min_severity'] = $sev;
    } else {
        $errors[] = 'The least severity worth a notice is info, watch or high.';
    }
}
if (($kinds = $list('kinds')) !== null) {
    $f['kinds'] = $kinds;
}
if (($textKinds = $list('text_kinds')) !== null) {
    $f['text_kinds'] = $textKinds;
}
if ($has('issuer_ids')) {
    $raw = $_POST['issuer_ids'];
    $raw = is_array($raw) ? $raw : explode(',', (string) $raw);
    $raw = array_values(array_filter(array_map(static fn ($x): string => trim((string) $x), $raw), static fn (string $x): bool => $x !== ''));
    $ids = [];
    foreach ($raw as $x) {
        if (!ctype_digit($x)) {
            $errors[] = 'Issuers are named by their ids.';
            break;
        }
        $ids[] = (int) $x;
    }
    $ids = array_values(array_unique($ids));
    if ($ids !== [] && (int) one_value($pdo, 'SELECT count(*) FROM mcp_issuers WHERE issuer_id = ANY (CAST(:ids AS bigint[]))', ['ids' => pg_array_literal($ids)]) !== count($ids)) {
        $errors[] = 'Choose only issuers you may see.';
    }
    $f['issuer_ids'] = $ids;
}
if ($errors !== []) {
    refuse(422, implode(' ', $errors));
}
$pdo->beginTransaction();
$r = save_prefs($pdo, $me, $f);
log_activity($pdo, 'settings.prefs', 'notification_prefs', $me, ['before' => $r['before'], 'after' => $r['after']]);
$pdo->commit();
emit_action_status(true, ['did' => 'Saved how you are told', 'record_id' => $me, 'refresh' => 'prefsChanged', 'email_enabled' => $r['prefs']['email_enabled'],
    'text_enabled' => $r['prefs']['text_enabled'], 'morning_note' => $r['prefs']['morning_note']]);
$return = safe_local_path($_POST['return_to'] ?? null) ?? '/settings/';
saved_go($return . (str_contains($return, '?') ? '&' : '?') . 'notice=prefs_saved', 'prefsChanged');
