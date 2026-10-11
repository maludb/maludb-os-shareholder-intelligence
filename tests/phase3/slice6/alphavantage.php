<?php
/**
 * Proof: Alpha Vantage, the default price source (db/025; the owner 2026-10-11 — a key added later). Seeded paused for want of a key; the short side
 * points an admin at the credential door; the door (credential_set, built ahead of slice 10) seals the key, shows only its label and last four, logs
 * no secret, refuses an agent, and its arrival resumes the source; the pull reads the free plan's compact series (full refused as premium), counts
 * the day's requests and defers the rest past the cap or the host's own limit (ok, no back-off, no admin notice), skips a ticker Alpha Vantage does
 * not know, and answers blocked on a refused key; a rotation; JSON.
 */
require __DIR__ . '/lib.php';
$sm = seed_short();
$sec = $sm['security'];
$src = q("SELECT * FROM sources WHERE connector = 'prices_alphavantage'")[0];
$sid = (int) $src['id'];
$set = static fn (): array => json_decode((string) one('SELECT settings FROM sources WHERE id = :i', ['i' => $sid]), true);
$avReqs = static fn (): array => array_values(array_filter(stub_requests(), static fn ($r) => $r['path'] === '/query'));

echo "1. The default, waiting for its key\n";
$s0 = $set();
ok($src['paused_at'] !== null && ($s0['paused_for_key'] ?? false) === true && ($s0['default'] ?? false) === true && (int) $s0['daily_requests'] === 25
    && str_contains((string) $src['paused_reason'], 'Needs an Alpha Vantage key') && $src['credential_id'] === null, 'seeded the default, paused for want of a key: 25 requests a day (one each five seconds — run.sh sets the stub\'s speed)');
[$jA, ] = sign_on(1);
[$jP, ] = sign_on(26);
page($jA, '/?issuer=' . $sm['issuer']);
page($jP, '/?issuer=' . $sm['issuer']);
$h = page($jA, '/short?security=' . $sec)['body'];
ok(str_contains($h, 'Prices come from Alpha Vantage once an admin sets its key') && str_contains($h, 'href="/admin/sources/' . $sid . '/credential"') && str_contains($h, 'id="short-chart-key-btn"'), 'the short side, with no price: an admin is shown "Set the Alpha Vantage key"');
ok(!str_contains(page($jP, '/short?security=' . $sec)['body'], 'id="short-chart-key-btn"'), 'an Analyst is told where prices come from, without the button');

echo "2. The credential door\n";
ok(page($jP, '/admin/sources/' . $sid . '/credential')['code'] === 403 && post_json($jP, '/admin/sources/credential.php', ['source' => $sid, 'label' => 'x', 'secret' => 'SMOKE-AV-KEY'])['code'] === 403, 'an Analyst: 403 on the screen and the action (credentials.manage)');
$p = page($jA, '/admin/sources/' . $sid . '/credential');
ok($p['code'] === 200 && str_contains($p['body'], 'id="credential-form"') && str_contains($p['body'], 'None set.') && str_contains($p['body'], 'type="password" name="secret"'), 'the admin\'s screen: none set, the secret a password field');
$j = json_decode(page($jA, '/admin/sources/' . $sid . '/credential', ['headers' => ['Accept: application/json']])['body'], true)['data'] ?? [];
ok(array_key_exists('credential', $j['source'] ?? []) && $j['source']['credential'] === null && ($j['source']['paused'] ?? false) === true && ($j['kinds'] ?? []) === ['api_key', 'bearer', 'basic', 'oauth_client'], 'JSON: no credential, paused, the four kinds');
$r = post_json($jA, '/admin/sources/credential.php', ['source' => $sid, 'label' => '', 'secret' => 'ab']);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['label'], $r['json']['error']['fields']['secret']), 'no label and a two-character secret: 422 on both fields');
kernel_state(function ($s) { $s['facts']['961'] = ['valid' => true, 'member_id' => 42, 'run_id' => 961, 'request_id' => 'req-961', 'is_eval' => false]; return $s; });
$r = req('POST', '/admin/sources/credential.php', ['headers' => as_agent(run_token(42, 961)), 'form' => ['source' => $sid, 'label' => 'agent', 'secret' => 'SMOKE-AV-KEY']]);
ok($r['code'] === 403, 'an agent: 403 — a credential is a person\'s');
$since = last_activity_id();
$r = post_json($jA, '/admin/sources/credential.php', ['source' => $sid, 'kind' => 'api_key', 'label' => 'Alpha Vantage free key', 'secret' => 'SMOKE-AV-KEY']);
$row = q('SELECT * FROM sources WHERE id = :i', ['i' => $sid])[0];
$cred = q('SELECT id, kind, label, last4, encode(ciphertext, \'hex\') AS hex FROM source_credentials WHERE source_id = :i', ['i' => $sid]);
ok($r['code'] === 200 && ($r['json']['resumed'] ?? false) === true && (int) ($r['json']['record_id'] ?? 0) === $sid && count($cred) === 1 && (int) $row['credential_id'] === (int) $cred[0]['id'], 'set: the source\'s one credential');
ok($row['paused_at'] === null && $row['paused_reason'] === null && !array_key_exists('paused_for_key', $set()), 'the key\'s arrival resumed the source');
ok($cred[0]['last4'] === '-KEY' && !str_contains($cred[0]['hex'], bin2hex('SMOKE-AV-KEY')) && (int) one("SELECT count(*) FROM source_credentials WHERE position(convert_to('SMOKE-AV-KEY', 'UTF8') IN ciphertext) > 0") === 0, 'stored as ciphertext (the key\'s bytes nowhere in it), the last four kept');
$a = activity('credential.set', $since)[0] ?? [];
ok(json_decode($a['after'] ?? '{}', true) == ['source_id' => $sid, 'kind' => 'api_key', 'label' => 'Alpha Vantage free key', 'last4' => '-KEY'] && count(activity('source.resume', $since)) === 1, 'credential.set logged with the source, kind, label and last four; source.resume after it');
$p = page($jA, '/admin/sources/' . $sid . '/credential?notice=resumed')['body'];
ok(str_contains($p, 'Alpha Vantage free key') && str_contains($p, '<code>-KEY</code>') && !str_contains($p, 'SMOKE-AV-KEY') && str_contains($p, 'the source resumed'), 'the screen: the label and the last four, never the key');

echo "3. The pull: the free plan's series, the day's requests counted\n";
stub_reset();
source_due('prices_alphavantage');
pull6('prices_alphavantage');
$p = last_pull('prices_alphavantage');
ok($p['status'] === 'ok' && (int) one('SELECT count(*) FROM prices WHERE security_id = :s', ['s' => $sec]) === 60 && count($avReqs()) === 2, 'a security never loaded: full refused as premium, compact read — sixty days, two requests');
ok(($set()['used'] ?? []) == ['on' => '2024-04-04', 'n' => 2], 'the day\'s requests counted: two on 2024-04-04');
ok((float) one("SELECT close FROM prices WHERE security_id = :s AND trade_date = '2024-01-11'", ['s' => $sec]) > 0 && (string) one('SELECT s.connector FROM prices p JOIN sources s ON s.id = p.source_id WHERE p.security_id = :s LIMIT 1', ['s' => $sec]) === 'prices_alphavantage', 'the bars under the Alpha Vantage source');
stub_reset();
source_due('prices_alphavantage');
pull6('prices_alphavantage');
ok(count($avReqs()) === 1 && str_contains($avReqs()[0]['path'] . '', '/query') && ($set()['used']['n'] ?? 0) === 3, 'loaded once: compact only, one request (three today)');
q("UPDATE sources SET settings = settings || '{\"daily_requests\": 3}' WHERE id = :i", ['i' => $sid]);
stub_reset();
source_due('prices_alphavantage');
pull6('prices_alphavantage');
$p = last_pull('prices_alphavantage');
ok($p['status'] === 'ok' && $avReqs() === [] && str_contains((string) ($set()['deferred']['why'] ?? ''), 'requests are used') && ($set()['deferred']['on'] ?? '') === '2024-04-04', 'the cap reached (3 here): no request, the pull ok, the rest deferred to the next market day (settings.deferred says so)');
stub_reset();
source_due('prices_alphavantage');
pull6('prices_alphavantage', '2024-04-05T19:00:00-04:00');
ok(count($avReqs()) === 1 && ($set()['used'] ?? []) == ['on' => '2024-04-05', 'n' => 1] && array_key_exists('deferred', $set()) && $set()['deferred'] === null, 'the next market day the count starts again; nothing deferred');
q("UPDATE sources SET settings = settings || '{\"daily_requests\": 25}' WHERE id = :i", ['i' => $sid]);

echo "4. The host's own limit, an unknown ticker, a refused key\n";
stub_reset(['variant' => ['av_limit']]);
source_due('prices_alphavantage');
$before = (int) one("SELECT count(*) FROM notifications WHERE kind IN ('source_blocked', 'source_failed')");
pull6('prices_alphavantage', '2024-04-08T19:00:00-04:00');
$p = last_pull('prices_alphavantage');
$row = q('SELECT consecutive_failures, backoff_until FROM sources WHERE id = :i', ['i' => $sid])[0];
ok($p['status'] === 'ok' && (int) $row['consecutive_failures'] === 0 && $row['backoff_until'] === null && ($set()['used']['n'] ?? 0) === 25 && (int) one("SELECT count(*) FROM notifications WHERE kind IN ('source_blocked', 'source_failed')") === $before,
    'Alpha Vantage says the day\'s 25 are spent: the pull ok and deferred — no back-off, nobody told');
ok(str_contains((string) ($set()['deferred']['why'] ?? ''), '25 requests per day') && !str_contains(json_encode($set()), 'SMOKE-AV-KEY'), 'the host\'s words are kept — with the key it names taken out');
q("INSERT INTO securities (issuer_id, class, name, cusip, ticker, is_primary) VALUES (:i, 'preferred', 'SMOKE Preferred', '07831X305', 'ZZZZ', false)", ['i' => $sm['issuer']]);
stub_reset();
source_due('prices_alphavantage');
pull6('prices_alphavantage', '2024-04-09T19:00:00-04:00');
$p = last_pull('prices_alphavantage');
ok($p['status'] === 'ok' && ($set()['unknown_tickers'] ?? []) === ['ZZZZ'] && count($avReqs()) === 3, 'a ticker Alpha Vantage does not know (ZZZZ: full, then compact — both "Invalid API call") is skipped and named; SMOK is still read');
$since = last_activity_id();
$r = post_json($jA, '/admin/sources/credential.php', ['source' => $sid, 'kind' => 'api_key', 'label' => 'Alpha Vantage free key', 'secret' => 'WRONG-AV-KEY']);
ok($r['code'] === 200 && ($r['json']['resumed'] ?? true) === false && one('SELECT rotated_at FROM source_credentials WHERE source_id = :i', ['i' => $sid]) !== null && count(activity('credential.rotate', $since)) === 1
    && (int) one('SELECT count(*) FROM source_credentials WHERE source_id = :i', ['i' => $sid]) === 1, 'rotated: the one credential replaced, rotated_at stamped, credential.rotate logged');
stub_reset();
source_due('prices_alphavantage');
pull6('prices_alphavantage', '2024-04-10T19:00:00-04:00');
$p = last_pull('prices_alphavantage');
ok($p['status'] === 'blocked' && str_contains((string) $p['error'], 'Alpha Vantage refused the key') && str_contains((string) $p['error'], 'apikey is invalid'), 'a key Alpha Vantage refuses: blocked, in its words');
post_json($jA, '/admin/sources/credential.php', ['source' => $sid, 'kind' => 'api_key', 'label' => 'Alpha Vantage free key', 'secret' => 'SMOKE-AV-KEY']);
stub_reset();
source_due('prices_alphavantage');
pull6('prices_alphavantage', '2024-04-11T19:00:00-04:00');
ok(last_pull('prices_alphavantage')['status'] === 'ok', 'rotated back: ok');
$leak = (int) one("SELECT count(*) FROM source_pulls WHERE policy::text LIKE '%-AV-KEY%' OR error LIKE '%-AV-KEY%' OR query::text LIKE '%-AV-KEY%'") + (int) one("SELECT count(*) FROM sources WHERE settings::text LIKE '%-AV-KEY%'") + (int) one("SELECT count(*) FROM activity_log WHERE after::text LIKE '%-AV-KEY%' OR before::text LIKE '%-AV-KEY%'");
ok($leak === 0 && !str_contains((string) @file_get_contents(need('SI_DEV_STATE') . '/app.log'), '-AV-KEY'), 'neither key in a pull, the trail or the server log');
finish();
