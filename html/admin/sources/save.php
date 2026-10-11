<?php
declare(strict_types=1);
/**
 * Action `source_save` (log `source.save` with what changed; sources.manage; an agent's pauses — other): **source**, name, base_url, settings (an object — replaces the settings), rate_per_second (0.1 to 10),
 * user_agent, active (yes or no). A field left out stays. record_id = the source. The settings hold no secret (a key is the credential's).
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/write.php';
si_handler_begin();
require_right('sources.manage');
$pdo = db();
$src = find_source_base($pdo, (int) (request_integer('source') ?? 0)) ?? refuse(404, 'Source not found.');
$errors = [];
$f = [];
if (req_has('name')) {
    $n = (string) req_val('name');
    if ($n === '' || mb_strlen($n) > 160) { $errors['name'] = 'Name the source in up to 160 characters.'; } else { $f['name'] = $n; }
}
if (req_has('base_url')) {
    $u = (string) req_val('base_url');
    if ($u === '') { $f['base_url'] = null; } elseif (($p = base_url_problem($u)) !== null) { $errors['base_url'] = $p; } else { $f['base_url'] = $u; }
}
if (req_has('rate_per_second')) {
    $r = (string) req_val('rate_per_second');
    if (!is_numeric($r) || (float) $r < 0.1 || (float) $r > 10) { $errors['rate_per_second'] = 'The rate is 0.1 to 10 requests a second.'; } else { $f['rate_per_second'] = round((float) $r, 2); }
}
if (req_has('user_agent')) {
    $ua = (string) req_val('user_agent');
    if (mb_strlen($ua) > 200 || preg_match('~[\r\n]~', $ua)) { $errors['user_agent'] = 'The user-agent is one line of up to 200 characters.'; } else { $f['user_agent'] = $ua === '' ? null : $ua; }
}
if (req_has('active')) { $f['active'] = si_yes('active'); }
[$settings, $jsonError] = request_json_object('settings');
if ($jsonError !== null) {
    $errors['settings'] = $jsonError;
} elseif ($settings !== null) {
    if (($p = source_settings_problem($settings)) !== null) { $errors['settings'] = $p; } else { $f['settings'] = $settings; }
}
if ($errors !== []) {
    si_refuse_fields($errors);
}
$r = si_guard($pdo, static function () use ($pdo, $src, $f): array {
    $pdo->beginTransaction();
    $r = source_save($pdo, (int) $src['id'], $f);
    $pdo->commit();
    return $r;
});
si_done($r['after'] === [] ? 'Nothing changed in ' . $src['name'] : 'Saved ' . $src['name'] . ' (' . implode(', ', array_map(static fn (string $k): string => str_replace('_', ' ', $k), array_keys($r['after']))) . ')', (int) $src['id'],
    si_land(return_path('/admin/sources/' . (int) $src['id']), 'saved'), 'sourceChanged', ['changed' => array_keys($r['after'])]);
