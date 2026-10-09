<?php
/**
 * Proof: JSON mode on every slice 1 screen and handler (the kernel's actions server and the command bar call them): each screen answers {data} (the kit's respond_screen)
 * to Accept: application/json; each handler answers {ok, did, record_id, location} under an action token (a person's) and under an
 * agent's run token with its relay; a refusal is JSON with its code; nothing renders HTML to a JSON caller.
 */
require __DIR__ . '/lib.php';
edgar_contact(true);
[$jM, ] = sign_on(27);
$json = ['headers' => ['Accept: application/json']];
$a = (int) (post_json($jM, '/issuers/save.php', ['name' => 'SMOKE JSON CORP', 'ticker' => 'SMJS', 'cik' => '999999'])['json']['record_id'] ?? 0);
$sec = (int) (post_json($jM, '/issuers/securities/save.php', ['issuer' => $a, 'name' => 'Common Stock', 'cusip' => '99902A101'])['json']['record_id'] ?? 0);

echo "1. Every screen answers JSON\n";
$screens = ['/issuers', '/issuers/' . $a, '/issuers/' . $a . '?tab=sources', '/issuers/' . $a . '?tab=notes', '/issuers/new', '/issuers/new?ticker=SMOK', '/issuers/' . $a . '/edit',
            '/issuers/' . $a . '/securities', '/issuers/' . $a . '/share-counts', '/issuers/' . $a . '/access', '/participants', '/participants/1', '/participants/1/edit'];
$bad = [];
foreach ($screens as $u) {
    $r = page($jM, $u, $json);
    $b = json_decode($r['body'], true);
    if ($r['code'] !== 200 || !is_array($b) || !isset($b['data']) || isset($b['error']) || preg_match('/^Content-Type: application\/json/mi', $r['headers']) !== 1) { $bad[] = "$u {$r['code']}"; }
}
ok($bad === [], 'all ' . count($screens) . ' slice 1 screens answer {data} as JSON' . ($bad ? ': ' . implode(', ', $bad) : ''));
$r = page($jM, '/issuers/99999', $json);
ok($r['code'] === 404 && (json_decode($r['body'], true)['error']['code'] ?? '') === 'not_found', 'a missing issuer: 404 not_found, as JSON');
[$jV, ] = sign_on(29);
$r = page($jV, '/issuers/new', $json);
ok($r['code'] === 403 && (json_decode($r['body'], true)['error']['code'] ?? '') === 'forbidden', 'a refused screen: 403 forbidden, as JSON');

echo "2. Every handler answers JSON under a person's action token\n";
$tok = person_token(27);
$h = ['Accept: application/json', 'X-Action-Token: ' . $tok];
$calls = [
    ['/issuers/save.php', ['issuer' => $a, 'notes' => 'json']],
    ['/issuers/edgar-sync.php', ['issuer' => $a]],
    ['/issuers/securities/save.php', ['security' => $sec, 'ticker' => 'SMJS']],
    ['/issuers/share-counts/save.php', ['security' => $sec, 'as_of' => '2026-09-30', 'kind' => 'float', 'value' => '1000']],
    ['/issuers/access/save.php', ['issuer' => $a, 'members' => '30']],
    ['/issuers/access/save.php', ['issuer' => $a, 'members' => '']],
    ['/participants/save.php', ['participant' => 2, 'notes' => 'json']],
    ['/tags/apply.php', ['kind' => 'issuer', 'record' => $a, 'tag' => 'json']],
    ['/tags/remove.php', ['kind' => 'issuer', 'record' => $a, 'tag' => 'json']],
    ['/notes/add.php', ['kind' => 'issuer', 'record' => $a, 'body' => 'json']],
];
$bad = [];
foreach ($calls as [$u, $f]) {
    $r = req('POST', $u, ['headers' => $h, 'form' => $f]);
    $b = json_decode($r['body'], true);
    if ($r['code'] !== 200 || ($b['ok'] ?? false) !== true || !isset($b['did']) || !array_key_exists('record_id', $b) || !isset($b['location'])) { $bad[] = "$u {$r['code']} " . substr($r['body'], 0, 120); }
}
ok($bad === [], 'all ' . count($calls) . ' calls answer {ok, did, record_id, location}' . ($bad ? ': ' . implode(' | ', $bad) : ''));
$cid = (int) one("SELECT id FROM share_counts WHERE security_id = :s AND kind = 'float'", ['s' => $sec]);
$r = req('POST', '/issuers/share-counts/delete.php', ['headers' => $h, 'form' => ['share_count' => $cid]]);
ok($r['code'] === 200 && (json_decode($r['body'], true)['record_id'] ?? 0) === $cid, 'share_count_delete answers the record it removed');
$r = req('POST', '/issuers/save.php', ['headers' => $h, 'form' => ['name' => '']]);
$b = json_decode($r['body'], true);
ok($r['code'] === 422 && ($b['error']['code'] ?? '') === 'invalid' && (isset($b['error']['fields']['name']) || isset($b['fields']['name'])), 'a refusal: 422 invalid with the field named');
$r = req('POST', '/issuers/save.php', ['headers' => $h, 'form' => ['name' => 'SMOKE MADE BY TOKEN']]);
$nid = (int) (json_decode($r['body'], true)['record_id'] ?? 0);
ok($r['code'] === 200 && $nid > 0 && one('SELECT created_by FROM issuers WHERE id = :i', ['i' => $nid]) === 27, 'a create answers its record_id, made as the token\'s person');
$r = req('POST', '/issuers/delete.php', ['headers' => $h, 'form' => ['issuer' => $nid]]);
ok($r['code'] === 200 && (json_decode($r['body'], true)['record_id'] ?? 0) === $nid, 'issuer_delete answers its record');
$r = req('POST', '/issuers/restore.php', ['headers' => $h, 'form' => ['issuer' => $nid]]);
ok($r['code'] === 200, 'issuer_restore too');
$r = req('POST', '/issuers/securities/delete.php', ['headers' => $h, 'form' => ['security' => $sec]]);
ok($r['code'] === 200 && (json_decode($r['body'], true)['record_id'] ?? 0) === $sec, 'security_delete too');

echo "3. Under an agent's run token (the Surveillance Analyst, an Analyst)\n";
kernel_state(function ($s) { $s['facts']['501'] = ['valid' => true, 'member_id' => 42, 'run_id' => 501, 'request_id' => 'req-501', 'is_eval' => false]; return $s; });
$rt = as_agent(run_token(42, 501));
$r = req('POST', '/notes/add.php', ['headers' => $rt, 'form' => ['kind' => 'issuer', 'record' => $a, 'body' => 'SMOKE: an agent\'s note']]);
ok($r['code'] === 200 && (int) one("SELECT agent_run_id FROM activity_log WHERE action = 'note.add' ORDER BY id DESC LIMIT 1") === 501 && one("SELECT source FROM activity_log WHERE action = 'note.add' ORDER BY id DESC LIMIT 1") === 'agent', 'an agent\'s note_add: logged with its run and source agent');
ok(req('POST', '/issuers/save.php', ['headers' => $rt, 'form' => ['name' => 'X']])['code'] === 403, 'an agent holding Analyst may not save an issuer (issuers.manage): 403');
ok(page(jar(), '/issuers', ['headers' => $rt])['code'] === 200, 'it reads the issuers');
finish();
