<?php
/**
 * Proof: JSON mode on every slice 7 screen ({data}: the signals with their class, basis, proposal and disposition; one signal whole — the rule, the
 * evidence, the events, the outcome; the history; the rules with the thresholds in effect) and handler under a person's action token ({ok, did,
 * record_id, location}); a refusal is a 422 in JSON; the registry: five screens and nine actions built, the people-only ones marked, three approvals.
 */
require __DIR__ . '/lib.php';
$ids = seed7();
$sec = $ids['security'];
[$jP, ] = sign_on(26);
page($jP, '/?issuer=' . $ids['issuer']);
$json = ['headers' => ['Accept: application/json']];

echo "1. Every screen answers JSON\n";
$h = ['Accept: application/json', 'X-Action-Token: ' . person_token(26)];
$r = req('POST', '/signals/run.php', ['headers' => $h, 'form' => ['security' => $sec, 'as_of' => AS_OF]]);
$b = json_decode($r['body'], true) ?: [];
ok($r['code'] === 200 && ($b['ok'] ?? false) && ($b['raised'] ?? 0) === 17 && (int) $b['record_id'] === $sec && isset($b['location'], $b['did']) && count($b['raised_ids'] ?? []) === 17, 'rules_run answers {ok, did, record_id: the security, location} and the counts');
$sof = signal_of('si_over_float', $sec);
$data = [];
$bad = [];
foreach (['/signals', '/signals/' . $sof, '/signals/history?from=2024-04-01&to=2024-04-30', '/rules', '/rules/' . (int) one("SELECT id FROM signal_rules WHERE key = 'si_over_float'") . '/edit'] as $u) {
    $jar = str_contains($u, '/edit') ? sign_on(27)[0] : $jP;
    if (str_contains($u, '/edit')) { page($jar, '/?issuer=' . $ids['issuer']); }
    $p = page($jar, $u, $json);
    $d = json_decode($p['body'], true);
    if ($p['code'] !== 200 || !isset($d['data'])) { $bad[] = "$u {$p['code']}"; }
    $data[preg_replace('~\?.*~', '', preg_replace('~/\d+~', '/{id}', $u))] = $d['data'] ?? [];
}
ok($bad === [], 'the five slice 7 screens answer {data}' . ($bad ? ': ' . implode(', ', $bad) : ''));
$list = $data['/signals'];
$one = array_values(array_filter($list['signals'] ?? [], static fn ($s) => $s['signal_id'] === $sof))[0] ?? [];
ok(count($list['signals'] ?? []) === 17 && ($list['counts'] ?? []) === ['high' => 7, 'watch' => 8, 'info' => 2] && ($one['evidence_class'] ?? '') === 'established' && str_contains((string) ($one['basis'] ?? ''), 'Hong et al.'),
    'the list: seventeen signals, the counts by severity, each with its class and basis');
$v = $data['/signals/{id}'];
ok(($v['signal']['signal_id'] ?? 0) === $sof && ($v['rule']['key'] ?? '') === 'si_over_float' && ($v['rule']['params']['dtc_high'] ?? null) === 10 && ($v['evidence']['days_to_cover'] ?? null) === 11.28 && is_array($v['events'] ?? null) && array_key_exists('outcome', $v),
    'one signal: the signal, the rule with its thresholds in effect, the evidence as the rule wrote it, the events, the outcome');
ok(($data['/signals/history']['total'] ?? 0) === 17 && isset($data['/signals/history']['proposals']), 'the history: seventeen in April, the proposals counted');
ok(count($data['/rules']['rules'] ?? []) === 19 && isset($data['/rules']['rules'][0]['outcome']['too_few_to_say']), 'the rules: nineteen, each with its outcome');
ok(($data['/rules/{id}/edit']['rule']['key'] ?? '') === 'si_over_float' && ($data['/rules/{id}/edit']['override_fields'] ?? []) === ['issuer', 'params', 'enabled'], 'the rule form: the rule and the fields');

echo "2. Every handler under a person's action token\n";
$hm = ['Accept: application/json', 'X-Action-Token: ' . person_token(27)];
$shape = static fn (array $r): bool => $r['code'] === 200 && (($b = json_decode($r['body'], true) ?: [])['ok'] ?? false) === true && isset($b['did'], $b['location']) && array_key_exists('record_id', $b);
$post = static fn (array $hdr, string $path, array $form): array => req('POST', $path, ['headers' => $hdr, 'form' => $form]);
$rid = static fn (array $r): int => (int) (json_decode($r['body'], true)['record_id'] ?? 0);
$svr = signal_of('short_volume_ratio', $sec);
$results = [
    'signal_acknowledge' => [$r = $post($h, '/signals/acknowledge.php', ['signal' => signal_of('borrow_fee', $sec), 'reason' => 'known']), signal_of('borrow_fee', $sec)],
    'signal_dismiss' => [$r = $post($h, '/signals/dismiss.php', ['signal' => signal_of('threshold_list', $sec), 'reason' => 'explained_by_event', 'explaining_event' => $ids['ev_short']]), signal_of('threshold_list', $sec)],
    'signal_escalate' => [$post($h, '/signals/escalate.php', ['signal' => signal_of('new_13d', $sec), 'note' => 'counsel']), signal_of('new_13d', $sec)],
    'signal_propose' => [$post($h, '/signals/propose.php', ['signal' => signal_of('si_change', $sec), 'reason' => 'watching', 'note' => 'a person may propose too']), signal_of('si_change', $sec)],
    'signal_note' => [$post($h, '/signals/note.php', ['signal' => $sof, 'body' => 'a note']), null],
    'rule_save' => [$post($hm, '/rules/save.php', ['rule' => (int) one("SELECT id FROM signal_rules WHERE key = 'borrow_fee'"), 'params' => '{"fee_pct": 25}']), (int) one("SELECT id FROM signal_rules WHERE key = 'borrow_fee'")],
    'rule_override_save' => [$post($hm, '/rules/override.php', ['rule' => (int) one("SELECT id FROM signal_rules WHERE key = 'borrow_fee'"), 'issuer' => $ids['issuer'], 'params' => '{"fee_pct": 30}', 'enabled' => 'yes']), (int) one("SELECT id FROM signal_rules WHERE key = 'borrow_fee'")],
    'signal_delete' => [$post($hm, '/signals/delete.php', ['signal' => $svr, 'reason' => 'corrected']), $svr],
];
$bad = [];
foreach ($results as $k => [$r, $want]) {
    if (!$shape($r) || ($want !== null && $rid($r) !== $want)) { $bad[] = $k . ' ' . $r['code'] . ' ' . substr($r['body'], 0, 120); }
}
ok($bad === [], 'the eight other handlers answer {ok, did, record_id, location} — the record the signal, the note or the rule' . ($bad ? ': ' . implode(' | ', $bad) : ''));
ok((float) json_decode((string) one("SELECT params::text FROM signal_rules WHERE key = 'borrow_fee'"), true)['fee_pct'] === 25.0 && json_decode((string) one("SELECT o.params::text FROM signal_rule_overrides o JOIN signal_rules r ON r.id = o.rule_id WHERE r.key = 'borrow_fee'"), true)['fee_pct'] === 30
    && one("SELECT o.enabled FROM signal_rule_overrides o JOIN signal_rules r ON r.id = o.rule_id WHERE r.key = 'borrow_fee'") === true, 'an agent\'s params as a JSON object: the rule at 25%, the issuer\'s own at 30% and on');
$r = $post($h, '/signals/dismiss.php', ['signal' => $sof, 'reason' => 'nonsense']);
ok($r['code'] === 422 && (json_decode($r['body'], true)['error']['code'] ?? '') === 'invalid', 'a refusal: 422 as JSON');

echo "3. The registry\n";
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$screens = ['signal-list', 'signal-view', 'signal-history', 'rule-list', 'rule-edit'];
$actions = ['signal_acknowledge', 'signal_dismiss', 'signal_escalate', 'signal_propose', 'signal_note', 'signal_delete', 'rules_run', 'rule_save', 'rule_override_save'];
ok(array_filter($screens, static fn ($s) => empty($reg['screens'][$s]['built'])) === [] && array_filter($actions, static fn ($a) => empty($reg['actions'][$a]['built'])) === [], 'the registry marks the five screens and the nine actions built');
ok(array_values(array_filter($actions, static fn ($a) => $reg['actions'][$a]['agent'] === false)) === ['signal_acknowledge', 'signal_dismiss', 'signal_escalate'], 'the three dispositions are people only; the agent\'s door is signal_propose');
$ap = array_column(json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json'), true)['approvals'] ?? [], 'category', 'action');
ok(array_intersect_key($ap, array_flip($actions)) === ['rule_override_save' => 'other', 'rule_save' => 'other', 'signal_delete' => 'deletion'], 'three approvals in step with the manifest: signal_delete (deletion), rule_save and rule_override_save (other)');
ok(in_array('reason', array_column($reg['actions']['signal_delete']['params'], 'name'), true), 'signal_delete carries its reason (the manifest row gained it)');
finish();
