<?php
/**
 * Proof: keys (agents-k7-api.md "Proof") — minted by a person on the Tokens screen (the raw key shown once, only its hash kept, the log carrying the label), by an administrator for another person, never through the
 * kernel's relay (a model must not hold one) and never by an agent; rotated (a new key for the same person, the old one working for the overlap and then not); revoked at once, by its owner or by tokens.manage.
 * A key is a person's own: the API answers as them (api.php proves the walls).
 */
require __DIR__ . '/lib.php';
$ids = seed9();
$jP = person(26);
$jA = person(1);
$jM = person(27);
$jV = person(29);
$health = static fn (string $key): int => api('/api/v1/issuers', $key)['code'];

echo "1. Minting\n";
$since = last_activity_id();
$k1 = mint_key($jP, 'The transfer agent\'s nightly drop');
ok($k1['code'] === 200 && preg_match('/^sik_[0-9a-f]{48}$/', $k1['key']) === 1 && $k1['id'] > 0 && ($k1['json']['ok'] ?? false) === true, 'an Analyst mints a key through the handler: sik_ + 48 hex, shown in the answer, record_id = the key');
$row = q('SELECT member_id, label, scope, token_hash, rate_per_minute, rate_per_day, revoked_at FROM mcp_access_tokens WHERE id = :i', ['i' => $k1['id']])[0];
ok($row['scope'] === 'api' && (int) $row['member_id'] === 26 && $row['token_hash'] === hash('sha256', $k1['key']) && $row['rate_per_minute'] == 60 && $row['rate_per_day'] == 5000, 'stored: scope api, her member, the sha256 of the key (not the key), the settings\' limits 60 a minute and 5,000 a day');
$all = (string) one("SELECT string_agg(coalesce(after::text, '') || coalesce(before::text, ''), ' ') FROM activity_log");
$dump = json_encode(q('SELECT * FROM mcp_access_tokens'));
ok(!str_contains($all, $k1['key']) && !str_contains($dump, $k1['key']), 'the raw key is nowhere in the trail or in the token table');
$a = activity('key.mint', $since)[0] ?? [];
$aj = json_decode($a['after'] ?? '{}', true);
$good = ($a['source'] ?? '') === 'web' && $aj == ['key_id' => $k1['id'], 'label' => 'The transfer agent\'s nightly drop', 'member_id' => 26];
ok($good, 'key.mint logged with the label, the key\'s id and its person — not the key' . ($good ? '' : ' — got ' . json_encode([$a['source'] ?? null, $a['after'] ?? null, $k1['id']])));
$tok = page($jP, '/settings/tokens/', ['headers' => ['Accept: application/json']]);
$tj = json_decode($tok['body'], true)['data'] ?? [];
ok(count($tj['keys'] ?? []) === 1 && $tj['keys'][0]['label'] === 'The transfer agent\'s nightly drop' && !str_contains($tok['body'], $k1['key']) && !str_contains($tok['body'], 'token_hash') && ($tj['may_mint_key'] ?? false) === true, 'the Tokens screen\'s JSON lists the key — never its value or its hash');

echo "2. Shown once, on the screen\n";
$r = post_form($jP, '/settings/tokens/key-mint.php', ['label' => 'A script']);
ok($r['code'] === 302 && str_ends_with($r['location'], '/settings/tokens/'), 'the form mints and redirects to the Tokens screen');
$p1 = page($jP, '/settings/tokens/');
preg_match('~id="tokens-minted-value">(sik_[0-9a-f]{48})<~', $p1['body'], $m);
ok(($m[1] ?? '') !== '' && str_contains($p1['body'], 'Your new API key "A script"'), 'the screen shows the new key once, with its label');
$p2 = page($jP, '/settings/tokens/');
ok(!str_contains($p2['body'], $m[1] ?? 'x') && !str_contains($p2['body'], 'tokens-minted-value') && str_contains($p2['body'], 'id="key-row-'), 'and the next visit does not: only the list');
ok(api('/api/v1/issuers', $m[1] ?? '')['code'] === 200, 'the key works');

echo "3. Who may mint\n";
$r = post_json($jV, '/settings/tokens/key-mint.php', ['label' => 'A viewer\'s']);
ok($r['code'] === 403, 'a Viewer holds no right to a key of her own (tokens.own): 403');
$r = post_json($jP, '/settings/tokens/key-mint.php', ['label' => 'For Ann', 'member' => 29]);
ok($r['code'] === 403, 'an Analyst may not mint for another person (tokens.manage): 403');
$kv = mint_key($jA, 'Ann\'s read key', 29);
ok($kv['code'] === 200 && (int) one('SELECT member_id FROM mcp_access_tokens WHERE id = :i', ['i' => $kv['id']]) === 29, 'an administrator mints one for Ann, a Viewer — acting as Ann');
$r = post_json($jA, '/settings/tokens/key-mint.php', ['label' => 'For the Expert', 'member' => 40]);
ok($r['code'] === 403 && (int) one("SELECT count(*) FROM mcp_access_tokens WHERE label = 'For the Expert'") === 0, 'never for an agent: 403, nothing minted');
$r = post_json($jA, '/settings/tokens/key-mint.php', ['label' => 'For Priya', 'member' => 26]);
ok($r['code'] === 403 && str_contains($r['body'], 'for a Viewer only') && (int) one("SELECT count(*) FROM mcp_access_tokens WHERE label = 'For Priya'") === 0,
    'never for an Analyst or a manager — the admin would hold a key that writes in their name: 403, nothing minted');
$r = req('POST', '/settings/tokens/key-mint.php', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(26)], 'form' => ['label' => 'Through the relay']]);
ok($r['code'] === 422 && str_contains($r['body'], 'shown once, to you, on the Tokens screen') && (int) one("SELECT count(*) FROM mcp_access_tokens WHERE label = 'Through the relay'") === 0, 'through the kernel\'s relay (an action token): refused, nothing minted — a model must not hold a key');
$agent = agent_headers(42, 953);
$r = agent_post($agent, '/settings/tokens/key-mint.php', ['label' => 'An agent\'s']);
ok($r['code'] === 403, 'an agent: 403 (a person\'s)');
$r = post_json($jP, '/settings/tokens/key-mint.php', ['label' => '']);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['label']), 'no label: refused, the field named');
for ($i = 0; $i < 8; $i++) { mint_key($jM, 'Marco key ' . $i); }
$r9 = post_json($jM, '/settings/tokens/key-mint.php', ['label' => 'the ninth']);
$r10 = post_json($jM, '/settings/tokens/key-mint.php', ['label' => 'the tenth']);
$r11 = post_json($jM, '/settings/tokens/key-mint.php', ['label' => 'the eleventh']);
ok($r9['code'] === 200 && $r10['code'] === 200 && $r11['code'] === 422 && str_contains($r11['body'], 'At most 10 live keys'), 'a person keeps ten live keys at most: the eleventh is refused in words');

echo "4. Rotating: the old key keeps its overlap, then stops\n";
$since = last_activity_id();
$r = post_json($jP, '/settings/tokens/key-rotate.php', ['api_key' => $k1['id']]);
$new = (string) ($r['json']['key'] ?? '');
$nid = rid($r);
$n = q('SELECT member_id, label, rate_per_minute, rate_per_day, rotated_from FROM mcp_access_tokens WHERE id = :i', ['i' => $nid])[0] ?? [];
ok($r['code'] === 200 && preg_match('/^sik_[0-9a-f]{48}$/', $new) === 1 && $nid !== $k1['id'] && (int) $n['rotated_from'] === $k1['id'] && (int) $n['member_id'] === 26 && $n['label'] === 'The transfer agent\'s nightly drop' && $n['rate_per_minute'] == 60,
    'rotated: a new key for the same person, label and limits, pointing at the old one');
$old = q('SELECT expires_at, revoked_at FROM mcp_access_tokens WHERE id = :i', ['i' => $k1['id']])[0];
$hours = (strtotime($old['expires_at']) - time()) / 3600;
ok($old['revoked_at'] === null && $hours > 23.9 && $hours <= 24.01, 'the old key expires in 24 hours (the settings\' overlap), not revoked');
ok($health($k1['key']) === 200 && $health($new) === 200, 'both keys work inside the overlap');
q("UPDATE mcp_access_tokens SET expires_at = now() - interval '1 minute' WHERE id = :i", ['i' => $k1['id']]);
ok($health($k1['key']) === 401 && $health($new) === 200, 'the overlap over: the old key is 401, the new one works');
$a = activity('key.rotate', $since)[0] ?? [];
$aj = json_decode($a['after'] ?? '{}', true);
ok(($aj['rotated_from'] ?? 0) === $k1['id'] && ($aj['key_id'] ?? 0) === $nid && !str_contains($a['after'] ?? '', $new), 'key.rotate logged: the new key\'s id and the old one\'s, never a key');
$r = post_json($jM, '/settings/tokens/key-rotate.php', ['api_key' => $nid]);
ok($r['code'] === 404, 'another person rotating her key: not found');
$r = post_json($jA, '/settings/tokens/key-rotate.php', ['api_key' => $nid]);
ok($r['code'] === 403 && str_contains($r['body'], 'own person'), 'even an administrator may not rotate it (they would hold the new key): 403');
$r = post_json($jP, '/settings/tokens/key-rotate.php', ['api_key' => $k1['id']]);
ok($r['code'] === 422 && str_contains($r['body'], 'stopped working'), 'a key past its overlap is not rotated: 422, in words');
$r = req('POST', '/settings/tokens/key-rotate.php', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(26)], 'form' => ['api_key' => $nid]]);
ok($r['code'] === 422 && $health($new) === 200, 'through the relay: refused, the key untouched');

echo "5. Revoking: at once, by its owner or by tokens.manage\n";
$since = last_activity_id();
$r = post_json($jP, '/settings/tokens/key-revoke.php', ['api_key' => $nid]);
ok($r['code'] === 200 && rid($r) === $nid && $health($new) === 401, 'revoked by its owner: the key is 401 at once');
ok(!str_contains(activity('key.revoke', $since)[0]['after'] ?? '', 'sik_') && json_decode(activity('key.revoke', $since)[0]['after'], true)['label'] === 'The transfer agent\'s nightly drop', 'key.revoke logged with the label');
$r = post_json($jP, '/settings/tokens/key-revoke.php', ['api_key' => $nid]);
ok($r['code'] === 422 && str_contains($r['body'], 'revoked already'), 'revoking it again: 422');
$kp = mint_key($jP, 'Priya two');
$r = post_json($jM, '/settings/tokens/key-revoke.php', ['api_key' => $kp['id']]);
ok($r['code'] === 404 && $health($kp['key']) === 200, 'another person revoking it: not found, the key still works');
$r = post_json($jA, '/settings/tokens/key-revoke.php', ['api_key' => $kp['id']]);
ok($r['code'] === 200 && $health($kp['key']) === 401, 'an administrator (tokens.manage) revokes anyone\'s');
$r = agent_post($agent, '/settings/tokens/key-revoke.php', ['api_key' => $kv['id']]);
ok($r['code'] === 403 && $health($kv['key']) === 200, 'an agent: 403, the key untouched');
q('UPDATE members SET status = \'inactive\' WHERE id = 29');
ok($health($kv['key']) === 401, 'a key whose person is no longer active here is 401 (a revoked grant shuts the API too)');
q('UPDATE members SET status = \'active\' WHERE id = 29');
ok($health($kv['key']) === 200, '…and the key works again when she is back');
$r = post_json($jV, '/settings/tokens/key-revoke.php', ['api_key' => $kv['id']]);
ok($r['code'] === 200 && $health($kv['key']) === 401, 'a Viewer, who cannot mint, revokes her own key: a leaked key is killed by the person it acts as');
finish();
