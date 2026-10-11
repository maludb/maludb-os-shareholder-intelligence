<?php
/**
 * Proof: the outcomes (signals.md §6) — the worker's daily run records the close at each signal and 5, 20 and 60 trading days after as the prices
 * arrive (si_signal_outcomes_update, `outcomes.update`); a signal's page shows them; the rules' catalogue gives each rule's n, the mean 20-day return
 * and the share negative — and "too few to say" for a rule with fewer than thirty. Nothing here changes a rule.
 */
require __DIR__ . '/lib.php';
$ids = seed7();
$sec = $ids['security'];
[$jP, ] = sign_on(26);
page($jP, '/?issuer=' . $ids['issuer']);
run_rules($jP, $sec);

// prices after the signals: 70 market days from 2024-04-08, the close falling 0.4% a day; and a year of 2023 for thirty older signals of one rule
q("INSERT INTO prices (security_id, trade_date, close, volume) SELECT :s, d::date, round((4.00 * power(0.996, row_number() OVER (ORDER BY d)))::numeric, 4), 100000
     FROM generate_series('2024-04-08'::date, '2024-08-30'::date, interval '1 day') d WHERE extract(isodow FROM d) < 6", ['s' => $sec]);
q("INSERT INTO prices (security_id, trade_date, close, volume) SELECT :s, d::date, round((3.00 + 0.002 * row_number() OVER (ORDER BY d))::numeric, 4), 100000
     FROM generate_series('2023-01-02'::date, '2023-12-29'::date, interval '1 day') d WHERE extract(isodow FROM d) < 6", ['s' => $sec]);
q("INSERT INTO signals (rule_id, issuer_id, security_id, raised_on, window_from, window_to, severity, value, summary, status, expires_on)
   SELECT (SELECT id FROM signal_rules WHERE key = 'participant_jump'), :i, :s, d::date, d::date, d::date, 'info', 1.2, 'SMOKE: an older participant jump', 'expired', d::date + 10
     FROM generate_series('2023-01-09'::date, '2023-08-14'::date, interval '7 days') d", ['i' => $ids['issuer'], 's' => $sec]);
ok((int) one("SELECT count(*) FROM signals s JOIN signal_rules r ON r.id = s.rule_id WHERE r.key = 'participant_jump' AND s.status = 'expired'") === 32, 'thirty-two older participant_jump signals (expired) beside this run\'s');

echo "1. The worker's daily run records the price after\n";
$since = last_activity_id();
$w = worker('rules', '2024-07-16T07:00:00-04:00');
$rep = $w['report']['ran']['rules'] ?? [];
ok(($rep['daily'] ?? null) === true && ($rep['outcomes'] ?? 0) >= 49, 'the 06:45 run of 2024-07-16 updated the outcomes of every signal (' . ($rep['outcomes'] ?? '?') . ')');
$o = activity('outcomes.update', $since)[0] ?? [];
ok($o !== [] && $o['source'] === 'cron' && (int) json_decode($o['after'], true)['signals'] === ($rep['outcomes'] ?? -1), 'outcomes.update logged with the count');
$sof = signal_of('si_over_float', $sec);
$oc = q('SELECT * FROM signal_outcomes WHERE signal_id = :i', ['i' => $sof])[0] ?? [];
$p0 = (float) one("SELECT close FROM prices WHERE security_id = :s AND trade_date = '2024-04-04'", ['s' => $sec]);
$p5 = (float) one("SELECT close FROM prices WHERE security_id = :s AND trade_date = '2024-04-12'", ['s' => $sec]);
ok((float) ($oc['price_at'] ?? 0) === $p0 && (float) ($oc['price_5d'] ?? 0) === $p5 && $oc['price_20d'] !== null && $oc['price_60d'] !== null,
    'a signal of 2024-04-05: the close at it (Apr 4\'s, the last before) and 5 (Apr 12), 20 and 60 trading days after');
ok(abs((float) $oc['return_5d'] - round(($p5 - $p0) / $p0, 4)) < 0.00001, 'the 5-day return is the database\'s: (price after − price at) / price at');
$b = page($jP, '/signals/' . $sof)['body'];
ok(str_contains($b, 'id="signal-outcome"') && str_contains($b, number_format($p0, 2)) && preg_match('~class="text-(danger|success)">[+-]\d+\.\d%~', $b) === 1 && !str_contains($b, 'id="signal-outcome-none"'), 'the signal\'s page shows the closes and the returns');
q("INSERT INTO signals (rule_id, issuer_id, security_id, raised_on, window_from, window_to, severity, summary, status, expires_on) VALUES ((SELECT id FROM signal_rules WHERE key = 'holder_5pct'), :i, :s, '2020-01-06', '2020-01-06', '2020-01-06', 'info', 'SMOKE: no price then', 'expired', '2020-01-16')", ['i' => $ids['issuer'], 's' => $sec]);
ok(str_contains(page($jP, '/signals/' . (int) one("SELECT id FROM signals WHERE summary = 'SMOKE: no price then'"))['body'], 'id="signal-outcome-none"'), 'a signal with no close recorded says so');

echo "2. By rule: n, the mean, the share negative — and too few to say\n";
$d = json_decode(page($jP, '/rules', ['headers' => ['Accept: application/json']])['body'], true)['data'] ?? [];
$by = array_column(array_map(static fn ($r) => $r['outcome'] + ['key' => $r['key']], $d['rules'] ?? []), null, 'key');
$pj = $by['participant_jump'] ?? [];
ok(($pj['n'] ?? 0) >= 30 && ($pj['too_few_to_say'] ?? true) === false && $pj['mean_return_20d_pct'] !== null && $pj['share_negative_20d_pct'] !== null, 'participant_jump: n = ' . ($pj['n'] ?? '?') . ', a mean 20-day return and the share negative');
$few = array_filter($by, static fn ($r) => $r['key'] !== 'participant_jump');
ok(count($few) === 18 && array_filter($few, static fn ($r) => $r['too_few_to_say'] !== true) === [], 'the other eighteen: too few to say');
$exp = q("SELECT round(avg(o.return_20d) * 100, 2) AS m, round(100.0 * count(*) FILTER (WHERE o.return_20d < 0) / count(o.return_20d), 1) AS neg, count(o.return_20d) AS n FROM signal_outcomes o JOIN signals s ON s.id = o.signal_id JOIN signal_rules r ON r.id = s.rule_id WHERE r.key = 'participant_jump' AND o.return_20d IS NOT NULL")[0];
ok((float) $pj['mean_return_20d_pct'] === (float) $exp['m'] && (float) $pj['share_negative_20d_pct'] === (float) $exp['neg'] && $pj['n'] === (int) $exp['n'], 'the figures are the outcomes\' own: mean ' . $exp['m'] . '%, ' . $exp['neg'] . '% negative, n = ' . $exp['n']);
$h = page($jP, '/rules')['body'];
ok(preg_match('~id="rule-outcome-participant_jump">[^<]*[+-]\d+\.\d\d%~', $h) === 1 && preg_match('~id="rule-outcome-si_over_float"><span class="text-muted">too few to say</span> <span class="text-muted">\(n = 1\)</span>~', $h) === 1,
    'the rule list prints the figure with its n, and "too few to say (n = 1)" for a rule with one outcome');
ok((int) one('SELECT count(*) FROM signal_rules WHERE enabled') === 19 && one("SELECT params::text FROM signal_rules WHERE key = 'participant_jump'") === '{"new_pct": 0.5, "move_pct": 1}', 'nothing here changed a rule');
finish();
