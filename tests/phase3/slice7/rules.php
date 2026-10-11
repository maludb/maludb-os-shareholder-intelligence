<?php
/**
 * Proof: the rules (signals.md §3, §8; signals-rules.md "Proof") — the worker's rules pass after a fixture upload raises the SPR rules; the crafted
 * fixtures raise every rule on a run, each signal a card with its class chip and its basis, every evidence partial its table and (where the rule has
 * one) its chart; a second run raises nothing new; a high signal tells the escalation member and dispatches the Analyst; a member who asked is told;
 * a disabled rule raises nothing; an issuer's own threshold changes that issuer's next run only; the 06:45 run on a market day and not on a holiday
 * nor before 06:45; expiry.
 */
require __DIR__ . '/lib.php';
const KEYS = ['si_over_float', 'si_change', 'borrow_fee', 'ftd_spike', 'threshold_list', 'short_volume_ratio', 'prime_migration', 'participant_jump', 'concentration_shift', 'nobo_drain',
              'register_untied', 'new_13d', 'holder_5pct', 'top_holder_exit', 'insider_sales', 'dilution_overlap', 'activist_short_report'];
const NO_CHART = ['spr_untied', 'register_untied', 'new_13d', 'holder_5pct'];
[$jP, ] = sign_on(26);
[$jM, ] = sign_on(27);

echo "1. A fixture upload, then the worker's rules pass raises the SPR rules\n";
$sm = smok_issuer();
$sec = $sm['security'];
$tied = 0;
foreach (fortnight_csvs() as [$name, $bytes]) {
    $r = upload_bytes($jP, $name, $bytes, ['security' => $sec, 'kind' => 'spr']);
    $tied += $r['code'] === 200 && ($r['json']['tied'] ?? null) === true ? 1 : 0;
}
ok($tied === 2, 'the fixture fortnight uploaded through the form as DTC\'s two weekly files, both tied' . ($tied < 2 ? ': ' . substr($r['body'], 0, 200) : ''));
ok((int) one('SELECT count(*) FROM si_rules_due(50) WHERE security_id = :s', ['s' => $sec]) === 1, 'the upload makes the security due for the rules (si_rules_due)');
$since = last_activity_id();
$w = worker('rules', MORNING);
$rep = $w['report']['ran']['rules'] ?? [];
ok($w['rc'] === 0 && ($rep['securities'] ?? 0) === 1 && ($rep['raised'] ?? 0) >= 2 && ($rep['daily'] ?? null) === true, 'the worker\'s pass ran the security once — its change and, at 07:00 on a market day, its 06:45 run in one: ' . json_encode($rep));
$k = open_keys($sec);
ok(in_array('prime_migration', $k, true) && in_array('participant_jump', $k, true), 'the SPR rules fired from the uploaded file: prime_migration (lore) and participant_jump (' . implode(', ', $k) . ')');
$raise = activity('signal.raise', $since);
$a = json_decode($raise[0]['after'] ?? '{}', true);
ok(count($raise) === count($k) && $raise[0]['source'] === 'cron' && (int) $raise[0]['signal_id'] > 0 && (int) $raise[0]['security_id'] === $sec && !array_diff(array_keys($a), ['rule', 'severity', 'value', 'security_id']),
    'signal.raise a signal at a time, by cron, with the rule, the severity, the value and the security only');
$rr = q('SELECT * FROM rule_runs WHERE security_id = :s', ['s' => $sec])[0] ?? [];
ok(($rr['as_of'] ?? '') === AS_OF && ($rr['daily_on'] ?? '') === AS_OF, 'rule_runs remembers the run as of 2024-04-05 and the day\'s 06:45 run');
$w = worker('rules', MORNING);
ok(($w['report']['ran']['rules']['securities'] ?? -1) === 0 && ($w['report']['ran']['rules']['daily'] ?? null) === false, 'the pass again at once: nothing changed, the day\'s run done — nothing runs');

echo "2. The crafted fixtures raise every rule\n";
$ids = seed7();
ok($ids['security'] === $sec, 'the seed takes the uploaded issuer\'s security');
$w = worker('rules', MORNING);
$rep = $w['report']['ran']['rules'] ?? [];
ok(($rep['securities'] ?? 0) >= 2, 'the worker runs both securities: their inputs changed (' . json_encode($rep) . ')');
ok(open_keys($sec) === KEYS, 'seventeen rules open on the SMOKE security, in run order: ' . implode(', ', open_keys($sec)));
ok(open_keys($ids['security2']) === ['participant_jump', 'spr_untied', 'price_volume_anomaly'], 'the second issuer: its SPR against a DTC letter (spr_untied) and a 20% drop on volume with no event (price_volume_anomaly)');
ok((int) one('SELECT count(DISTINCT r.key) FROM signals s JOIN signal_rules r ON r.id = s.rule_id') === 19, 'every one of the nineteen rules has raised a signal');
ok((int) one("SELECT count(*) FROM signals s JOIN signal_rules r ON r.id = s.rule_id WHERE r.key = 'dilution_overlap' AND s.severity = 'high'") === 1, 'dilution_overlap reads the open short-side signals the earlier rules raised (the run order)');
$high = (int) one("SELECT count(*) FROM signals WHERE severity = 'high'");
ok($high >= 5 && (int) one("SELECT count(*) FROM notifications WHERE member_id = 27 AND kind = 'signal_high'") === $high && (int) one("SELECT count(*) FROM agent_dispatches WHERE kind = 'signal' AND agent_member_id = 42") === $high,
    "every high signal ($high) told the escalation member (the IR Manager) and dispatched the Surveillance Analyst once");

echo "3. The cards: severity, the class chip, the basis\n";
page($jP, '/?issuer=' . $sm['issuer']);
$h = page($jP, '/signals')['body'];
ok(substr_count($h, 'id="signal-card-') === 17, 'seventeen cards on the SMOKE issuer');
preg_match_all('~class="badge (text-bg-[a-z]+) signal-class" data-class="([a-z_]+)">([^<]+)<~', $h, $m, PREG_SET_ORDER);
$chips = [];
foreach ($m as [, $cls, $class, $words]) { $chips[$class] = [$cls, $words]; }
ok(($chips['established'] ?? []) === ['text-bg-primary', 'Established'] && ($chips['contested'] ?? []) === ['text-bg-warning', 'Contested'] && ($chips['lore'] ?? []) === ['text-bg-secondary', 'Lore — no published validation'] && ($chips['data_defect'] ?? []) === ['text-bg-danger', 'Data defect'],
    'each class chip in its colour and words: Established, Contested, Lore — no published validation, Data defect');
ok(str_contains($h, 'title="The basis: Short interest over float and days to cover predict negative returns (Hong et al., NBER w21166).'), 'a card\'s tooltip is its rule\'s basis');
$first = strpos($h, 'data-severity="high"');
ok($first !== false && $first < strpos($h, 'data-severity="watch"') && strpos($h, 'data-severity="watch"') < strpos($h, 'data-severity="info"'), 'high first, then watch, then info');

echo "4. Every evidence partial renders its table and its chart\n";
$bad = [];
foreach (q('SELECT s.id, r.key FROM signals s JOIN signal_rules r ON r.id = s.rule_id ORDER BY r.sort_order') as $g) {
    if ((int) $g['id'] === 0) { continue; }
    $iss = (int) one('SELECT issuer_id FROM signals WHERE id = :i', ['i' => $g['id']]);
    $p = page($jP, '/signals/' . $g['id'] . '?issuer=' . $iss);
    $b = $p['body'];
    $want = !in_array($g['key'], NO_CHART, true);
    if ($p['code'] !== 200 || !str_contains($b, 'id="signal-evidence-table"') || !str_contains($b, 'id="signal-basis"') || !str_contains($b, 'id="signal-class"')
        || $want !== str_contains($b, 'id="signal-chart-svg"') || str_contains($b, 'id="signal-chart"') !== $want) {
        $bad[] = $g['key'] . ' (' . $p['code'] . ')';
    }
}
ok($bad === [], 'all nineteen: the evidence table, the basis, the class chip; a chart for the fifteen that have one, none for the data defects, the 13D and the 5% holder' . ($bad ? ' — wrong: ' . implode(', ', $bad) : ''));
$b = page($jP, '/signals/' . signal_of('short_volume_ratio', $sec))['body'];
ok(str_contains($b, 'id="signal-caveat"') && str_contains($b, 'Not comparable with short interest'), 'short_volume_ratio prints FINRA\'s caveat in full');
$b = page($jP, '/signals/' . signal_of('prime_migration', $sec))['body'];
ok(str_contains($b, 'No published study validates this rule.') && str_contains($b, 'data-class="lore"'), 'a lore rule says so under its basis');

echo "5. A second run raises nothing new\n";
$since = last_activity_id();
$r = run_rules($jP, $sec);
ok($r['code'] === 200 && ($r['json']['raised'] ?? -1) === 0 && ($r['json']['refreshed'] ?? 0) === 17 && (int) one("SELECT count(*) FROM signals WHERE security_id = :s AND status = 'open'", ['s' => $sec]) === 17,
    'rules_run as a person: 0 raised, 17 refreshed — one open signal per rule per security');
ok(count(activity('signal.refresh', $since)) === 17 && count(activity('rules.run', $since)) === 1 && json_decode(activity('rules.run', $since)[0]['after'], true)['refreshed'] === 17, 'signal.refresh each, and rules.run with the counts');

echo "6. A member who asked for signals is told — never of an issuer they may not see\n";
q("INSERT INTO notification_prefs (member_id, kinds, signals_min_severity) VALUES (26, '{signal,signal_high}', 'watch') ON CONFLICT (member_id) DO UPDATE SET kinds = EXCLUDED.kinds, signals_min_severity = EXCLUDED.signals_min_severity");
q("INSERT INTO notification_prefs (member_id, kinds, signals_min_severity) VALUES (30, '{signal}', 'info') ON CONFLICT (member_id) DO UPDATE SET kinds = EXCLUDED.kinds, signals_min_severity = EXCLUDED.signals_min_severity");
q("INSERT INTO issuer_members (issuer_id, member_id, added_by) VALUES (:i, 30, 27)", ['i' => $ids['issuer2']]);   // the second issuer restricted to Dana
$told = static fn (int $m): int => (int) one("SELECT count(*) FROM notifications WHERE member_id = :m AND kind = 'signal'", ['m' => $m]);
q("DELETE FROM signals WHERE security_id = :s AND rule_id IN (SELECT id FROM signal_rules WHERE key IN ('borrow_fee', 'participant_jump'))", ['s' => $sec]);
[$p26, $p30] = [$told(26), $told(30)];
run_rules($jP, $sec);
ok($told(26) === $p26 + 1 && $told(30) === $p30 + 2, 'SMOKE raises borrow_fee (watch) and participant_jump (info) again: the Analyst (watch and above) is told of one, Dana (everything) of both');
q("DELETE FROM signals WHERE security_id = :s AND rule_id = (SELECT id FROM signal_rules WHERE key = 'spr_untied')", ['s' => $ids['security2']]);
[$p26, $p30] = [$told(26), $told(30)];
app_php('require_once ' . var_export(dirname(__DIR__, 3) . '/app/features/signals/write.php', true) . '; echo json_encode(rules_run($pdo, ' . $ids['security2'] . ', "2024-04-05"));');
ok($told(30) === $p30 + 1 && $told(26) === $p26, 'the second issuer (restricted to Dana) raises spr_untied again: Dana is told, the Analyst — who may not see it — is not');

echo "7. A disabled rule raises nothing\n";
$s2 = $ids['security2'];
page($jM, '/?issuer=' . $sm['issuer']);
$pva = signal_of('price_volume_anomaly', $s2);
q("INSERT INTO issuer_members (issuer_id, member_id, added_by) VALUES (:i, 26, 27), (:i2, 27, 27)", ['i' => $ids['issuer2'], 'i2' => $ids['issuer2']]);
$r = post_json($jM, '/signals/delete.php', ['signal' => $pva, 'reason' => 'the prices of the test were fixed']);
ok($r['code'] === 200 && (int) one('SELECT count(*) FROM signals WHERE id = :i', ['i' => $pva]) === 0, 'a manager deletes the anomaly (raised on data since corrected)');
$rid = (int) one("SELECT id FROM signal_rules WHERE key = 'price_volume_anomaly'");
$r = post_json($jM, '/rules/save.php', ['rule' => $rid, 'enabled' => 'no']);
ok($r['code'] === 200 && one('SELECT enabled FROM signal_rules WHERE id = :i', ['i' => $rid]) === false, 'rule_save: the rule switched off for every issuer');
$r = run_rules($jP, $s2);
ok($r['code'] === 200 && signal_of('price_volume_anomaly', $s2) === 0, 'the next run raises no anomaly');
post_json($jM, '/rules/save.php', ['rule' => $rid, 'enabled' => 'yes']);
run_rules($jP, $s2);
ok(signal_of('price_volume_anomaly', $s2) > 0, 'switched on again, the next run raises it');

echo "8. An issuer's own threshold changes that issuer's next run only\n";
$sof = signal_of('si_over_float', $sec);
post_json($jP, '/signals/dismiss.php', ['signal' => $sof, 'reason' => 'false_positive']);
$rule = (int) one("SELECT id FROM signal_rules WHERE key = 'si_over_float'");
$since = last_activity_id();
$r = post_json($jM, '/rules/override.php', ['rule' => $rule, 'issuer' => $sm['issuer'], 'params[dtc_high]' => '50', 'params[float_pct_high]' => '']);
$ov = q('SELECT params::text AS p, enabled FROM signal_rule_overrides WHERE rule_id = :r AND issuer_id = :i', ['r' => $rule, 'i' => $sm['issuer']])[0] ?? [];
ok($r['code'] === 200 && ($ov['p'] ?? '') === '{"dtc_high": 50}' && $ov['enabled'] === null, 'rule_override_save: SMOKE\'s days-to-cover for high is 50 (an empty threshold takes the rule\'s; the switch follows the rule)');
$o = json_decode(activity('rule.override', $since)[0]['after'] ?? '{}', true);
ok(($o['params']['dtc_high'] ?? null) === 50 && (int) activity('rule.override', $since)[0]['issuer_id'] === $sm['issuer'], 'rule.override logged with what changed and the issuer');
run_rules($jP, $sec);
ok((string) one("SELECT severity FROM signals WHERE security_id = :s AND rule_id = :r AND status = 'open'", ['s' => $sec, 'r' => $rule]) === 'watch', 'SMOKE\'s next run: 11.28 days to cover is watch now (under its own 50), not high');
ok((int) json_decode((string) one('SELECT si_rule_params(:k, :i)', ['k' => 'si_over_float', 'i' => $ids['issuer2']]), true)['dtc_high'] === 10, 'the second issuer keeps the rule\'s 10');
$j = json_decode(page($jM, '/rules?issuer=' . $sm['issuer'], ['headers' => ['Accept: application/json']])['body'], true)['data'] ?? [];
$row = array_values(array_filter($j['rules'] ?? [], static fn ($x) => $x['key'] === 'si_over_float'))[0] ?? [];
ok(($row['effective_params']['dtc_high'] ?? null) === 50 && ($row['params']['dtc_high'] ?? null) === 10 && ($row['override']['params']['dtc_high'] ?? null) === 50, 'the catalogue shows the rule\'s 10, SMOKE\'s 50 in effect');
$h = page($jM, '/rules?issuer=' . $sm['issuer'])['body'];
ok(str_contains($h, 'id="rule-row-si_over_float"') && str_contains($h, 'id="rule-override-si_over_float"') && substr_count($h, 'id="rule-row-') === 19, 'the rule list: nineteen rows, SMOKE\'s own marked');
$r = post_json($jM, '/rules/override.php', ['rule' => $rule, 'issuer' => $sm['issuer'], 'params[dtc_high]' => '', 'enabled' => '']);
ok($r['code'] === 200 && (int) one('SELECT count(*) FROM signal_rule_overrides WHERE rule_id = :r', ['r' => $rule]) === 0, 'an override emptied of thresholds and switch is removed');
$r = post_json($jM, '/rules/save.php', ['rule' => $rule, 'params' => '{"made_up": 3}']);
ok($r['code'] === 422 && str_contains($r['body'], 'no threshold'), 'a threshold the rule does not have is refused in words');
$r = post_json($jM, '/rules/save.php', ['rule' => $rule, 'params[dtc_watch]' => '-1']);
ok($r['code'] === 422, '…and a negative one');
ok(post_json($jP, '/rules/save.php', ['rule' => $rule, 'enabled' => 'no'])['code'] === 403, 'an Analyst may not change a rule (rules.manage)');

echo "9. The 06:45 run: market days only, from 06:45\n";
q("UPDATE si_settings SET market_holidays = '{2024-03-29,2024-04-08}'");
$w = worker('rules', '2024-04-08T07:00:00-04:00');
ok(($w['report']['ran']['rules']['daily'] ?? null) === false && ($w['report']['ran']['rules']['securities'] ?? -1) === 0, 'a holiday (Monday 2024-04-08 set as one): no 06:45 run');
$w = worker('rules', '2024-04-06T08:00:00-04:00');
ok(($w['report']['ran']['rules']['daily'] ?? null) === false, 'a Saturday: none either');
$w = worker('rules', '2024-04-09T06:30:00-04:00');
ok(($w['report']['ran']['rules']['daily'] ?? null) === false, 'a market day at 06:30: not yet');
$since = last_activity_id();
$w = worker('rules', '2024-04-09T06:50:00-04:00');
ok(($w['report']['ran']['rules']['daily'] ?? null) === true && ($w['report']['ran']['rules']['securities'] ?? 0) === 2 && (int) one("SELECT count(*) FROM rule_runs WHERE daily_on = '2024-04-09'") === 2,
    'a market day at 06:50: every security, once');
ok(count(activity('outcomes.update', $since)) === 1, 'and the outcomes after it (outcomes.update)');

echo "10. Expiry\n";
$since = last_activity_id();
$r = run_rules($jP, $sec, '2024-08-01');
$open = (int) one("SELECT count(*) FROM signals WHERE security_id = :s AND status = 'open'", ['s' => $sec]);
ok($r['code'] === 200 && ($r['json']['expired'] ?? 0) >= 8 && count(activity('signal.expire', $since)) === ($r['json']['expired'] ?? -1), 'a run months later expires the open signals past their window and grace, signal.expire each (' . ($r['json']['expired'] ?? '?') . ')');
$ex = signal_of('ftd_spike', $sec);
$b = page($jP, '/signals/' . $ex)['body'];
ok(str_contains($b, 'id="signal-disposition"') && str_contains($b, 'Expired') && !str_contains($b, 'id="signal-dispose"'), 'an expired signal stays readable, with no disposition form');
finish();
