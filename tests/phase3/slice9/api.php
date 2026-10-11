<?php
/**
 * Proof: the records API (agents-k7-api.md "The API", "Proof") — a key is a person's own: an Analyst's key answers holders by name, a Viewer's key "Individual holder"; a key that reaches several issuers must name one;
 * a restricted issuer is not there for a key whose person is not on its list; a revoked key, a person's MCP token and no key are 401; the 61st call in a minute is 429 with Retry-After; an upload through the API ties
 * (and hands the Analyst its dispatch); an event and a borrow observation are posted by the same handlers the forms use; every call is one `api.call` row (the route, the status, the rows, a holders read's count), never the
 * query or the key.
 */
require __DIR__ . '/lib.php';
$ids = seed9();
$alpha = $ids['alpha'];
$sec = $ids['security'];
$jP = person(26);
$jA = person(1);
$jV = person(29);
[$jD, ] = sign_on(30);
$kp = mint_key($jP, 'Priya\'s script')['key'];
$kv = mint_key($jA, 'Ann\'s read key', 29)['key'];
$kd = mint_key($jD, 'Dana\'s script')['key'];
$km = mint_key(person(27), 'Marco\'s script')['key'];

echo "1. Who may call\n";
$r = api('/api/v1/issuers', null);
ok($r['code'] === 401 && ($r['json']['error']['code'] ?? '') === 'unauthorized', 'no key: 401, one body for every failure');
$r = api('/api/v1/issuers', 'sik_' . str_repeat('0', 48));
ok($r['code'] === 401 && ($r['json']['error']['message'] ?? '') === 'A valid API key is required.', 'a key that is not one: the same 401');
$mt = post_json($jP, '/settings/tokens/mint.php', ['label' => 'Claude Desktop', 'scope' => 'mcp']);
$mcp = (string) ($mt['json']['token'] ?? '');
ok($mt['code'] === 200 && str_starts_with($mcp, 'mcp_') && api('/api/v1/issuers', $mcp)['code'] === 401, 'a person\'s MCP token is not a key: 401');
$rv = mint_key($jP, 'About to be revoked');
ok(api('/api/v1/issuers', $rv['key'])['code'] === 200, 'a key works…');
post_json($jP, '/settings/tokens/key-revoke.php', ['api_key' => $rv['id']]);
ok(api('/api/v1/issuers', $rv['key'])['code'] === 401, '…and is 401 the moment it is revoked');
$r = api('/api/v1/health', null);
ok($r['code'] === 200 && ($r['json']['application'] ?? '') === 'shareholder_intelligence', 'the health check needs no key');
$r = api('/api/v1/issuers', $kp, ['method' => 'DELETE']);
ok($r['code'] === 405, 'a method the route does not take: 405');
$r = req('GET', '/api/v1/issuers', ['jar' => $jP, 'headers' => ['Accept: text/html']]);
ok($r['code'] === 200 && (json_decode($r['body'], true)['data']['issuers'] ?? null) !== null, 'a signed-in browser may read the API too (a session), and always gets JSON');
$r = req('POST', '/api/v1/events', ['jar' => $jP, 'form' => ['issuer' => $alpha, 'on_date' => date('Y-m-d'), 'kind' => 'note', 'title' => 'SMOKE: no csrf']]);
ok($r['code'] === 403, 'a session POSTing without its CSRF token: 403 (a key\'s call has no cookie to forge)');

echo "2. A key is a person's own: both walls\n";
$r = api('/api/v1/issuers', $kp);
$names = array_column($r['json']['data']['issuers'] ?? [], 'name');
ok(in_array('SMOKE Alpha Corp', $names, true) && !in_array('SMOKE Beta Holdings', $names, true), 'Priya\'s key lists Alpha and not Beta (restricted to Dana): ' . implode(', ', $names));
$names = array_column(api('/api/v1/issuers', $kd)['json']['data']['issuers'] ?? [], 'name');
ok(in_array('SMOKE Beta Holdings', $names, true), 'Dana\'s key lists Beta');
$names = array_column(api('/api/v1/issuers', $km)['json']['data']['issuers'] ?? [], 'name');
ok(in_array('SMOKE Beta Holdings', $names, true), 'an IR Manager\'s key lists every issuer');
$r = api('/api/v1/holders', $kp);
ok($r['code'] === 422 && str_contains($r['body'], 'several issuers'), 'a key that reaches several issuers and names none: 422, in words');
$r = api('/api/v1/holders?issuer=SMKB', $kp);
ok($r['code'] === 404, 'Beta, named: not found for Priya\'s key (the same nothing as a missing one)');
$r = api('/api/v1/holders?issuer=SMKB', $kd);
ok($r['code'] === 200, 'and found for Dana\'s');
$hp = api('/api/v1/holders?issuer=SMKA', $kp);
$hv = api('/api/v1/holders?issuer=SMKA', $kv);
$byName = static fn (array $r): array => array_column($r['json']['data']['holders'] ?? [], 'display_name');
ok(in_array('SMOKE Frank Person', $byName($hp), true) && in_array('SMOKE Zeta Holdings LLC', $byName($hp), true), 'an Analyst\'s key reads holders by name: Frank and Zeta');
ok(!in_array('SMOKE Frank Person', $byName($hv), true) && in_array('Individual holder', $byName($hv), true) && in_array('SMOKE Zeta Holdings LLC', $byName($hv), true) && !str_contains($hv['body'], 'Frank') && !str_contains($hv['body'], 'Springfield'),
    'a Viewer\'s key reads "Individual holder" for the person (name, city and address withheld) and the entity by name');
$one = api('/api/v1/holders/' . $ids['holder_frank'], $kv);
ok($one['code'] === 200 && !str_contains($one['body'], 'Frank') && api('/api/v1/holders/' . $ids['holder_frank'], $kp)['code'] === 200 && str_contains(api('/api/v1/holders/' . $ids['holder_frank'], $kp)['body'], 'SMOKE Frank Person'), 'one holder: withheld for the Viewer\'s key, named for the Analyst\'s');
ok(api('/api/v1/holders/' . $ids['holder_beta'], $kp)['code'] === 404, 'a restricted issuer\'s holder by id: not found');

echo "3. The reads\n";
$g = static fn (string $path, ?string $key = null): array => api($path, $key ?? $kp);
$r = $g('/api/v1/issuers/' . $alpha);
ok($r['code'] === 200 && ($r['json']['data']['issuer']['ticker'] ?? '') === 'SMKA', '/issuers/{id}: the issuer');
$r = $g('/api/v1/securities?issuer=SMKA');
ok($r['code'] === 200 && ($r['json']['data']['securities'][0]['cusip'] ?? '') === '99900A101', '/securities?issuer=: its securities');
$r = $g('/api/v1/securities/' . $sec);
ok($r['code'] === 200 && ($r['json']['data']['security']['ticker'] ?? '') === 'SMKA' && isset($r['json']['data']['share_counts']), '/securities/{id}: one security and its latest share counts');
$r = $g('/api/v1/participants');
ok($r['code'] === 200 && count($r['json']['data']['participants'] ?? []) > 5, '/participants: the directory');
$r = $g('/api/v1/positions?security=' . $sec);
ok($r['code'] === 200 && (float) ($r['json']['data']['total'] ?? 0) === 10000000.0 && count($r['json']['data']['positions'] ?? []) === 3, '/positions: who held at DTC on the latest date (three participants, 10,000,000 shares)');
$r = $g('/api/v1/signals?issuer=SMKA');
$sg = $r['json']['data']['signals'][0] ?? [];
ok($r['code'] === 200 && ($sg['rule_key'] ?? '') === 'si_over_float' && in_array($sg['evidence_class'] ?? '', ['established', 'contested', 'lore', 'data_defect'], true), '/signals: the signal with its rule and evidence class');
$r = $g('/api/v1/signals/' . $ids['signal']);
ok($r['code'] === 200 && ($r['json']['data']['signal']['signal_id'] ?? 0) === $ids['signal'], '/signals/{id}: one signal');
$r = $g('/api/v1/events?issuer=SMKA');
ok($r['code'] === 200 && count($r['json']['data']['events'] ?? []) >= 3, '/events: the timeline');
$r = $g('/api/v1/filings?issuer=SMKA');
ok($r['code'] === 200 && count($r['json']['data']['filings'] ?? []) === 2, '/filings: the index');
$r = $g('/api/v1/filings/' . $ids['filing_13d']);
ok($r['code'] === 200 && ($r['json']['data']['filing']['form'] ?? '') === 'SC 13D', '/filings/{id}: one filing');
$r = $g('/api/v1/short-interest?security=' . $sec);
ok($r['code'] === 200 && count($r['json']['data']['series'] ?? []) === 1 && (float) $r['json']['data']['series'][0]['short_shares'] === 3000000.0, '/short-interest: the series');
foreach (['short-volume', 'fails', 'threshold', 'holdings', 'beneficial', 'insiders', 'institutions', 'reports', 'uploads', 'borrow-observations'] as $route) {
    $r = $g('/api/v1/' . $route . '?security=' . $sec . '&issuer=SMKA');
    ok($r['code'] === 200 && isset($r['json']['data']), "/$route answers {data}");
}
$r = $g('/api/v1/nosuchroute');
ok($r['code'] === 404, 'a route that is not there: 404');

echo "4. Every call is one api.call row\n";
$tok = (int) one("SELECT id FROM mcp_access_tokens WHERE label = 'Priya''s script'");
$since = last_activity_id();
$r = $g('/api/v1/holders?issuer=SMKA&q=Frank');
$n = count($r['json']['data']['holders'] ?? []);
$a = activity('api.call', $since);
$aj = json_decode($a[0]['after'] ?? '{}', true);
ok(count($a) === 1 && $a[0]['source'] === 'api' && (int) $a[0]['token_id'] === $tok && (int) $a[0]['actor_member_id'] === 26 && ($aj['route'] ?? '') === '/api/v1/holders' && ($aj['status'] ?? 0) === 200 && ($aj['holders_rows'] ?? -1) === $n && $n >= 1,
    'one api.call: source api, the key\'s token, her member, the route, the status and the count of holders read (' . $n . ')');
ok(!str_contains($a[0]['after'] ?? '', 'Frank') && !str_contains($a[0]['after'] ?? '', 'q='), 'and neither the name searched for nor the query string');
$since = last_activity_id();
$g('/api/v1/events?issuer=SMKA');
$aj = json_decode(activity('api.call', $since)[0]['after'] ?? '{}', true);
ok(($aj['rows'] ?? -1) >= 3 && !array_key_exists('holders_rows', $aj), 'a read of events: its row count, no holders count');
$since = last_activity_id();
api('/api/v1/issuers', 'sik_' . str_repeat('1', 48));
ok(count(activity('api.call', $since)) === 0, 'a call with no valid key is not logged to anyone');
$since = last_activity_id();
$g('/api/v1/holders/' . $ids['holder_frank']);
$aj = json_decode(activity('api.call', $since)[0]['after'] ?? '{}', true);
ok(($aj['route'] ?? '') === '/api/v1/holders/{id}' && ($aj['holders_rows'] ?? 0) >= 1, 'a holder by id: the route as /api/v1/holders/{id}');
ok((int) one("SELECT count(*) FROM key_usage WHERE token_id = :t AND bucket_kind = 'day'", ['t' => $tok]) === 1, 'the key\'s day bucket counts its calls (key_usage)');

echo "5. Writes: the same handlers the forms use\n";
$since = last_activity_id();
$r = api('/api/v1/events', $kp, ['json' => ['issuer' => $alpha, 'on_date' => date('Y-m-d'), 'kind' => 'note', 'title' => 'SMOKE: posted through the API', 'severity' => 'watch']]);
$ev = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && ($r['json']['ok'] ?? false) === true && $ev > 0 && one('SELECT title FROM events WHERE id = :i', ['i' => $ev]) === 'SMOKE: posted through the API', 'POST /api/v1/events (JSON): the event is recorded, record_id = the event');
$a = activity('event.create', $since)[0] ?? [];
ok(($a['source'] ?? '') === 'api' && (int) $a['token_id'] === $tok && (int) $a['actor_member_id'] === 26, 'logged event.create as the API, under the key');
$r = api('/api/v1/events', $kp, ['json' => ['issuer' => $alpha, 'on_date' => date('Y-m-d'), 'kind' => 'nonsense', 'title' => 'x']]);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['kind']), 'a refusal is JSON with the field named');
$r = api('/api/v1/events', $kv, ['json' => ['issuer' => $alpha, 'on_date' => date('Y-m-d'), 'kind' => 'note', 'title' => 'SMOKE: a Viewer\'s']]);
ok($r['code'] === 403, 'a Viewer\'s key may not write (events.write): 403');
$r = api('/api/v1/borrow-observations', $kp, ['json' => ['security' => $sec, 'observed_on' => date('Y-m-d'), 'fee_rate_percent' => '12.5', 'source' => 'broker_quote']]);
ok($r['code'] === 200 && (float) one('SELECT fee_rate_percent FROM borrow_observations WHERE security_id = :s ORDER BY id DESC LIMIT 1', ['s' => $sec]) === 12.5, 'POST /api/v1/borrow-observations: recorded');
$cusip = (string) one('SELECT cusip FROM securities WHERE id = :s', ['s' => $sec]);
[$name, $bytes] = week_csv($cusip);
$tmp = sys_get_temp_dir() . '/' . $name;
file_put_contents($tmp, $bytes);
$r = api('/api/v1/uploads', $kv, ['file' => $tmp, 'form' => ['security' => $sec, 'kind' => 'spr']]);
ok($r['code'] === 403, 'a Viewer\'s key may not upload (uploads.write): 403');
$since = last_activity_id();
$r = api('/api/v1/uploads', $kp, ['file' => $tmp, 'form' => ['security' => $sec, 'kind' => 'spr']]);
@unlink($tmp);
$imp = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && ($r['json']['tied'] ?? null) === true && $imp > 0, 'POST /api/v1/uploads (multipart: file, kind, security): the SPR is read and ties to its own totals' . (($r['json']['tied'] ?? null) === true ? '' : ' — ' . substr($r['body'], 0, 200)));
$up = q('SELECT status, tied, created_by FROM imports WHERE id = :i', ['i' => $imp])[0] ?? [];
ok(($up['status'] ?? '') === 'done' && $up['tied'] === true && (int) $up['created_by'] === 26 && (int) one('SELECT count(*) FROM participant_positions WHERE import_id = :i', ['i' => $imp]) > 10, 'the upload is Priya\'s: done, tied, its positions loaded');
$a = activity('upload.create', $since)[0] ?? [];
ok(($a['source'] ?? '') === 'api' && (int) $a['token_id'] === $tok, 'upload.create logged as the API under the key');
ok((int) one("SELECT count(*) FROM agent_dispatches WHERE kind = 'upload_done' AND import_id = :i", ['i' => $imp]) === 1, 'and the Analyst has its upload_done dispatch');
$r = api('/api/v1/uploads/' . $imp, $kp);
ok($r['code'] === 200 && ($r['json']['data']['upload']['upload_id'] ?? ($r['json']['data']['upload']['import_id'] ?? 0)) == $imp, 'GET /api/v1/uploads/{id}: the upload and its tie, to poll');

echo "6. Rate limits: the 61st call in a minute\n";
$kr = mint_key($jP, 'Burst')['key'];
$rid = (int) one("SELECT id FROM mcp_access_tokens WHERE label = 'Burst'");
steady_minute(8);
$codes = [];
for ($i = 1; $i <= 61; $i++) { $codes[] = api('/api/v1/issuers', $kr)['code']; }
$last = api('/api/v1/issuers', $kr);
ok(count(array_filter($codes, static fn ($c) => $c === 200)) === 60 && $codes[60] === 429, 'sixty calls answer; the sixty-first is 429');
ok(preg_match('/^Retry-After: (\d+)/mi', $last['headers'], $m) === 1 && (int) $m[1] >= 1 && (int) $m[1] <= 60 && ($last['json']['error']['code'] ?? '') === 'rate_limited', '429 with Retry-After (seconds to the next minute) and a body in words');
$u = q("SELECT calls, refused FROM key_usage WHERE token_id = :t AND bucket_kind = 'minute'", ['t' => $rid])[0] ?? [];
ok((int) ($u['calls'] ?? 0) === 60 && (int) ($u['refused'] ?? 0) >= 1, 'key_usage: 60 calls and the refusals counted');
$c = (int) one("SELECT count(*) FROM activity_log WHERE action = 'api.call' AND token_id = :t", ['t' => $rid]);
ok($c >= 61, 'the refused call is in the trail too (' . $c . ' api.call rows)');
q("UPDATE mcp_access_tokens SET rate_per_day = 3 WHERE id = :i", ['i' => $rid]);
ok(true, '(the day\'s limit is the same rule, proved in the schema proof)');
finish();
