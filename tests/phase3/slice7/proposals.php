<?php
/**
 * Proof: an agent's proposal (signals.md §4, §8) — the Surveillance Analyst proposes through its run token (the reason, the explaining event, one
 * sentence) and the signal stays open; the card and the page show "<agent> proposes: dismiss — explained by an event — <the event>"; a person takes
 * it in one click (the matching disposition with the proposal's words) or not ("Not this" opens the form); the history counts the proposals taken
 * and not taken; the Analyst's queue (si_analyst_proposals) lists the waiting ones; a Viewer reads the proposal and has no Take it.
 */
require __DIR__ . '/lib.php';
$ids = seed7();
$sec = $ids['security'];
[$jP, ] = sign_on(26);
[$jV, ] = sign_on(29);
foreach ([$jP, $jV] as $j) { page($j, '/?issuer=' . $ids['issuer']); }
run_rules($jP, $sec);
kernel_state(function ($s) { $s['facts']['941'] = ['valid' => true, 'member_id' => 42, 'run_id' => 941, 'request_id' => 'req-941', 'is_eval' => false]; return $s; });
$rt = as_agent(run_token(42, 941));
$agent = static fn (string $path, array $form): array => req('POST', $path, ['headers' => $rt, 'form' => $form]);

echo "1. The agent proposes\n";
$th = signal_of('threshold_list', $sec);
$r = $agent('/signals/propose.php', ['signal' => $th, 'reason' => 'explained_by_event']);
ok($r['code'] === 422 && str_contains($r['body'], 'names the event'), 'explained_by_event without the event: refused');
$r = $agent('/signals/propose.php', ['signal' => $th, 'reason' => 'dismiss_it']);
ok($r['code'] === 422, 'a reason that is not one: refused');
$since = last_activity_id();
$r = $agent('/signals/propose.php', ['signal' => $th, 'reason' => 'explained_by_event', 'explaining_event' => $ids['ev_short'], 'note' => 'The short report of Apr 3 drove the fails that put SMOK on the list.']);
$row = q('SELECT status, proposal_by, proposal_reason, proposal_event_id, proposal_note FROM signals WHERE id = :i', ['i' => $th])[0];
ok($r['code'] === 200 && $row['status'] === 'open' && (int) $row['proposal_by'] === 42 && $row['proposal_reason'] === 'explained_by_event' && (int) $row['proposal_event_id'] === $ids['ev_short'], 'the proposal is on the signal; the signal stays open');
$a = activity('signal.propose', $since)[0] ?? [];
ok($a !== [] && $a['source'] === 'agent' && (int) $a['actor_member_id'] === 42 && (int) $a['agent_run_id'] === 941 && json_decode($a['after'], true)['reason'] === 'explained_by_event', 'signal.propose logged as the agent, under its run');
pdo()->exec("SELECT set_config('app.member_id', '26', false)");
$q = q("SELECT * FROM si_analyst_proposals(:i, 'signal')", ['i' => $ids['issuer']]);
ok(count($q) === 1 && (int) $q[0]['record_id'] === $th && $q[0]['reason'] === 'explained_by_event', 'the Analyst\'s queue lists the waiting proposal');

echo "2. The card and the page show it; Take it is one click\n";
$h = page($jP, '/signals')['body'];
ok(str_contains($h, 'id="signal-proposal-' . $th . '"') && str_contains($h, 'SMOKE Surveillance Analyst proposes:</span> dismiss — explained by an event — SMOKE Short Research') && str_contains($h, 'id="signal-take-' . $th . '-' . $th . '"'),
    'the card: "SMOKE Surveillance Analyst proposes: dismiss — explained by an event — the report", with Take it');
ok(str_contains($h, 'href="/signals/' . $th . '#signal-dispose"') && str_contains($h, 'id="signal-not-this-' . $th . '-' . $th . '"'), '…and Not this, opening the disposition form');
$hv = page($jV, '/signals')['body'];
ok(str_contains($hv, 'id="signal-proposal-' . $th . '"') && !str_contains($hv, 'id="signal-take-'), 'a Viewer reads the proposal and has no Take it');
$b = page($jP, '/signals/' . $th)['body'];
$f = form_of($b, '/signals/dismiss.php', 'signal-take-');
ok($f !== null && $f['reason'] === 'explained_by_event' && (int) $f['explaining_event'] === $ids['ev_short'] && str_starts_with($f['note'], 'The short report of Apr 3'), 'the page\'s Take it posts dismiss with the proposal\'s reason, event and sentence');
$since = last_activity_id();
$r = post_json($jP, '/signals/dismiss.php', $f);
$row = q('SELECT status, disposition_reason, event_id, disposed_by, disposition_note FROM signals WHERE id = :i', ['i' => $th])[0];
ok($r['code'] === 200 && $row['status'] === 'dismissed' && (int) $row['event_id'] === $ids['ev_short'] && (int) $row['disposed_by'] === 26 && str_starts_with((string) $row['disposition_note'], 'The short report'), 'taken: dismissed by the Analyst in one click, in the proposal\'s words');
ok(json_decode(activity('signal.dismiss', $since)[0]['after'] ?? '{}', true)['proposal'] === 'accepted', 'signal.dismiss records that the proposal was taken');

echo "3. A proposal not taken\n";
$sc = signal_of('si_change', $sec);
ok($agent('/signals/propose.php', ['signal' => $sc, 'reason' => 'escalate', 'note' => 'A jump this size before a raise deserves the IR Manager.'])['code'] === 200, 'the agent proposes to escalate si_change');
$b = page($jP, '/signals/' . $sc)['body'];
$f = form_of($b, '/signals/escalate.php', 'signal-take-');
ok($f !== null && !isset($f['reason']) && str_starts_with($f['note'], 'A jump this size'), 'its Take it would post escalate with the sentence');
$since = last_activity_id();
$r = post_json($jP, '/signals/acknowledge.php', ['signal' => $sc, 'reason' => 'known', 'note' => 'volume explains it']);
ok($r['code'] === 200 && json_decode(activity('signal.acknowledge', $since)[0]['after'] ?? '{}', true)['proposal'] === 'rejected', 'the Analyst acknowledges instead: the proposal not taken, and the log says so');
$bf = signal_of('borrow_fee', $sec);
$agent('/signals/propose.php', ['signal' => $bf, 'reason' => 'watching', 'note' => 'An indicative quote; watch for the next.']);
ok($agent('/signals/propose.php', ['signal' => $th, 'reason' => 'known'])['code'] === 422, 'a proposal on a disposed signal: refused');

echo "4. The history counts them\n";
$d = json_decode(page($jP, '/signals/history?from=2024-04-01&to=2024-04-30', ['headers' => ['Accept: application/json']])['body'], true)['data'] ?? [];
ok(($d['proposals'] ?? []) === ['accepted' => 1, 'rejected' => 1, 'waiting' => 1, 'lapsed' => 0] && ($d['total'] ?? 0) === 17, 'the history of April: 17 signals; the proposals 1 taken, 1 not taken, 1 waiting');
$dis = array_values(array_filter($d['signals'] ?? [], static fn ($s) => $s['signal_id'] === $th))[0] ?? [];
ok(($dis['disposition']['reason'] ?? '') === 'explained_by_event' && ($dis['disposition']['by_name'] ?? '') === 'SMOKE Priya' && ($dis['proposal']['fate'] ?? '') === 'accepted' && ($dis['proposal']['by_name'] ?? '') === 'SMOKE Surveillance Analyst',
    'a row: the disposition, who and why, the proposal and its fate');
$h = page($jP, '/signals/history?from=2024-04-01&to=2024-04-30')['body'];
ok(str_contains($h, 'id="history-row-' . $th . '"') && str_contains($h, '>taken<') && str_contains($h, '>not taken<') && str_contains($h, 'id="history-proposals-accepted"'), 'the history table shows taken and not taken');
$d2 = json_decode(page($jP, '/signals/history?from=2024-04-01&to=2024-04-30&status=dismissed', ['headers' => ['Accept: application/json']])['body'], true)['data'] ?? [];
ok(($d2['total'] ?? 0) === 1, 'filtered by status: the one dismissed');
finish();
