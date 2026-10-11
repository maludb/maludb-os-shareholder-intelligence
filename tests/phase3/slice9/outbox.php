<?php
/**
 * Proof: notifications and the outbox (agents-k7-api.md "The worker's passes", "Proof") — the Analyst sends a member a notice through the `notification` door (the bell, and the email or text their preferences ask for); it
 * never tells a recipient about an issuer they may not see, nor the sender about one they may not; the worker's outbox pass sends the email through MaluMail (the morning note in its own template) and the text through
 * the kernel (K6), retries a failure at 1, 5 and 30 minutes and then fails it, and skips what the provider refuses; nothing of an address, a phone or the words is in the trail.
 */
require __DIR__ . '/lib.php';
$ids = seed9();
$alpha = $ids['alpha'];
$beta = $ids['beta'];
$agent = agent_headers(42, 952);
$jP = person(26);
mail_reset();
sms_reset();
kernel_state(function ($s) { $s['sms'] = ['mode' => 'ok']; return $s; });
q('INSERT INTO notification_prefs (member_id, text_enabled) VALUES (26, true) ON CONFLICT (member_id) DO UPDATE SET text_enabled = true');
q("INSERT INTO notification_prefs (member_id) VALUES (31) ON CONFLICT DO NOTHING");

echo "1. The notification door\n";
$since = last_activity_id();
$body = "One high signal is open on SMKA.\nShort interest is 20.0% of the float (FINRA, settled 15 days ago).";
$r = agent_post($agent, '/agents/notify.php', ['recipient' => 26, 'title' => 'SMOKE: morning note — SMKA', 'body' => $body, 'kind' => 'morning_note', 'issuer' => $alpha]);
$nid = rid($r);
ok($r['code'] === 200 && $nid > 0, 'the Analyst notifies Priya (record_id = the notification)');
$n = q('SELECT * FROM notifications WHERE id = :i', ['i' => $nid])[0] ?? [];
ok(($n['kind'] ?? '') === 'morning_note' && (int) $n['member_id'] === 26 && (int) $n['issuer_id'] === $alpha && (int) $n['actor_member_id'] === 42 && $n['body'] === $body, 'the bell row: kind, recipient, issuer, the sender, the words');
$ob = q('SELECT * FROM notification_outbox WHERE record_id = :a AND kind = \'morning_note\' ORDER BY id', ['a' => $alpha]);
ok(count($ob) === 1 && $ob[0]['channel'] === 'email' && $ob[0]['to_email'] === 'priya@example.invalid' && $ob[0]['status'] === 'queued', 'and one email in the outbox (her preferences: email on, texts only for high signals)');
$a = activity('notification.send', $since)[0] ?? [];
$aj = json_decode($a['after'] ?? '{}', true);
ok(($a['source'] ?? '') === 'agent' && $aj == ['to' => 26, 'kind' => 'morning_note', 'issuer_id' => $alpha], 'notification.send logged by the agent: {to, kind, issuer_id} — never the words');
$r = agent_post($agent, '/agents/notify.php', ['recipient' => 40, 'title' => 'x', 'kind' => 'mention']);
ok($r['code'] === 422 && str_contains($r['body'], 'goes to a person'), 'a notice to an agent: refused (an agent is messaged through the kernel)');
$r = agent_post($agent, '/agents/notify.php', ['recipient' => 26, 'title' => 'x', 'kind' => 'signal_high']);
ok($r['code'] === 422, 'a kind that is not one of the four: refused');
$r = agent_post($agent, '/agents/notify.php', ['recipient' => 26, 'kind' => 'mention']);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['title']), 'no title: refused, the field named');
$r = agent_post($agent, '/agents/notify.php', ['recipient' => 26, 'title' => 'About Beta', 'issuer' => $beta]);
ok($r['code'] === 404, 'about an issuer the SENDER may not see (the agent is not on Beta\'s list): not found');
$jM = person(27);
$cnt = (int) one('SELECT count(*) FROM notifications WHERE member_id = 26');
$r = post_json($jM, '/agents/notify.php', ['recipient' => 26, 'title' => 'About Beta', 'issuer' => $beta]);
ok($r['code'] === 422 && str_contains($r['body'], 'may not see that issuer') && (int) one('SELECT count(*) FROM notifications WHERE member_id = 26') === $cnt, 'by a manager about Beta to Priya, who may not see it: nothing is sent (the database\'s wall)');
$r = post_json($jP, '/agents/notify.php', ['recipient' => 29, 'title' => 'Hello', 'kind' => 'mention']);
ok($r['code'] === 200, 'a person with signals.work may notify too');
$r = post_json(person(29), '/agents/notify.php', ['recipient' => 26, 'title' => 'Hello']);
ok($r['code'] === 403, 'a Viewer may not (signals.work): 403');

echo "2. The outbox: the email and the text\n";
q("SELECT si_notify(26, 'signal_high', 'High signal: SMOKE Alpha', 'Short interest is 20% of the float.', 'signal', :sg, :sg2, :a, NULL, 'sh:test')", ['sg' => $ids['signal'], 'sg2' => $ids['signal'], 'a' => $alpha]);
$since = last_activity_id();
$w = worker('outbox', date('c'));
$rep = $w['report']['ran']['outbox'] ?? [];
$mail = mail_log();
ok($w['rc'] === 0 && ($rep['sent'] ?? 0) >= 4 && ($rep['failed'] ?? 1) === 0, 'the pass sent what was queued: ' . json_encode($rep));
$morning = array_values(array_filter($mail, static fn ($m) => str_contains((string) $m['subject'], 'morning note')))[0] ?? [];
ok(($morning['to'] ?? '') === 'priya@example.invalid' && ($morning['from'] ?? '') === 'shareholders@example.invalid' && str_contains($morning['html'] ?? '', 'The morning note') && str_contains($morning['html'], 'Short interest is 20.0% of the float')
    && str_contains($morning['html'], '/issuers/' . $alpha) && str_contains($morning['text'] ?? '', 'One high signal is open'), 'the morning note went out in its own template, to Priya, with its words and a link to the issuer');
$high = array_values(array_filter($mail, static fn ($m) => str_contains((string) $m['subject'], 'High signal')))[0] ?? [];
ok(str_contains($high['html'] ?? '', '/signals/' . $ids['signal']) && !str_contains($high['html'], 'The morning note'), 'a high signal\'s email links to the signal and uses the plain template');
$sms = sms_log();
ok(count($sms) === 1 && (int) $sms[0]['member_id'] === 26 && str_contains($sms[0]['text'], 'High signal: SMOKE Alpha') && $sms[0]['accepted'] === true && str_starts_with($sms[0]['reference'], 'si-outbox-'), 'one text, for the high signal only, through the kernel (K6): her member, the title, a reference');
$st = q("SELECT channel, status, attempts, provider_ref, sent_at FROM notification_outbox WHERE member_id = 26 ORDER BY id");
ok(count($st) === 3 && count(array_filter($st, static fn ($r) => $r['status'] === 'sent' && $r['sent_at'] !== null && (int) $r['attempts'] === 1)) === 3 && array_values(array_filter($st, static fn ($r) => $r['channel'] === 'text'))[0]['provider_ref'] === 'kernel:9001',
    'her three rows (two emails, one text) are sent on the first attempt, with the time; the text keeps the kernel\'s notification reference');
$x = activity('outbox.sent', $since);
$all = (string) one("SELECT string_agg(coalesce(after::text, ''), ' ') FROM activity_log WHERE id > :s", ['s' => $since]);
ok(count($x) >= 4 && !str_contains($all, 'priya@') && !str_contains($all, 'Short interest') && !str_contains($all, 'High signal'), 'outbox.sent logged for each; no address, phone or word of a notice in the trail');
$n = count($mail);
$w = worker('outbox', date('c'));
ok(count(mail_log()) === $n && ($w['report']['ran']['outbox']['sent'] ?? -1) === 0, 'the pass again: nothing is sent twice');

echo "3. A failure is retried at 1, 5 and 30 minutes, then fails\n";
q("UPDATE members SET email = 'flaky@example.invalid' WHERE id = 31");
q("SELECT si_notify(31, 'mention', 'SMOKE: a mention for Lee', 'See the note.', 'issuer', :a, NULL, :a2, NULL, 'lee:1')", ['a' => $alpha, 'a2' => $alpha]);
$row = static fn (): array => q("SELECT id, status, attempts, detail, send_after - now() AS wait FROM notification_outbox WHERE member_id = 31 AND channel = 'email' ORDER BY id DESC LIMIT 1")[0];
$secs = static fn (string $iv): int => (int) one("SELECT extract(epoch FROM CAST(:i AS interval))::int", ['i' => $iv]);
$wait = [60, 300, 1800];
for ($try = 1; $try <= 3; $try++) {
    q("UPDATE notification_outbox SET send_after = now() - interval '1 second' WHERE id = :i", ['i' => $row()['id']]);
    $w = worker('outbox', date('c'));
    $r = $row();
    $s = $secs($r['wait']);
    ok($r['status'] === 'queued' && (int) $r['attempts'] === $try && $s > $wait[$try - 1] - 20 && $s <= $wait[$try - 1] && ($w['report']['ran']['outbox']['retried'] ?? 0) === 1, "attempt $try fails (502): queued again, the next in " . ($wait[$try - 1] / 60) . ' minute(s)');
}
$w = worker('outbox', date('c'));
ok($row()['status'] === 'queued' && ($w['report']['ran']['outbox']['retried'] ?? -1) === 0, 'not due yet: the pass leaves it');
$since = last_activity_id();
q("UPDATE notification_outbox SET send_after = now() - interval '1 second' WHERE id = :i", ['i' => $row()['id']]);
$w = worker('outbox', date('c'));
$r = $row();
ok($r['status'] === 'failed' && (int) $r['attempts'] === 4 && str_contains((string) $r['detail'], 'HTTP 502') && ($w['report']['ran']['outbox']['failed'] ?? 0) === 1 && count(activity('outbox.failed', $since)) === 1, 'the fourth fails for good: failed, attempts 4, outbox.failed logged');
q("UPDATE members SET email = 'suppressed@example.invalid' WHERE id = 31");
q("SELECT si_notify(31, 'mention', 'SMOKE: for a suppressed address', NULL, 'issuer', :a, NULL, :a2, NULL, 'lee:2')", ['a' => $alpha, 'a2' => $alpha]);
worker('outbox', date('c'));
$r = $row();
ok($r['status'] === 'skipped' && (int) $r['attempts'] === 1 && str_contains((string) $r['detail'], 'suppressed'), 'a suppressed address: skipped on the first try, with the reason — never retried');
q("UPDATE members SET email = 'rejected@example.invalid' WHERE id = 31");
q("SELECT si_notify(31, 'mention', 'SMOKE: for a rejected address', NULL, 'issuer', :a, NULL, :a2, NULL, 'lee:3')", ['a' => $alpha, 'a2' => $alpha]);
worker('outbox', date('c'));
ok($row()['status'] === 'skipped' && str_contains((string) $row()['detail'], 'invalid_address'), 'an address MaluMail rejects: skipped');

echo "4. Texts: the kernel decides\n";
q('UPDATE notification_prefs SET text_enabled = true WHERE member_id = 31');
$tx = static fn (): array => q("SELECT id, status, attempts, detail FROM notification_outbox WHERE member_id = 31 AND channel = 'text' ORDER BY id DESC LIMIT 1")[0];
kernel_state(function ($s) { $s['sms'] = ['mode' => 'ok', 'members' => ['31' => 'opted_out']]; return $s; });
q("SELECT si_notify(31, 'signal_high', 'SMOKE: high for Lee', 'x', 'issuer', :a, NULL, :a2, NULL, 'lee:t1')", ['a' => $alpha, 'a2' => $alpha]);
worker('outbox', date('c'));
ok($tx()['status'] === 'skipped' && $tx()['detail'] === 'opted_out', 'a member who opted out of texts: skipped, the kernel\'s code kept, no retry');
kernel_state(function ($s) { $s['sms'] = ['mode' => 'ok', 'members' => ['31' => 'server_error']]; return $s; });
q("SELECT si_notify(31, 'signal_high', 'SMOKE: high for Lee again', 'x', 'issuer', :a, NULL, :a2, NULL, 'lee:t2')", ['a' => $alpha, 'a2' => $alpha]);
worker('outbox', date('c'));
ok($tx()['status'] === 'queued' && (int) $tx()['attempts'] === 1, 'the kernel failing (500): queued again — a text is retried like an email');
kernel_state(function ($s) { $s['sms'] = ['mode' => 'ok']; return $s; });
q("UPDATE notification_outbox SET send_after = now() - interval '1 second' WHERE id = :i", ['i' => $tx()['id']]);
worker('outbox', date('c'));
ok($tx()['status'] === 'sent' && (int) $tx()['attempts'] === 2, 'and sent when the kernel is back: attempts 2');
q("UPDATE members SET status = 'inactive' WHERE id = 31");
q("INSERT INTO notification_outbox (channel, member_id, to_email, kind, subject, body) VALUES ('email', 31, 'x@example.invalid', 'mention', 'SMOKE: for someone gone', 'x')");
worker('outbox', date('c'));
ok($row()['status'] === 'skipped' && str_contains((string) $row()['detail'], 'no longer active'), 'a member no longer active here: skipped');
q("UPDATE members SET status = 'active' WHERE id = 31");
finish();
