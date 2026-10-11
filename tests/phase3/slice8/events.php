<?php
/**
 * Proof: events (events-reports.md) — a person records an event (the date, kind, title, Markdown body, link, severity, a filing chosen through the picker, tags), changes
 * one (a field left out stays), deletes it to the tombstone and restores it; an agent may record one but its delete pauses (the registry); a filing of a watched form makes its own
 * event and the timeline links the filing; the timeline groups by month and filters; the page shows the signals an event explains; a Viewer reads and writes nothing; an issuer behind
 * the wall has no events for those off its list; the body's Markdown is escaped; the log carries ids and kinds, never the body.
 */
require __DIR__ . '/lib.php';
$ids = seed8();
$alpha = $ids['alpha'];
$sec = $ids['security'];
$jP = person(26);
$jM = person(27);
$jV = person(29);
$jD = sign_on(30)[0];

echo "1. Record an event\n";
$since = last_activity_id();
$r = post_json($jP, '/events/save.php', ['issuer' => $alpha, 'on_date' => '2024-04-03', 'kind' => 'press', 'title' => 'SMOKE: a press release names a financing', 'severity' => 'watch',
    'body' => "The company **announced** a facility.\n\n- ATM with a sales agent\n- see [the release](https://example.invalid/pr) <script>alert(1)</script>", 'url' => 'https://example.invalid/pr', 'tags' => 'financing, smoke tag', 'security' => $sec]);
$e1 = rid($r);
$row = q('SELECT * FROM events WHERE id = :i', ['i' => $e1])[0] ?? [];
ok($r['code'] === 200 && $e1 > 0 && ($row['kind'] ?? '') === 'press' && $row['severity'] === 'watch' && $row['source'] === 'person' && (int) $row['created_by'] === 26 && (int) $row['security_id'] === $sec && str_contains($r['json']['location'] ?? '', '/events/' . $e1),
    'event_create: the event kept, source person, created by Priya, record_id the event, the location its page');
$a = activity('event.create', $since)[0] ?? [];
$aa = json_decode($a['after'] ?? '{}', true);
ok((int) ($a['entity_id'] ?? 0) === $e1 && (int) $a['issuer_id'] === $alpha && $aa['kind'] === 'press' && $aa['severity'] === 'watch' && $aa['on_date'] === '2024-04-03' && !str_contains((string) $a['after'], 'ATM with a sales agent'),
    'event.create logged with the kind, the severity and the date — the body\'s words are not in the trail');
ok(q('SELECT array_agg(t.label ORDER BY t.label) AS l FROM record_tags rt JOIN tags t ON t.id = rt.tag_id WHERE rt.record_type = \'event\' AND rt.record_id = :i', ['i' => $e1])[0]['l'] === '{financing,"smoke tag"}', 'the tags were set from the comma list');
$p = page($jP, '/events/' . $e1)['body'];
ok(str_contains($p, 'id="event-title"') && str_contains($p, '<strong>announced</strong>') && str_contains($p, '<li>ATM with a sales agent</li>') && str_contains($p, 'href="https://example.invalid/pr"') && !str_contains($p, '<script>alert(1)</script>') && str_contains($p, '&lt;script&gt;'),
    'its page renders the Markdown (bold, a list, a link) and escapes the script');
ok(str_contains($p, 'id="event-edit-link"') && str_contains($p, 'id="event-delete-btn"') && str_contains($p, 'id="event-tags"'), 'the page offers Edit, Delete and the tags');

echo "2. Validation — in words, on the field\n";
$bad = [
    'no date' => [['issuer' => $alpha, 'kind' => 'press', 'title' => 'x'], 'on_date'],
    'no title' => [['issuer' => $alpha, 'on_date' => '2024-04-03', 'kind' => 'press', 'title' => ''], 'title'],
    'a kind not on the list' => [['issuer' => $alpha, 'on_date' => '2024-04-03', 'kind' => 'gossip', 'title' => 'x'], 'kind'],
    'a severity not on the list' => [['issuer' => $alpha, 'on_date' => '2024-04-03', 'kind' => 'press', 'title' => 'x', 'severity' => 'urgent'], 'severity'],
    'a link that is not a web address' => [['issuer' => $alpha, 'on_date' => '2024-04-03', 'kind' => 'press', 'title' => 'x', 'url' => 'javascript:alert(1)'], 'url'],
    'a security of another issuer' => [['issuer' => $alpha, 'on_date' => '2024-04-03', 'kind' => 'press', 'title' => 'x', 'security' => (int) one('SELECT id FROM securities WHERE issuer_id = :i', ['i' => $ids['beta']])], 'security'],
    'a filing that is not there' => [['issuer' => $alpha, 'on_date' => '2024-04-03', 'kind' => 'press', 'title' => 'x', 'filing' => 999999], 'filing'],
];
$wrong = [];
foreach ($bad as $label => [$form, $field]) {
    $r = post_json($jP, '/events/save.php', $form);
    if (!($r['code'] === 422 && isset($r['json']['error']['fields'][$field]))) { $wrong[] = "$label → {$r['code']} " . substr($r['body'], 0, 100); }
}
ok($wrong === [], 'seven refusals: 422 with the sentence on the field' . ($wrong ? ': ' . implode(' | ', $wrong) : ''));
$r = post_json($jP, '/events/save.php', ['issuer' => $alpha, 'on_date' => '2024-04-03', 'kind' => 'press', 'title' => str_repeat('x', 301)]);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['title']), 'a title of 301 characters: refused');

echo "3. Change an event — a field left out stays\n";
$since = last_activity_id();
$r = post_json($jP, '/events/save.php', ['event' => $e1, 'severity' => 'high']);
$row = q('SELECT * FROM events WHERE id = :i', ['i' => $e1])[0];
ok($r['code'] === 200 && rid($r) === $e1 && $row['severity'] === 'high' && $row['title'] === 'SMOKE: a press release names a financing' && $row['kind'] === 'press' && str_contains((string) $row['body'], 'ATM') && $row['url'] === 'https://example.invalid/pr',
    'event_update with the severity alone: the severity changed, the title, kind, body and link as they were');
$a = activity('event.update', $since)[0] ?? [];
ok(json_decode($a['before'] ?? '{}', true) === ['severity' => 'watch'] && json_decode($a['after'], true)['severity'] === 'high', 'event.update logged what changed: severity watch → high');
$r = post_json($jP, '/events/save.php', ['event' => $e1, 'tags' => 'financing']);
ok($r['code'] === 200 && one('SELECT count(*) FROM record_tags WHERE record_type = \'event\' AND record_id = :i', ['i' => $e1]) == 1, 'the tags made exactly "financing"');
$form = page($jP, '/events/' . $e1 . '/edit')['body'];
ok(str_contains($form, 'id="event-form-field-title"') && str_contains($form, 'SMOKE: a press release names a financing') && str_contains($form, 'id="event-form-field-filing-picker"') && !str_contains($form, '<select name="filing"'),
    'the edit form is filled and the filing is chosen through the record picker, not a <select>');

echo "4. A filing of a watched form makes its event; the timeline links it\n";
q("INSERT INTO filings (issuer_id, filer_cik, accession_number, form, filed_on, items, primary_document, primary_doc_description) VALUES (:i, '0000999001', '0000999001-24-000007', '8-K', '2024-04-02', '{3.02}', 'a8k.htm', 'Unregistered sales')", ['i' => $alpha]);
$fid = (int) one("SELECT id FROM filings WHERE accession_number = '0000999001-24-000007'");
$e2 = (int) one("SELECT id FROM events WHERE filing_id = :f AND kind = 'filing'", ['f' => $fid]);
ok($e2 > 0 && one('SELECT severity FROM events WHERE id = :i', ['i' => $e2]) === 'watch' && one('SELECT source FROM events WHERE id = :i', ['i' => $e2]) === 'worker', 'the filing trigger made a watch-severity event, source worker');
$list = page($jP, '/events')['body'];
ok(str_contains($list, 'id="event-row-' . $e2 . '"') && str_contains($list, 'id="event-filing-' . $e2 . '"') && str_contains($list, 'href="/filings/' . $fid . '"') && str_contains($list, 'id="events-month-2024-04"'),
    'the timeline: the filing\'s event is a row with its filing linked, under its month');
$r = post_json($jP, '/events/save.php', ['issuer' => $alpha, 'on_date' => '2024-04-02', 'kind' => 'filing', 'title' => 'a second event for the same filing', 'filing' => $fid]);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['filing']) && str_contains($r['body'], 'already has its event'), 'a second filing-kind event for the same filing: refused in words');
$r = post_json($jP, '/events/save.php', ['issuer' => $alpha, 'on_date' => '2024-04-02', 'kind' => 'financing', 'title' => 'SMOKE: the facility, from the 8-K', 'filing' => $fid, 'severity' => 'high']);
$e3 = rid($r);
ok($r['code'] === 200 && (int) one('SELECT filing_id FROM events WHERE id = :i', ['i' => $e3]) === $fid, 'a financing event may cite the same filing (chosen through the picker)');
$pick = req('GET', '/pick/?source=filing&issuer=' . $alpha, ['jar' => $jP])['body'];
ok(str_contains($pick, '8-K of Apr 2, 2024'), 'the picker lists the filing');

echo "5. The timeline — filters, months, JSON\n";
$j = json_decode(page($jP, '/events?from=2024-04-01&to=2024-04-30', ['headers' => ['Accept: application/json']])['body'], true)['data'] ?? [];
ok(($j['total'] ?? 0) === 3 && array_column($j['events'] ?? [], 'event_id') === [$e1, $e3, $e2], 'April 2024 holds the three events (a JSON screen, newest first)');
$j = json_decode(page($jP, '/events?kind[]=press', ['headers' => ['Accept: application/json']])['body'], true)['data'] ?? [];
ok(in_array($e1, array_column($j['events'] ?? [], 'event_id'), true) && array_unique(array_column($j['events'] ?? [], 'kind')) === ['press'] && !in_array($e2, array_column($j['events'] ?? [], 'event_id'), true), 'kind=press: the press releases alone');
$j = json_decode(page($jP, '/events?severity[]=high&from=2024-04-01&to=2024-04-30', ['headers' => ['Accept: application/json']])['body'], true)['data'] ?? [];
ok(array_column($j['events'] ?? [], 'event_id') === [$e1, $e3], 'severity=high in April: the two high ones');
$j = json_decode(page($jP, '/events?q=facility&from=2024-04-01&to=2024-04-30', ['headers' => ['Accept: application/json']])['body'], true)['data'] ?? [];
ok(array_column($j['events'] ?? [], 'event_id') === [$e1, $e3], 'words search the title and the body ("facility": one in each)');
$j = json_decode(page($jP, '/events', ['headers' => ['Accept: application/json']])['body'], true)['data'] ?? [];
ok(isset($j['upcoming']) && in_array('SMOKE: the record date for the annual meeting', array_column($j['upcoming'], 'title'), true) && count(array_filter($j['upcoming'], static fn ($u) => $u['computed'])) >= 1,
    'the JSON screen carries what is coming: the recorded record date and the computed days');

echo "6. Delete to the tombstone, restore\n";
$since = last_activity_id();
$r = post_json($jP, '/events/delete.php', ['event' => $e3]);
ok($r['code'] === 200 && rid($r) === $e3 && one('SELECT deleted_at IS NOT NULL FROM events WHERE id = :i', ['i' => $e3]) === true, 'event_delete: the event is tombstoned');
ok(page($jP, '/events/' . $e3)['code'] === 404 && !str_contains(page($jP, '/events')['body'], 'id="event-row-' . $e3 . '"'), 'a tombstoned event is gone from its page and the timeline');
ok(activity('event.delete', $since) !== [] && post_json($jP, '/events/delete.php', ['event' => $e3])['code'] === 404, 'event.delete logged; deleting it again: 404');
$h = page($jP, '/events?notice=deleted&undo=' . $e3)['body'];
ok(str_contains($h, 'id="events-undo-btn"'), 'after a delete the list offers "Undo the delete"');
$r = post_json($jP, '/events/restore.php', ['event' => $e3]);
ok($r['code'] === 200 && one('SELECT deleted_at FROM events WHERE id = :i', ['i' => $e3]) === null && page($jP, '/events/' . $e3)['code'] === 200 && activity('event.restore', $since) !== [], 'event_restore: it is back, logged');
ok(post_json($jP, '/events/restore.php', ['event' => $e3])['code'] === 422, 'restoring an event that is not deleted: 422');

echo "7. An agent records an event; its delete pauses\n";
$ah = agent_headers(42, 951);
$r = agent_post($ah, '/events/save.php', ['issuer' => $alpha, 'on_date' => '2024-04-04', 'kind' => 'note', 'title' => 'SMOKE: the Analyst noticed the filing']);
$e4 = rid($r);
ok($r['code'] === 200 && $e4 > 0 && one('SELECT source FROM events WHERE id = :i', ['i' => $e4]) === 'agent', 'event_create under the Surveillance Analyst\'s run token: source agent');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$ap = array_column(json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json'), true)['approvals'] ?? [], 'category', 'action');
ok(($ap['event_delete'] ?? '') === 'deletion' && !isset($ap['event_create']) && !isset($ap['event_update']), 'an agent\'s event_delete pauses (deletion); creating and changing do not');

echo "8. The signals an event explains\n";
$ev = (int) one("SELECT id FROM events WHERE title = 'SMOKE: a short report is published'");
$sig = (int) one("SELECT s.id FROM signals s JOIN signal_rules r ON r.id = s.rule_id WHERE r.key = 'si_over_float' AND s.issuer_id = :i", ['i' => $alpha]);
$r = post_json($jP, '/signals/dismiss.php', ['signal' => $sig, 'reason' => 'explained_by_event', 'explaining_event' => $ev]);
$p = page($jP, '/events/' . $ev)['body'];
ok($r['code'] === 200 && str_contains($p, 'id="event-signal-' . $sig . '"'), 'a signal dismissed with this event is listed on its page');
ok(str_contains(page($jP, '/events/' . $e1)['body'], 'id="event-explains-empty"'), 'an event that explains nothing says so');

echo "9. Who may, and the wall\n";
$r = post_json($jV, '/events/save.php', ['issuer' => $alpha, 'on_date' => '2024-04-03', 'kind' => 'press', 'title' => 'a Viewer\'s']);
ok($r['code'] === 403 && str_contains($r['body'], 'You may not'), 'a Viewer records nothing: 403 in words');
$hv = page($jV, '/events/' . $e1)['body'];
ok(page($jV, '/events')['code'] === 200 && str_contains($hv, 'id="event-title"'), 'a Viewer reads the timeline and the page');
ok(!str_contains($hv, 'id="event-delete-btn"') && !str_contains($hv, 'id="event-edit-link"') && page($jV, '/events/new')['code'] === 403 && post_json($jV, '/events/delete.php', ['event' => $e1])['code'] === 403, 'and sees no Edit or Delete; the form and the delete are 403');
$bsec = (int) one('SELECT id FROM securities WHERE issuer_id = :i', ['i' => $ids['beta']]);
$eb = (int) q("INSERT INTO events (issuer_id, security_id, on_date, kind, title, severity, source, created_by) VALUES (:i, :s, '2024-04-03', 'press', 'SMOKE: Beta secret plans', 'info', 'person', 30) RETURNING id", ['i' => $ids['beta'], 's' => $bsec])[0]['id'];
ok(page($jP, '/events/' . $eb)['code'] === 404 && post_json($jP, '/events/save.php', ['event' => $eb, 'severity' => 'high'])['code'] === 404 && post_json($jP, '/events/delete.php', ['event' => $eb])['code'] === 404,
    'an event of an issuer Priya may not see: its page, its change and its delete are 404');
ok(post_json($jP, '/events/save.php', ['issuer' => $ids['beta'], 'on_date' => '2024-04-03', 'kind' => 'press', 'title' => 'into Beta'])['code'] === 422, 'and she cannot record one on that issuer');
$pd = page($jD, '/events/' . $eb);
ok($pd['code'] === 200 && str_contains($pd['body'], 'SMOKE: Beta secret plans'), 'Dana, on Beta\'s list, reads it');
$j = json_decode(page($jP, '/events?issuer=' . $ids['beta'], ['headers' => ['Accept: application/json']])['body'], true)['data'] ?? [];
ok(!in_array($eb, array_column($j['events'] ?? [], 'event_id'), true), 'Beta\'s events are not in Priya\'s list, whichever issuer she asks for');
$pk = req('GET', '/pick/?source=event&issuer=' . $ids['beta'], ['jar' => $jP])['body'];
ok(!str_contains($pk, 'secret'), 'nor in the event picker');

echo "10. The pages work on a phone\n";
$t = page($jP, '/events')['body'];
ok(str_contains($t, 'class="btn btn-light btn-touch btn-sm me-2" id="events-calendar-link"') || str_contains($t, 'id="events-calendar-link"'), 'the list links the calendar');
finish();
