<?php
/** Proof: JSON mode on every slice 2 screen ({data}) and handler ({ok, did, record_id, location}) under a person's action token; an upload refused to an agent's run token. */
require __DIR__ . '/lib.php';
[$jP, ] = sign_on(26);
[$jM, ] = sign_on(27);
$ref = reference_issuer();
$sec = $ref['security'];
$json = ['headers' => ['Accept: application/json']];
$files = real_spr_files();
$u1 = (int) (upload($jP, glob(REAL . '*SPR_06-21-2024_09175A107*.csv')[0], ['security' => $sec])['json']['record_id'] ?? 0);
page($jP, '/?issuer=' . $ref['issuer']);

echo "1. Every screen answers JSON\n";
$bad = [];
foreach (['/uploads', '/uploads/new', '/uploads/' . $u1, '/uploads/' . $u1 . '/status', '/positions', '/positions/matrix', '/positions/flow', '/positions/concentration', '/reconciliation'] as $u) {
    $r = page($jP, $u, $json);
    $b = json_decode($r['body'], true);
    if ($r['code'] !== 200 || !isset($b['data']) || isset($b['error'])) { $bad[] = "$u {$r['code']}"; }
}
ok($bad === [], 'all nine slice 2 screens (and the status fragment) answer {data}' . ($bad ? ': ' . implode(', ', $bad) : ''));
ok(page($jP, '/uploads/99999', $json)['code'] === 404, 'a missing upload: 404 as JSON');

echo "2. Every handler under a person's action token\n";
$h = ['Accept: application/json', 'X-Action-Token: ' . person_token(27)];
$f = glob(REAL . '*SPR_06-28-2024_09175A107*.csv')[0];
$ch = curl_init(BASE . '/uploads/save.php');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_POSTFIELDS => ['kind' => 'spr', 'security' => $sec, 'file' => new CURLFile($f, 'text/csv', basename($f))]]);
$b = json_decode((string) curl_exec($ch), true) ?: [];
$u2 = (int) ($b['record_id'] ?? 0);
ok(curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200 && ($b['ok'] ?? false) === true && $u2 > 0 && isset($b['location']) && $b['tied'] === true, 'upload_create: {ok, did, record_id, location, tied}');
$calls = [['/uploads/reparse.php', ['upload' => $u2]], ['/uploads/delete.php', ['upload' => $u2]], ['/uploads/restore.php', ['upload' => $u2]], ['/reconciliation/run.php', ['security' => $sec]]];
$bad = [];
foreach ($calls as [$u, $form]) {
    $r = req('POST', $u, ['headers' => $h, 'form' => $form]);
    $b = json_decode($r['body'], true);
    if ($r['code'] !== 200 || ($b['ok'] ?? false) !== true || !isset($b['did'], $b['location']) || !array_key_exists('record_id', $b)) { $bad[] = "$u {$r['code']} " . substr($r['body'], 0, 100); }
}
ok($bad === [], 'reparse, delete, restore and reconciliation_run answer {ok, did, record_id, location}' . ($bad ? ': ' . implode(' | ', $bad) : ''));
$r = req('POST', '/uploads/override.php', ['headers' => $h, 'form' => ['upload' => $u2, 'note' => 'x']]);
ok($r['code'] === 422 && (json_decode($r['body'], true)['error']['code'] ?? '') === 'invalid', 'a refusal (overriding a tied upload): 422 as JSON');

echo "3. An agent's run token\n";
kernel_state(function ($s) { $s['facts']['701'] = ['valid' => true, 'member_id' => 42, 'run_id' => 701, 'request_id' => 'req-701', 'is_eval' => false]; return $s; });
$rt = as_agent(run_token(42, 701));
ok(req('POST', '/uploads/save.php', ['headers' => $rt, 'form' => ['kind' => 'spr', 'security' => $sec]])['code'] === 403, 'upload_create refuses an agent (people only): 403');
$r = req('POST', '/reconciliation/run.php', ['headers' => $rt, 'form' => ['security' => $sec]]);
ok($r['code'] === 200 && one("SELECT source FROM activity_log WHERE action LIKE 'reconciliation.%' ORDER BY id DESC LIMIT 1") === 'agent', 'an agent runs the reconciliation, logged as the agent');
finish();
