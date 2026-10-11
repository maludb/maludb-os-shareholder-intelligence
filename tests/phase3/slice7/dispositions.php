<?php
/**
 * Proof: a person's disposition (signals.md §4, §8) — acknowledge, dismiss with a reason (explained_by_event needs an event of the issuer around the
 * window, chosen with the picker over exactly those; another reason says itself in the note), escalate (the IR Managers told, the one escalating not);
 * a disposed signal is no longer disposed; an agent's dispose call is refused (people only); a note logs its length; a Viewer sees every signal and
 * disposes of none; a signal of an issuer the member may not see does not exist to them; a manager deletes one with its reason.
 */
require __DIR__ . '/lib.php';
$ids = seed7();
$sec = $ids['security'];
[$jP, ] = sign_on(26);
[$jM, ] = sign_on(27);
[$jV, ] = sign_on(29);
foreach ([$jP, $jM, $jV] as $j) { page($j, '/?issuer=' . $ids['issuer']); }
run_rules($jP, $sec);

echo "1. Dismiss: the reason, and the explaining event from the window\n";
$th = signal_of('threshold_list', $sec);
$r = post_json($jP, '/signals/dismiss.php', ['signal' => $th, 'reason' => 'explained_by_event']);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['explaining_event']) || ($r['code'] === 422 && str_contains($r['body'], 'names the event')), 'explained_by_event without the event: 422 on the field');
$far = (int) q("INSERT INTO events (issuer_id, security_id, on_date, kind, title, severity, source, created_by) VALUES (:i, :s, '2023-01-10', 'other', 'SMOKE an old event', 'info', 'person', 26) RETURNING id", ['i' => $ids['issuer'], 's' => $sec])[0]['id'];
$r = post_json($jP, '/signals/dismiss.php', ['signal' => $th, 'reason' => 'explained_by_event', 'explaining_event' => $far]);
ok($r['code'] === 422 && str_contains($r['body'], 'around the signal'), 'an event far from the window: refused in words');
$pick = req('GET', '/pick/?source=event&signal=' . $th, ['jar' => $jP])['body'];
ok(str_contains($pick, 'SMOKE Short Research') && !str_contains($pick, 'SMOKE an old event'), 'the picker offers the issuer\'s events around the window, not the old one');
$r = post_json($jP, '/signals/dismiss.php', ['signal' => $th, 'reason' => 'other']);
ok($r['code'] === 422 && str_contains($r['body'], 'note'), 'another reason with no note: refused');
$since = last_activity_id();
$r = post_json($jP, '/signals/dismiss.php', ['signal' => $th, 'reason' => 'explained_by_event', 'explaining_event' => $ids['ev_short'], 'note' => 'the short report drove the fails']);
$row = q('SELECT status, disposition_reason, event_id, disposed_by, disposition_note FROM signals WHERE id = :i', ['i' => $th])[0];
ok($r['code'] === 200 && $row['status'] === 'dismissed' && $row['disposition_reason'] === 'explained_by_event' && (int) $row['event_id'] === $ids['ev_short'] && (int) $row['disposed_by'] === 26 && (int) ($r['json']['record_id'] ?? 0) === $th,
    'dismissed by the Analyst with the reason and the explaining event (record_id the signal)');
$a = activity('signal.dismiss', $since)[0] ?? [];
$aa = json_decode($a['after'] ?? '{}', true);
ok((int) ($a['signal_id'] ?? 0) === $th && $aa['reason'] === 'explained_by_event' && (int) $aa['event_id'] === $ids['ev_short'] && !str_contains((string) $a['after'], 'drove the fails'), 'signal.dismiss logged with the reason and the event — the note\'s words are not in the trail');
ok(post_json($jP, '/signals/dismiss.php', ['signal' => $th, 'reason' => 'false_positive'])['code'] === 422, 'a dismissed signal is not dismissed again');
$b = page($jP, '/signals/' . $th)['body'];
ok(str_contains($b, 'id="signal-disposition"') && str_contains($b, 'id="signal-disposition-event"') && !str_contains($b, 'id="signal-dispose"'), 'its page shows the disposition and the event, and no form');

echo "2. Acknowledge\n";
$bf = signal_of('borrow_fee', $sec);
ok(post_json($jP, '/signals/acknowledge.php', ['signal' => $bf, 'reason' => 'ignored'])['code'] === 422, 'acknowledge is known or watching');
$r = post_json($jP, '/signals/acknowledge.php', ['signal' => $bf, 'reason' => 'watching']);
ok($r['code'] === 200 && one('SELECT status FROM signals WHERE id = :i', ['i' => $bf]) === 'acknowledged' && one('SELECT disposition_reason FROM signals WHERE id = :i', ['i' => $bf]) === 'watching', 'acknowledged, watching');

echo "3. Escalate: the IR Managers told\n";
$nd = signal_of('new_13d', $sec);
$before = (int) one("SELECT count(*) FROM notifications WHERE member_id = 27 AND title LIKE 'Escalated by%'");
$r = post_json($jP, '/signals/escalate.php', ['signal' => $nd, 'note' => 'counsel to read item 4']);
ok($r['code'] === 200 && one('SELECT status FROM signals WHERE id = :i', ['i' => $nd]) === 'escalated', 'escalated');
ok((int) one("SELECT count(*) FROM notifications WHERE member_id = 27 AND title LIKE 'Escalated by SMOKE Priya%' AND signal_id = :i AND body = 'counsel to read item 4'", ['i' => $nd]) === 1 && (int) one("SELECT count(*) FROM notifications WHERE member_id = 26 AND title LIKE 'Escalated%'") === 0,
    'the IR Manager (also the escalation member) told once with the note; the Analyst who escalated is not');
ok(post_json($jP, '/signals/escalate.php', ['signal' => $nd])['code'] === 422, 'an escalated signal is not escalated again');
$b = page($jM, '/signals/' . $nd)['body'];
ok(str_contains($b, 'id="signal-escalated-note"') && str_contains($b, 'id="signal-dispose"') && !str_contains($b, 'id="signal-escalate-form"'), 'the IR Manager reads the escalation and may still acknowledge or dismiss it');
$r = post_json($jM, '/signals/acknowledge.php', ['signal' => $nd, 'reason' => 'known', 'note' => 'counsel read it']);
ok($r['code'] === 200 && one('SELECT status FROM signals WHERE id = :i', ['i' => $nd]) === 'acknowledged', 'the IR Manager acknowledges the escalated signal');

echo "4. People only: an agent cannot dispose\n";
kernel_state(function ($s) { $s['facts']['931'] = ['valid' => true, 'member_id' => 42, 'run_id' => 931, 'request_id' => 'req-931', 'is_eval' => false]; return $s; });
$rt = as_agent(run_token(42, 931));
$sof = signal_of('si_over_float', $sec);
foreach (['acknowledge' => ['reason' => 'known'], 'dismiss' => ['reason' => 'false_positive'], 'escalate' => []] as $verb => $f) {
    $r = req('POST', '/signals/' . $verb . '.php', ['headers' => $rt, 'form' => ['signal' => $sof] + $f]);
    ok($r['code'] === 403 && str_contains($r['body'], 'for people'), "an agent's signal_$verb: 403 — people only");
}
ok(one('SELECT status FROM signals WHERE id = :i', ['i' => $sof]) === 'open', 'the signal is still open');

echo "5. A note\n";
$since = last_activity_id();
$r = post_json($jP, '/signals/note.php', ['signal' => $sof, 'body' => 'SMOKE: the float figure is the transfer agent\'s']);
$n = activity('signal.note', $since)[0] ?? [];
ok($r['code'] === 200 && (int) one("SELECT count(*) FROM notes WHERE record_type = 'signal' AND record_id = :i", ['i' => $sof]) === 1 && json_decode($n['after'] ?? '{}', true)['length'] === 47 && !str_contains((string) $n['after'], 'transfer agent'),
    'signal_note: the note kept, the log carries its length only');
ok(str_contains(page($jV, '/signals/' . $sof)['body'], 'the float figure is the transfer agent'), 'the note is on the signal\'s page');

echo "6. A Viewer sees every signal and disposes of none\n";
$h = page($jV, '/signals')['body'];
ok(substr_count($h, 'id="signal-card-') === (int) one("SELECT count(*) FROM signals WHERE security_id = :s AND status IN ('open', 'escalated')", ['s' => $sec]) && !str_contains($h, 'id="signals-run-btn"'), 'the Viewer\'s list: every live signal, no Run button');
$b = page($jV, '/signals/' . $sof)['body'];
ok(str_contains($b, 'id="signal-evidence-table"') && !str_contains($b, 'id="signal-dispose"') && !str_contains($b, 'id="signal-delete-form"') && !str_contains($b, 'id="signal-note-form"'), 'the Viewer\'s signal page: the evidence, no disposition, no delete, no note form');
ok(post_json($jV, '/signals/acknowledge.php', ['signal' => $sof, 'reason' => 'known'])['code'] === 403 && post_json($jV, '/signals/run.php', ['security' => $sec])['code'] === 403, 'a Viewer\'s acknowledge and run: 403');
ok(page($jV, '/signals/history')['code'] === 200 && page($jV, '/rules')['code'] === 200 && page($jV, '/rules/1/edit')['code'] === 403, 'a Viewer reads the history and the rules; the rule form is a manager\'s');

echo "7. The wall\n";
q('INSERT INTO issuer_members (issuer_id, member_id, added_by) VALUES (:i, 30, 27)', ['i' => $ids['issuer2']]);
$hidden = signal_of('spr_untied', $ids['security2']);
if ($hidden === 0) { run_rules($jM, $ids['security2']); $hidden = signal_of('spr_untied', $ids['security2']); }
ok($hidden > 0 && page($jP, '/signals/' . $hidden)['code'] === 404 && post_json($jP, '/signals/acknowledge.php', ['signal' => $hidden, 'reason' => 'known'])['code'] === 404,
    'the second issuer restricted to Dana: its signal is a 404 to the Analyst, on the page and the action');
ok(!str_contains(req('GET', '/pick/?source=signal', ['jar' => $jP])['body'], 'SPR does not tie') && post_json($jP, '/signals/run.php', ['security' => $ids['security2']])['code'] === 404, 'the signal picker leaves it out; rules_run on its security: 404');

echo "8. A manager deletes a signal raised on data since corrected\n";
$sv = signal_of('short_volume_ratio', $sec);
ok(post_json($jP, '/signals/delete.php', ['signal' => $sv, 'reason' => 'x'])['code'] === 403, 'an Analyst may not delete (rules.manage)');
ok(post_json($jM, '/signals/delete.php', ['signal' => $sv])['code'] === 422, 'a deletion without its reason: refused');
$since = last_activity_id();
$r = post_json($jM, '/signals/delete.php', ['signal' => $sv, 'reason' => 'FINRA re-sent the March files']);
$d = activity('signal.delete', $since)[0] ?? [];
ok($r['code'] === 200 && (int) one('SELECT count(*) FROM signals WHERE id = :i', ['i' => $sv]) === 0 && json_decode($d['after'] ?? '{}', true)['reason'] === 'FINRA re-sent the March files'
    && json_decode($d['before'] ?? '{}', true)['rule'] === 'short_volume_ratio', 'deleted; signal.delete keeps what it was and the reason');
ok(page($jM, '/signals/' . $sv)['code'] === 404 && (int) one("SELECT count(*) FROM search_index WHERE entity_kind = 'signal' AND entity_id = :i", ['i' => $sv]) === 0, 'its page is a 404, its search row gone');
$ap = array_column(json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json'), true)['approvals'] ?? [], 'category', 'action');
ok(($ap['signal_delete'] ?? '') === 'deletion', 'an agent\'s signal_delete pauses for approval (deletion)');
finish();
