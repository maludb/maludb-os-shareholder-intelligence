<?php
/**
 * Proof: the dispatch pass (agents-k7-api.md "The worker's passes", "Proof") — a high signal, a Schedule 13D, an upload that ties and the Friday pack each hand the Surveillance Analyst ONE chat turn on the (fake)
 * kernel's chat endpoint, as the agent, with the dispatch's words; the reply's first 200 characters are kept and never logged; a cancelled dispatch is skipped; a failed turn is retried after its wait, three
 * attempts in all; a refusal is not retried; a turn still running is asked again; one awaiting approval is left; a signal disposed meanwhile and an issuer the Analyst does not cover are cancelled with
 * their reason; the pack's day makes one dispatch an issuer.
 */
require __DIR__ . '/lib.php';
$ids = seed9();
$alpha = $ids['alpha'];
$sec = $ids['security'];
$jM = person(27);
$kernel = static function (array $s): void { kernel_state(function ($st) use ($s) { unset($st['chat'], $st['chat_status'], $st['chat_message']); return $s + $st; }); };
$kernel([]);
chat_reset();

echo "1. A high signal and a 13D: one chat turn each, as the agent\n";
$sd = dispatch_of_signal($ids['signal']);
$fd = q("SELECT * FROM agent_dispatches WHERE kind = 'filing' AND filing_id = :f", ['f' => $ids['filing_13d']])[0] ?? [];
ok(($sd['status'] ?? '') === 'sent' && (int) $sd['agent_member_id'] === 42 && ($fd['status'] ?? '') === 'sent', 'the database made both dispatches (the high signal, the Schedule 13D), status sent');
$since = last_activity_id();
$w = worker('dispatches', date('c'));
$rep = $w['report']['ran']['dispatches'] ?? [];
$log = chat_log();
ok($w['rc'] === 0 && ($rep['dispatched'] ?? 0) === 2 && ($rep['answered'] ?? 0) === 2 && count($log) === 2, 'the pass made two turns, both answered: ' . json_encode($rep));
$sig_turn = array_values(array_filter($log, static fn ($l) => str_contains((string) $l['utterance'], 'signal was raised')))[0] ?? [];
ok(($sig_turn['agent'] ?? '') === '42' && ($sig_turn['acting'] ?? '') === '42' && str_contains($sig_turn['utterance'], 'signal_propose') && str_contains($sig_turn['utterance'], 'SMOKE Alpha Corp') && str_contains($sig_turn['utterance'], 'short interest is 20.0% of the float')
    && ($sig_turn['context']['application'] ?? '') === 'shareholder_intelligence' && ($sig_turn['context']['issuer_id'] ?? 0) === $alpha && ($sig_turn['context']['entity'] ?? '') === 'signal',
    'the signal\'s turn: the agent is 42, acting as itself, the words name the issuer, the signal and signal_propose, the context carries the issuer');
$fil_turn = array_values(array_filter($log, static fn ($l) => str_contains((string) $l['utterance'], 'SC 13D')))[0] ?? [];
ok(str_contains($fil_turn['utterance'] ?? '', 'event_create') && ($fil_turn['context']['entity'] ?? '') === 'filing', 'the 13D\'s turn: the form, and event_create');
$sd = dispatch_of_signal($ids['signal']);
ok($sd['status'] === 'answered' && $sd['reply_excerpt'] === 'Hello from the fake expert' && (int) $sd['attempts'] === 1 && (int) $sd['run_id'] === 1 && $sd['request_id'] === 'si-dispatch-' . $sd['id'] && $sd['answered_at'] !== null, 'the row: answered, the excerpt kept, one attempt, the run, the conversation id');
ok(count(activity('agent.dispatch', $since)) === 2 && count(activity('agent.reply', $since)) === 2, 'agent.dispatch and agent.reply logged once a turn');
$all = (string) one("SELECT string_agg(coalesce(after::text, ''), ' ') FROM activity_log WHERE id > :s", ['s' => $since]);
ok(!str_contains($all, 'Hello from the fake expert') && !str_contains($all, 'signal_propose'), 'neither the reply nor the prompt is in any log row');
$w = worker('dispatches', date('c'));
ok(count(chat_log()) === 2 && ($w['report']['ran']['dispatches']['dispatched'] ?? -1) === 0, 'the pass again: no new turn');

echo "2. A cancelled dispatch is skipped; a disposed signal cancels its own\n";
$s2 = new_signal($sec, 'short_volume_ratio');
$s3 = new_signal($sec, 'borrow_fee');
$d2 = dispatch_of_signal($s2);
$d3 = dispatch_of_signal($s3);
$r = post_json($jM, '/agents/dispatches/cancel.php', ['dispatch' => $d2['id']]);
ok($r['code'] === 200 && (rid($r)) === (int) $d2['id'] && one('SELECT status FROM agent_dispatches WHERE id = :i', ['i' => $d2['id']]) === 'cancelled', 'a manager cancels a dispatch (record_id = the dispatch)');
ok(count(activity('dispatch.cancel', $since)) === 1, 'logged dispatch.cancel');
q("UPDATE signals SET status = 'dismissed', disposition_reason = 'false_positive', disposed_by = 27, disposed_at = now() WHERE id = :i", ['i' => $s3]);
chat_reset();
$w = worker('dispatches', date('c'));
$rep = $w['report']['ran']['dispatches'] ?? [];
$d3 = dispatch_of_signal($s3);
ok(count(chat_log()) === 0 && $d3['status'] === 'cancelled' && str_contains((string) $d3['detail'], 'dismissed already') && ($rep['cancelled'] ?? 0) === 1, 'no turn for either: the cancelled one is skipped, the disposed signal\'s dispatch is cancelled with its reason');
$r = post_json($jM, '/agents/dispatches/cancel.php', ['dispatch' => $sd['id']]);
ok($r['code'] === 422 && str_contains($r['body'], 'cannot be cancelled'), 'an answered dispatch cannot be cancelled (422, in words)');
$r = post_json(person(26), '/agents/dispatches/cancel.php', ['dispatch' => $d2['id']]);
ok($r['code'] === 403, 'an Analyst may not cancel (agents.settings): 403');
$r = agent_post(agent_headers(42, 960), '/agents/dispatches/cancel.php', ['dispatch' => $d2['id']]);
ok($r['code'] === 403, 'an agent may not cancel: it is a person\'s');

echo "3. A failed turn is retried after its wait — three attempts in all; a refusal is not retried\n";
$s4 = new_signal($sec, 'ftd_spike');
$d4 = dispatch_of_signal($s4);
$kernel(['chat_status' => 500]);
$since = last_activity_id();
$w = worker('dispatches', date('c'));
$d = q('SELECT * FROM agent_dispatches WHERE id = :i', ['i' => $d4['id']])[0];
ok($d['status'] === 'failed' && (int) $d['attempts'] === 1 && $d['next_attempt_at'] !== null && strtotime($d['next_attempt_at']) > time() + 240 && strtotime($d['next_attempt_at']) < time() + 330, 'the kernel down: failed, one attempt, the next in five minutes');
$w = worker('dispatches', date('c'));
ok(count(chat_log()) === 1 && ($w['report']['ran']['dispatches']['retried'] ?? -1) === 0, 'the pass again at once: the wait is not over, no new turn');
q('UPDATE agent_dispatches SET next_attempt_at = now() - interval \'1 minute\' WHERE id = :i', ['i' => $d4['id']]);
$w = worker('dispatches', date('c'));
$d = q('SELECT * FROM agent_dispatches WHERE id = :i', ['i' => $d4['id']])[0];
ok($d['status'] === 'failed' && (int) $d['attempts'] === 2 && strtotime($d['next_attempt_at']) > time() + 800 && ($w['report']['ran']['dispatches']['retried'] ?? 0) === 1, 'the second attempt fails too: attempts 2, the next in fifteen minutes');
q('UPDATE agent_dispatches SET next_attempt_at = now() - interval \'1 minute\' WHERE id = :i', ['i' => $d4['id']]);
worker('dispatches', date('c'));
$d = q('SELECT * FROM agent_dispatches WHERE id = :i', ['i' => $d4['id']])[0];
ok($d['status'] === 'failed' && (int) $d['attempts'] === 3 && $d['next_attempt_at'] === null && count(activity('agent.fail', $since)) === 1, 'the third fails for good: attempts 3, no next try, agent.fail logged once');
$n = count(chat_log());
worker('dispatches', date('c'));
ok(count(chat_log()) === $n, 'and no fourth turn is ever made');
$s5 = new_signal($sec, 'concentration_shift');
$d5 = dispatch_of_signal($s5);
$kernel(['chat_status' => 500]);
worker('dispatches', date('c'));
$kernel([]);
q('UPDATE agent_dispatches SET next_attempt_at = now() - interval \'1 minute\' WHERE id = :i', ['i' => $d5['id']]);
$w = worker('dispatches', date('c'));
$d = q('SELECT * FROM agent_dispatches WHERE id = :i', ['i' => $d5['id']])[0];
ok($d['status'] === 'answered' && (int) $d['attempts'] === 2 && $d['reply_excerpt'] === 'Hello from the fake expert' && ($w['report']['ran']['dispatches']['retried'] ?? 0) === 1, 'a turn that fails once and then answers: attempts 2, answered');
$s6 = new_signal($sec, 'nobo_drain');
$d6 = dispatch_of_signal($s6);
$kernel(['chat_status' => 403]);
worker('dispatches', date('c'));
$d = q('SELECT * FROM agent_dispatches WHERE id = :i', ['i' => $d6['id']])[0];
ok($d['status'] === 'refused' && $d['next_attempt_at'] === null && str_contains((string) $d['detail'], 'may not use the expert'), 'a refusal (403): refused, in the kernel\'s words, never retried');
$kernel(['chat_status' => 409]);
$s7 = new_signal($sec, 'register_untied');
worker('dispatches', date('c'));
ok(dispatch_of_signal($s7)['status'] === 'failed', 'a busy agent (409) is a failed turn to try again, not a refusal');

echo "4. A turn still running is asked again; one awaiting approval is left\n";
$kernel(['chat' => ['run_id' => 77, 'status' => 'running', 'finished' => false]]);
$s8 = new_signal($sec, 'spr_untied');
worker('dispatches', date('c'));
$d8 = dispatch_of_signal($s8);
ok($d8['status'] === 'sent' && (int) $d8['run_id'] === 77 && $d8['request_id'] !== null, 'a run the kernel has not finished: still sent, its run kept');
$kernel(['chat' => ['run_id' => 77, 'status' => 'succeeded', 'finished' => true, 'reply' => 'Done: I proposed a disposition.', 'actions' => []]]);
$w = worker('dispatches', date('c'));
$d8 = dispatch_of_signal($s8);
ok($d8['status'] === 'answered' && $d8['reply_excerpt'] === 'Done: I proposed a disposition.' && ($w['report']['ran']['dispatches']['polled'] ?? 0) >= 1, 'the next pass asked the run again (GET): answered');
$kernel(['chat' => ['run_id' => 78, 'status' => 'awaiting_approval', 'finished' => false]]);
$s9 = new_signal($sec, 'top_holder_exit');
worker('dispatches', date('c'));
$d9 = dispatch_of_signal($s9);
ok($d9['status'] === 'awaiting_approval' && $d9['answered_at'] !== null, 'a turn paused for a person\'s approval in the kernel: awaiting approval');
$kernel([]);

echo "5. The Analyst's issuers: a dispatch for an issuer it does not cover is cancelled\n";
$r = post_json($jM, '/agents/settings.php', ['analyst_issuers' => [$ids['gamma']]]);
ok($r['code'] === 200, 'the manager sets the Analyst\'s issuers to Gamma alone');
$s10 = new_signal($sec, 'holder_5pct');
chat_reset();
worker('dispatches', date('c'));
$d10 = dispatch_of_signal($s10);
ok(count(chat_log()) === 0 && $d10['status'] === 'cancelled' && str_contains((string) $d10['detail'], 'does not cover'), 'no turn: cancelled "The Analyst does not cover this issuer."');
post_json($jM, '/agents/settings.php', ['analyst_issuers' => ['']]);
ok(one('SELECT cardinality(analyst_issuer_ids) FROM si_settings') == 0, 'an empty list covers every issuer again');

echo "6. An upload that ties hands the Analyst a dispatch\n";
$cusip = (string) one('SELECT cusip FROM securities WHERE id = :s', ['s' => $sec]);
[$name, $bytes] = week_csv($cusip);
$jP = person(26);
$tmp = sys_get_temp_dir() . '/' . $name;
file_put_contents($tmp, $bytes);
$ch = curl_init(BASE . '/uploads/save.php');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jP, CURLOPT_COOKIEJAR => $jP, CURLOPT_TIMEOUT => 60,
    CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-CSRF-Token: ' . page_csrf($jP)], CURLOPT_POSTFIELDS => ['security' => $sec, 'kind' => 'spr', 'file' => new CURLFile($tmp, 'application/octet-stream', $name)]]);
$ub = (string) curl_exec($ch);
@unlink($tmp);
$uj = json_decode($ub, true) ?: [];
ok(($uj['tied'] ?? null) === true, 'an SPR uploaded through the form and tied' . (($uj['tied'] ?? null) === true ? '' : ': ' . substr($ub, 0, 200)));
$imp = (int) ($uj['record_id'] ?? 0);
$ud = q("SELECT * FROM agent_dispatches WHERE kind = 'upload_done' AND import_id = :i", ['i' => $imp]);
ok(count($ud) === 1 && $ud[0]['status'] === 'sent' && (int) $ud[0]['agent_member_id'] === 42, 'the upload made one upload_done dispatch');
chat_reset();
worker('dispatches', date('c'));
$ul = array_values(array_filter(chat_log(), static fn ($l) => str_contains((string) $l['utterance'], 'An upload')))[0] ?? [];
ok(str_contains($ul['utterance'] ?? '', 'ties to its own totals') && str_contains($ul['utterance'], 'holder_match_propose') && ($ul['context']['entity'] ?? '') === 'import', 'its turn: the tie, and the matches to propose');
ok(q("SELECT status FROM agent_dispatches WHERE id = :i", ['i' => $ud[0]['id']])[0]['status'] === 'answered', 'answered');

echo "7. The pack's day: one dispatch an issuer\n";
$fri = '2024-04-05T12:00:00Z';              // 08:00 in New York on a Friday (the settings\' pack_day is 5)
$thu = '2024-04-04T12:00:00Z';
$w = worker('packs', $thu);
ok(($w['report']['ran']['packs']['dispatched'] ?? -1) === 0, 'on a Thursday: none');
$before = (int) one("SELECT count(*) FROM agent_dispatches WHERE kind = 'pack'");
$w = worker('packs', $fri);
$issuersSeen = (int) one("SELECT count(*) FROM issuers i WHERE i.deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM issuer_members m WHERE m.issuer_id = i.id)") + (int) one("SELECT count(*) FROM issuers i WHERE EXISTS (SELECT 1 FROM issuer_members m WHERE m.issuer_id = i.id AND m.member_id = 42)");
ok(($w['report']['ran']['packs']['dispatched'] ?? -1) === $issuersSeen && $issuersSeen === 2, 'on the Friday: one for each issuer the Analyst can see (Alpha and Gamma; Beta\'s list does not name it) — ' . json_encode($w['report']['ran']['packs'] ?? null));
$w = worker('packs', $fri);
ok(($w['report']['ran']['packs']['dispatched'] ?? -1) === 0 && (int) one("SELECT count(*) FROM agent_dispatches WHERE kind = 'pack'") === $before + 2, 'the same Friday again: none (once an issuer a day)');
chat_reset();
worker('dispatches', date('c'));
$pk = array_values(array_filter(chat_log(), static fn ($l) => str_contains((string) $l['utterance'], 'weekly pack for SMOKE Alpha Corp')))[0] ?? [];
ok(str_contains($pk['utterance'] ?? '', 'report_draft') && str_contains($pk['utterance'], 'do not send it') && ($pk['context']['entity'] ?? '') === 'issuer', 'the Analyst is asked to draft Alpha\'s pack with report_draft, and not to send it');
q("UPDATE agent_dispatches SET created_at = created_at - interval '3 days' WHERE kind = 'pack'");
q("INSERT INTO reports (issuer_id, kind, title, period_from, period_to, drafted_by) VALUES (:i, 'weekly_pack', 'SMOKE weekly', '2024-03-30', '2024-04-05', 42)", ['i' => $alpha]);
$w = worker('packs', $fri);
ok(($w['report']['ran']['packs']['dispatched'] ?? -1) === 1, 'with a pack already drafted for the week, only Gamma gets a dispatch (a day later than the first, so the first is out of the once-a-day window)');
q("UPDATE si_settings SET pack_day = 3");
$w = worker('packs', $fri);
ok(($w['report']['ran']['packs']['dispatched'] ?? -1) === 0, 'and a different pack day changes the day');
q("UPDATE si_settings SET pack_day = 5");
finish();
