<?php
/**
 * Proof: JSON mode on slice 9's two screens ({data}: the proposals, the agents) and the Tokens screen's keys, and every handler under a person's action token or an agent's run token ({ok, did, record_id, location});
 * a refusal is a 422 in JSON; the settings really change; an agent is refused the people-only doors; the registry: two screens and six actions built, the approvals in step with the manifest.
 */
require __DIR__ . '/lib.php';
$ids = seed9();
$alpha = $ids['alpha'];
$jP = person(26);
$jM = person(27);
$json = ['headers' => ['Accept: application/json']];
$hm = ['Accept: application/json', 'X-Action-Token: ' . person_token(27)];
$hp = ['Accept: application/json', 'X-Action-Token: ' . person_token(26)];
$agent = agent_headers(42, 954);
$shape = static fn (array $r): bool => $r['code'] === 200 && (($b = json_decode($r['body'], true) ?: [])['ok'] ?? false) === true && isset($b['did'], $b['location']) && array_key_exists('record_id', $b);
$post = static fn (array $hdr, string $path, array $form): array => req('POST', $path, ['headers' => $hdr, 'form' => $form]);

echo "1. The screens\n";
$p = page($jP, '/proposals', $json);
$d = json_decode($p['body'], true)['data'] ?? [];
ok($p['code'] === 200 && isset($d['proposals'], $d['counts'], $d['kinds'], $d['filters']) && array_keys($d['kinds']) === ['signal', 'holder_match', 'holder_kind'], '/proposals answers {data}: filters, counts, kinds, proposals');
$p = page($jM, '/admin/agents', $json);
$d = json_decode($p['body'], true)['data'] ?? [];
ok($p['code'] === 200 && isset($d['agents'], $d['settings'], $d['dispatches'], $d['issuers'], $d['counts'], $d['statuses']) && $d['analyst'] === 42 && $d['settings']['pack_day'] === 5 && $d['settings']['morning_note_hour'] === 7 && $d['settings']['morning_note_enabled'] === true && $d['settings']['analyst_proposals'] === true,
    '/admin/agents answers {data}: the agents, the settings (the morning note at 7, Friday, proposals on), the dispatches, the issuers');
$names = array_column($d['agents'], 'display_name');
ok(in_array('SMOKE Surveillance Analyst', $names, true) && in_array('SMOKE Expert', $names, true) && !in_array('SMOKE Priya', $names, true), 'the agents: only agents, the Analyst among them with its roles' . '');
$p = page($jP, '/admin/agents', $json);
ok($p['code'] === 403, 'an Analyst: /admin/agents is 403 (agents.settings)');
$p = page($jP, '/settings/tokens/', $json);
$d = json_decode($p['body'], true)['data'] ?? [];
ok($p['code'] === 200 && array_key_exists('keys', $d) && array_key_exists('may_mint_key', $d) && str_ends_with($d['api_url'] ?? '', '/api/v1/') && !str_contains($p['body'], 'token_hash'), '/settings/tokens/ answers the tokens, the keys and the API\'s URL');
foreach (['/proposals', '/admin/agents'] as $path) {
    $r = req('GET', $path, ['headers' => ['Accept: application/json']]);
    ok($r['code'] === 401, "$path with no session, as JSON: 401");
}

echo "2. Every handler under a token\n";
$dis = q("SELECT id FROM agent_dispatches WHERE status = 'sent' ORDER BY id LIMIT 1")[0]['id'] ?? 0;
$r1 = $post($hm, '/agents/dispatches/cancel.php', ['dispatch' => $dis]);
$r2 = $post($hm, '/agents/settings.php', ['morning_note_hour' => '8', 'pack_day' => '4', 'analyst_proposals' => '0', 'analyst_issuers' => [$alpha], 'escalate_high_to' => '27']);
$r3 = agent_post($agent, '/agents/notify.php', ['recipient' => 26, 'title' => 'SMOKE: json notice', 'kind' => 'digest', 'issuer' => $alpha]);
$r4 = req('POST', '/settings/tokens/key-mint.php', ['jar' => $jP, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . page_csrf($jP)], 'form' => ['label' => 'json key']]);
$kid = (int) (json_decode($r4['body'], true)['record_id'] ?? 0);
$key = (string) (json_decode($r4['body'], true)['key'] ?? '');
$r5 = req('POST', '/settings/tokens/key-rotate.php', ['jar' => $jP, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . page_csrf($jP)], 'form' => ['api_key' => $kid]]);
$kid2 = (int) (json_decode($r5['body'], true)['record_id'] ?? 0);
$r6 = req('POST', '/settings/tokens/key-revoke.php', ['jar' => $jP, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . page_csrf($jP)], 'form' => ['api_key' => $kid2]]);
$results = ['dispatch_cancel' => [$r1, (int) $dis], 'agents_settings_save' => [$r2, 1], 'notification' => [$r3, null], 'key_mint' => [$r4, $kid], 'key_rotate' => [$r5, $kid2], 'key_revoke' => [$r6, $kid2]];
$bad = [];
foreach ($results as $k => [$r, $want]) {
    $rr = (int) (json_decode($r['body'], true)['record_id'] ?? 0);
    if (!$shape($r) || ($want !== null && $rr !== $want) || ($want === null && $rr <= 0)) { $bad[] = $k . ' ' . $r['code'] . ' ' . substr($r['body'], 0, 140); }
}
ok($bad === [], 'the six handlers answer {ok, did, record_id, location}: the dispatch, the settings (1), the notification, the key, the new key, the key' . ($bad ? ' — ' . implode(' | ', $bad) : ''));
$s = q('SELECT morning_note_hour, pack_day, analyst_proposals, analyst_issuer_ids, escalate_high_to FROM si_settings')[0];
ok($s['morning_note_hour'] == 8 && $s['pack_day'] == 4 && $s['analyst_proposals'] === false && $s['analyst_issuer_ids'] === '{' . $alpha . '}' && $s['escalate_high_to'] == 27, 'the settings changed: the hour 8, Thursday, proposals off, the Analyst covering Alpha, the escalation member Marco');
$a = activity('agents_settings.save')[0] ?? [];
$aj = json_decode($a['after'] ?? '{}', true);
$bj = json_decode($a['before'] ?? '{}', true);
ok(($aj['morning_note_hour'] ?? 0) === 8 && ($bj['morning_note_hour'] ?? 0) === 7 && ($aj['pack_day'] ?? 0) === 4 && ($bj['pack_day'] ?? 0) === 5 && !array_key_exists('morning_note_enabled', $aj), 'agents_settings.save logs before and after of the fields that changed only');
$r = $post($hm, '/agents/settings.php', ['pack_day' => '5', 'morning_note_hour' => '7', 'analyst_proposals' => '1', 'analyst_issuers' => [''], 'escalate_high_to' => '']);
$s = q('SELECT pack_day, analyst_issuer_ids, escalate_high_to FROM si_settings')[0];
ok($shape($r) && $s['pack_day'] == 5 && $s['analyst_issuer_ids'] === '{}' && $s['escalate_high_to'] === null, 'and back: an empty list covers every issuer, an empty person is none');
$r = $post($hm, '/agents/settings.php', ['morning_note_hour' => '25']);
ok($r['code'] === 422 && isset(json_decode($r['body'], true)['error']['fields']['morning_note_hour']), 'an hour of 25: 422, the field named');
$r = $post($hm, '/agents/settings.php', ['pack_day' => '0']);
ok($r['code'] === 422, 'a pack day of 0: 422');
$r = $post($hm, '/agents/settings.php', ['escalate_high_to' => '40']);
ok($r['code'] === 422 && isset(json_decode($r['body'], true)['error']['fields']['escalate_high_to']), 'an agent as the escalation member: 422 (a person is told)');
$r = $post($hm, '/agents/settings.php', ['analyst_issuers' => ['999999']]);
ok($r['code'] === 422, 'an issuer that is not one: 422 (the database\'s sentence)');
$r = $post($hp, '/agents/settings.php', ['pack_day' => '3']);
ok($r['code'] === 403 && one('SELECT pack_day FROM si_settings') == 5, 'an Analyst\'s token: 403, nothing changed (agents.settings)');
$r = agent_post($agent, '/agents/settings.php', ['pack_day' => '3']);
ok($r['code'] === 403 && one('SELECT pack_day FROM si_settings') == 5, 'an agent: 403 — the settings are a person\'s (the registry says people)');
$r = $post($hm, '/agents/dispatches/cancel.php', ['dispatch' => 999999]);
ok($r['code'] === 404, 'cancelling a dispatch that is not there: 404');

echo "3. The registry\n";
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$screens = ['proposals', 'admin-agents'];
$actions = ['notification', 'dispatch_cancel', 'agents_settings_save', 'key_mint', 'key_rotate', 'key_revoke'];
ok(array_filter($screens, static fn ($s) => empty($reg['screens'][$s]['built'])) === [] && array_filter($actions, static fn ($a) => empty($reg['actions'][$a]['built'])) === [], 'the registry marks the two screens and the six actions built');
ok(array_values(array_filter($actions, static fn ($a) => $reg['actions'][$a]['agent'] === false)) === ['dispatch_cancel', 'agents_settings_save', 'key_mint', 'key_rotate', 'key_revoke'] && $reg['actions']['notification']['agent'] === true, 'people-only: the cancel, the settings and the three key doors; the notification is an agent\'s door');
$ap = array_column(json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json'), true)['approvals'] ?? [], 'category', 'action');
$mine = array_intersect_key($ap, array_flip($actions));
ksort($mine);
ok($mine === ['agents_settings_save' => 'other', 'key_mint' => 'other'], 'two approvals in step with the manifest: agents_settings_save and key_mint (other)');
finish();
