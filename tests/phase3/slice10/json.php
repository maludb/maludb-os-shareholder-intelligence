<?php
/**
 * Proof: JSON mode on the ten screens ({data}) and every handler under a person's action token ({ok, did, record_id, location}); an agent's source_save pauses by policy (approval category) and the
 * people-only doors refuse an agent; the registry: ten screens and eleven actions built, no stub left, the approvals in step with the manifest.
 */
require __DIR__ . '/lib.php';
seed_store();
$jA = who(1); $jP = who(26);
$ha = ['Accept: application/json', 'X-Action-Token: ' . person_token(1)];
$dtcc = source_id('dtcc_participants');
$shape = static fn (array $r): bool => $r['code'] === 200 && (($b = json_decode($r['body'], true) ?: [])['ok'] ?? false) === true && isset($b['did'], $b['location']) && array_key_exists('record_id', $b);
$post = static fn (array $h, string $path, array $form): array => req('POST', $path, ['headers' => $h, 'form' => $form]);

echo "1. The screens\n";
$t = (int) one("SELECT id FROM import_templates ORDER BY id LIMIT 1");
$bad = [];
foreach (['/admin/sources' => 'sources', '/admin/sources/' . $dtcc => 'source', '/admin/sources/' . $dtcc . '/edit' => 'source', '/admin/sources/' . $dtcc . '/credential' => 'source', '/admin/participant-classes' => 'classes',
          '/admin/templates' => 'templates', '/admin/templates/' . $t . '/edit' => 'template', '/admin/settings' => 'settings', '/admin/tokens' => 'tokens', '/admin/purge' => 'tombstones'] as $path => $key) {
    $g = get_json($jA, $path);
    if ($g['code'] !== 200 || !array_key_exists($key, $g['data'])) { $bad[] = $path . ' ' . $g['code']; }
    if (get_json($jP, $path)['code'] !== 403) { $bad[] = 'analyst ' . $path; }
    if (req('GET', $path, ['headers' => ['Accept: application/json']])['code'] !== 401) { $bad[] = 'anon ' . $path; }
}
ok($bad === [], 'ten screens answer {data} to an admin, 403 to an Analyst, 401 with no session' . ($bad ? ' — ' . implode(', ', $bad) : ''));

echo "2. Every handler under a token\n";
$r = [];
$r['source_save'] = [$post($ha, '/admin/sources/save.php', ['source' => $dtcc, 'rate_per_second' => '3']), $dtcc];
$r['source_schedule_set'] = [$post($ha, '/admin/sources/schedule.php', ['source' => $dtcc, 'schedule_minutes' => '1440']), $dtcc];
$r['source_pause'] = [$post($ha, '/admin/sources/pause.php', ['source' => $dtcc, 'reason' => 'json']), $dtcc];
$r['source_resume'] = [$post($ha, '/admin/sources/resume.php', ['source' => $dtcc]), $dtcc];
$r['credential_set'] = [$post($ha, '/admin/sources/credential.php', ['source' => $dtcc, 'label' => 'k', 'secret' => 'SMOKE-JSON-KEY']), $dtcc];
$part = (int) one('SELECT id FROM participants ORDER BY id LIMIT 1');
$r['participant_class_set'] = [$post($ha, '/admin/participant-classes/set.php', ['participant' => $part, 'class' => 'lending']), $part];
$ts = $post($ha, '/admin/templates/save.php', ['name' => 'SMOKE json t', 'kind' => 'holders_csv', 'mapping' => '{"a":1}']);
$tid = (int) (json_decode($ts['body'], true)['record_id'] ?? 0);
$r['template_save'] = [$ts, $tid];
$r['template_delete'] = [$post($ha, '/admin/templates/delete.php', ['template' => $tid]), $tid];
$r['settings_save'] = [$post($ha, '/admin/settings/save.php', ['signal_grace_days' => '9']), 1];
$k = mint_token_row(26, 'SMOKE json key', 'api');
$r['token_revoke_any'] = [$post($ha, '/admin/tokens/revoke.php', ['token' => $k]), $k];
$r['records_purge'] = [$post($ha, '/admin/purge/run.php', ['kind' => 'all']), 0];
$bad = [];
foreach ($r as $name => [$resp, $want]) {
    $rr = (int) (json_decode($resp['body'], true)['record_id'] ?? -1);
    if (!$shape($resp) || $rr !== $want) { $bad[] = "$name {$resp['code']} $rr " . substr($resp['body'], 0, 100); }
}
ok($bad === [], 'the eleven handlers answer {ok, did, record_id, location}' . ($bad ? ' — ' . implode(' | ', $bad) : ''));
ok((int) one('SELECT signal_grace_days FROM si_settings') === 9 && (int) one('SELECT schedule_minutes FROM sources WHERE id = :i', ['i' => $dtcc]) === 1440, 'and really changed things');
$rr = $post($ha, '/admin/sources/schedule.php', ['source' => $dtcc, 'schedule_minutes' => 'x']);
ok($rr['code'] === 422 && isset(json_decode($rr['body'], true)['error']['fields']['schedule_minutes']), 'a refusal is a 422 with the field');

echo "3. An agent\n";
$agent = agent_headers(42, 960);
ok($post($agent, '/admin/sources/credential.php', ['source' => $dtcc, 'label' => 'a', 'secret' => 'SMOKE-AGENT-KEY'])['code'] === 403
    && $post($agent, '/admin/settings/save.php', ['signal_grace_days' => '1'])['code'] === 403 && $post($agent, '/admin/tokens/revoke.php', ['token' => $k])['code'] === 403, 'credential_set, settings_save and token_revoke_any are people\'s: an agent is refused');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$ap = array_column(json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json'), true)['approvals'] ?? [], 'category', 'action');
$mine = array_intersect_key($ap, array_flip(['source_save', 'source_schedule_set', 'source_pause', 'source_resume', 'credential_set', 'participant_class_set', 'template_save', 'template_delete', 'settings_save', 'token_revoke_any', 'records_purge']));
ksort($mine);
ok($mine === ['credential_set' => 'other', 'records_purge' => 'deletion', 'settings_save' => 'other', 'source_save' => 'other', 'source_schedule_set' => 'other', 'template_delete' => 'deletion'], 'six approvals in step with the manifest (an agent\'s source_save pauses)');
$people = ['credential_set', 'settings_save', 'token_revoke_any'];
ok(array_filter($people, static fn ($a) => $reg['actions'][$a]['agent'] !== false) === [] && $reg['actions']['source_save']['agent'] === true, 'the registry: the three people-only actions are marked so');

echo "4. The registry\n";
$screens = ['admin-sources', 'admin-source-view', 'admin-source-edit', 'admin-source-credential', 'admin-participant-classes', 'admin-templates', 'admin-template-edit', 'admin-settings', 'admin-tokens', 'admin-purge'];
$actions = array_keys($mine) + [];
ok(array_filter($screens, static fn ($s) => empty($reg['screens'][$s]['built'])) === [] && array_filter(array_keys($mine), static fn ($a) => empty($reg['actions'][$a]['built'])) === [], 'ten screens and eleven actions built');
ok(array_filter($reg['screens'], static fn ($s) => !empty($s['stub'])) === [] && array_filter($reg['screens'], static fn ($s) => empty($s['built'])) === [], 'no screen of the manifest is a stub: ' . count($reg['screens']) . ' of ' . count($reg['screens']) . ' built');
$stubs = trim((string) shell_exec('grep -rl "render_nav_stub(\|render_module_stub(" ' . escapeshellarg(dirname(__DIR__, 3) . '/html') . ' ' . escapeshellarg(dirname(__DIR__, 3) . '/app') . ' 2>/dev/null'));
ok($stubs === '', 'no controller calls a placeholder helper, and the helper is gone');
finish();
