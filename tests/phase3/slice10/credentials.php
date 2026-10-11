<?php
/**
 * Proof: the credential door through the sources screens (admin-settings.md "Built ahead", re-proved with the slice's own screens): a key sealed as ciphertext (the database never holds its bytes —
 * a scan of the whole dump), shown as its label and last four on the list and the view, opened only by the fetcher that needs it and checked by the stub (a bearer header), rotated (one credential, rotated_at,
 * a wrong key refused by the host in the host's words, the secret in no pull, no log, no error, no page), the pause made for want of a key lifted by its arrival, an agent and the other roles refused.
 */
require __DIR__ . '/lib.php';
$sm = seed_short();
$jA = who(1);
$jM = who(27);
$pg = source_id('prices_polygon');
$av = source_id('prices_alphavantage');
$dtcc = source_id('dtcc_participants');
$secret = 'SMOKE-PG-KEY';
$secrets = ['SMOKE-PG-KEY', 'WRONG-PG-KEY'];
$leaks = static function () use ($secrets): int {
    $n = 0;
    foreach ($secrets as $s) {
        $n += (int) one("SELECT count(*) FROM source_pulls WHERE policy::text LIKE :l OR error LIKE :l OR query LIKE :l", ['l' => '%' . $s . '%']);
        $n += (int) one("SELECT count(*) FROM sources WHERE settings::text LIKE :l OR coalesce(paused_reason, '') LIKE :l", ['l' => '%' . $s . '%']);
        $n += (int) one("SELECT count(*) FROM activity_log WHERE after::text LIKE :l OR before::text LIKE :l OR route LIKE :l", ['l' => '%' . $s . '%']);
        $n += (int) one("SELECT count(*) FROM notifications WHERE coalesce(body, '') LIKE :l OR title LIKE :l", ['l' => '%' . $s . '%']);
        $n += (int) one("SELECT count(*) FROM source_credentials WHERE position(convert_to(:s, 'UTF8') IN ciphertext) > 0 OR label LIKE :l", ['s' => $s, 'l' => '%' . $s . '%']);
    }
    return $n;
};

echo "1. Waiting for a key\n";
$row = q('SELECT * FROM sources WHERE id = :i', ['i' => $pg])[0];
ok($row['paused_at'] !== null && json_decode($row['settings'], true)['paused_for_key'] === true && $row['credential_id'] === null, 'Polygon is seeded paused for want of a key');
$p = page($jA, '/admin/sources/' . $pg)['body'];
ok(str_contains($p, 'None set.') && str_contains($p, 'id="source-credential-btn"') && str_contains($p, 'Set the credential'), 'its view: no credential, the button to set one');
$p = page($jA, '/admin/sources/' . $pg . '/credential')['body'];
ok(str_contains($p, 'id="credential-form"') && str_contains($p, 'type="password" name="secret"') && str_contains($p, 'autocomplete="new-password"'), 'the credential form: the secret a password field');

echo "2. Setting the key\n";
$since = last_activity_id();
$r = post_json($jA, '/admin/sources/credential.php', ['source' => $pg, 'kind' => 'bearer', 'label' => 'SMOKE Polygon key', 'secret' => $secret]);
$row = q('SELECT * FROM sources WHERE id = :i', ['i' => $pg])[0];
$cred = q("SELECT id, kind, label, last4, rotated_at, encode(ciphertext, 'hex') AS hex FROM source_credentials WHERE source_id = :i", ['i' => $pg]);
ok($r['code'] === 200 && ($r['json']['resumed'] ?? false) === true && count($cred) === 1 && (int) $row['credential_id'] === (int) $cred[0]['id'] && $row['paused_at'] === null && $cred[0]['kind'] === 'bearer', 'set: one credential, the source resumed by its arrival');
ok($cred[0]['last4'] === '-KEY' && !str_contains($cred[0]['hex'], bin2hex($secret)) && $leaks() === 0, 'sealed: the last four kept, the key\'s bytes nowhere in the ciphertext, the pulls, the sources, the log or the notices');
$dump = (string) shell_exec('sudo -n -u postgres pg_dump -d ' . escapeshellarg(need('SI_DEV_DB')) . ' 2>/dev/null');
ok(strlen($dump) > 100000 && substr_count($dump, $secret) === 0 && substr_count($dump, bin2hex($secret)) === 0, 'a dump of the whole database (' . round(strlen($dump) / 1000000, 1) . ' MB) holds the key neither as text nor as hex');
$a = activity('credential.set', $since)[0] ?? [];
ok(json_decode($a['after'] ?? '{}', true) == ['source_id' => $pg, 'kind' => 'bearer', 'label' => 'SMOKE Polygon key', 'last4' => '-KEY'] && count(activity('source.resume', $since)) === 1, 'credential.set logged with the source, kind, label and last four; source.resume after it');
$p = page($jA, '/admin/sources/' . $pg)['body'];
ok(str_contains($p, 'SMOKE Polygon key') && str_contains($p, '<code>-KEY</code>') && !str_contains($p, $secret) && str_contains($p, 'Rotate the credential'), 'the view: the label and the last four, never the key; the button now rotates');
$p = page($jA, '/admin/sources?connector=prices_polygon')['body'];
ok(str_contains($p, 'key set') && !str_contains($p, $secret), 'the list says a key is set');
$j = get_json($jA, '/admin/sources/' . $pg)['data']['source'];
ok(($j['credential']['last4'] ?? '') === '-KEY' && ($j['credential']['kind'] ?? '') === 'bearer' && !str_contains(json_encode($j), $secret) && !str_contains(json_encode($j), 'ciphertext'), 'JSON: the credential is its kind, label and last four');

echo "3. The fetcher opens it\n";
stub_reset();
source_due('prices_polygon');
fetch_only('prices_polygon', AT_S6);
$pull = last_pull('prices_polygon');
ok($pull['status'] === 'ok' && (int) one('SELECT count(*) FROM prices WHERE security_id = :s', ['s' => $sm['security']]) === 60, 'Polygon is pulled with the sealed key: the stub (which checks the bearer header) answers the sixty days');
ok(array_filter(stub_requests(), static fn ($r) => str_starts_with($r['path'], '/v2/aggs/') && $r['status'] === 200) !== [], 'the stub saw an authorised request');

echo "4. A wrong key, a rotation\n";
$since = last_activity_id();
$r = post_json($jA, '/admin/sources/credential.php', ['source' => $pg, 'kind' => 'bearer', 'label' => 'SMOKE Polygon key', 'secret' => 'WRONG-PG-KEY']);
$cred = q('SELECT id, rotated_at, last4 FROM source_credentials WHERE source_id = :i', ['i' => $pg]);
ok($r['code'] === 200 && ($r['json']['resumed'] ?? true) === false && count($cred) === 1 && $cred[0]['rotated_at'] !== null && $cred[0]['last4'] === '-KEY' && count(activity('credential.rotate', $since)) === 1, 'rotated: the one credential replaced, rotated_at stamped, credential.rotate logged');
q('UPDATE sources SET backoff_until = NULL, consecutive_failures = 0 WHERE id = :i', ['i' => $pg]);
stub_reset();
source_due('prices_polygon');
fetch_only('prices_polygon', '2024-04-05T23:30:00-04:00');
$pull = last_pull('prices_polygon');
ok(in_array($pull['status'], ['blocked', 'failed'], true) && $pull['error'] !== null && !str_contains((string) $pull['error'], 'WRONG-PG-KEY') && $leaks() === 0, 'the host refuses the wrong key (' . $pull['status'] . '): its words in the pull, the key nowhere');
$p = page($jA, '/admin/sources/' . $pg)['body'];
ok(!str_contains($p, 'WRONG-PG-KEY') && preg_match('~<tr id="pull-row-' . $pull['id'] . '" data-status="' . $pull['status'] . '"~', $p) === 1, 'the view shows the refused pull and no key');
post_json($jA, '/admin/sources/credential.php', ['source' => $pg, 'kind' => 'bearer', 'label' => 'SMOKE Polygon key', 'secret' => $secret]);
q('UPDATE sources SET backoff_until = NULL, consecutive_failures = 0 WHERE id = :i', ['i' => $pg]);
stub_reset();
source_due('prices_polygon');
fetch_only('prices_polygon', '2024-04-05T23:30:00-04:00');
ok(last_pull('prices_polygon')['status'] === 'ok' && (int) one('SELECT count(*) FROM source_credentials WHERE source_id = :i', ['i' => $pg]) === 1, 'rotated back: ok, still one credential');
ok($leaks() === 0 && !str_contains((string) @file_get_contents(need('SI_DEV_STATE') . '/app.log'), 'PG-KEY'), 'neither key is in a pull, the trail, a notice or the server log');

echo "5. What it does not do\n";
$since = last_activity_id();
$r = post_json($jA, '/admin/sources/credential.php', ['source' => $dtcc, 'kind' => 'api_key', 'label' => 'SMOKE unused key', 'secret' => 'SMOKE-UNUSED-1234']);
ok($r['code'] === 200 && ($r['json']['resumed'] ?? true) === false && activity('source.resume', $since) === [], 'a key for a source that was not waiting for one resumes nothing');
$bad = [];
foreach ([['kind' => 'password', 'label' => 'x', 'secret' => 'abcdefgh'], ['kind' => 'api_key', 'label' => '', 'secret' => 'abcdefgh'], ['kind' => 'api_key', 'label' => 'x', 'secret' => 'abc'],
          ['kind' => 'api_key', 'label' => 'x', 'secret' => 'abc defgh'], ['kind' => 'api_key', 'label' => str_repeat('a', 81), 'secret' => 'abcdefgh'], ['kind' => 'api_key', 'label' => 'x', 'secret' => str_repeat('k', 2001)]] as $form) {
    $r = post_json($jA, '/admin/sources/credential.php', ['source' => $av] + $form);
    if ($r['code'] !== 422) { $bad[] = json_encode(array_map(static fn ($v) => substr((string) $v, 0, 12), $form)); }
}
ok($bad === [] && (int) one('SELECT count(*) FROM source_credentials WHERE source_id = :i', ['i' => $av]) === 0, 'a kind off the list, no label, a short, a spaced and a long secret, a long label: 422, nothing stored' . ($bad ? ' — ' . implode(' ', $bad) : ''));
ok(post_json($jA, '/admin/sources/credential.php', ['source' => 999999, 'label' => 'x', 'secret' => 'abcdefgh'])['code'] === 404, 'a source that is not there: 404');
kernel_state(function ($s) { $s['facts']['971'] = ['valid' => true, 'member_id' => 42, 'run_id' => 971, 'request_id' => 'req-971', 'is_eval' => false]; return $s; });
$r = req('POST', '/admin/sources/credential.php', ['headers' => as_agent(run_token(42, 971)), 'form' => ['source' => $av, 'label' => 'agent', 'secret' => 'SMOKE-AGENT-KEY']]);
ok($r['code'] === 403 && (int) one('SELECT count(*) FROM source_credentials WHERE source_id = :i', ['i' => $av]) === 0, 'an agent: 403 — a credential is a person\'s');
ok(page($jM, '/admin/sources/' . $pg . '/credential')['code'] === 403 && post_json($jM, '/admin/sources/credential.php', ['source' => $av, 'label' => 'x', 'secret' => 'abcdefgh'])['code'] === 403, 'an IR Manager: 403 on the screen and the action (credentials.manage)');
finish();
