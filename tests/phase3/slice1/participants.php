<?php
/**
 * Proof: the DTC participants (issuers-participants.md "Proof"): the directory pull through the worker (a new number classed from its name, a
 * renamed one keeping its class with the old name as an alias, unchanged ones), a person's class surviving the next rename, the list as cards by
 * class with the search by number, name and old name, one participant's page with its series in the current security (and none for a security
 * behind the wall), the edit (class → manual, notes, tags) logged, the search index catching up, a Viewer reading and not editing.
 */
require __DIR__ . '/lib.php';
[$jM, ] = sign_on(27);
[$jP, ] = sign_on(26);
[$jV, ] = sign_on(29);
$json = ['headers' => ['Accept: application/json']];

echo "1. The directory\n";
edgar_contact(true);
q("UPDATE sources SET last_ok_at = now() WHERE connector = 'edgar_submissions'");
stub_reset();
source_due('dtcc_participants');
$w = worker('fetch,search_catch_up', '2026-10-10T11:00:00-04:00');
$p7777 = q("SELECT * FROM participants WHERE number = '7777'")[0] ?? [];
$p7778 = q("SELECT * FROM participants WHERE number = '7778'")[0] ?? [];
$p0164 = q("SELECT * FROM participants WHERE number = '0164'")[0] ?? [];
ok(($p7777['class_key'] ?? '') === 'lending' && $p7777['class_source'] === 'directory_name' && $p7777['first_seen_in_directory'] === '2026-09-30', 'a new number named …/STOCK LOAN is classed lending, from the directory\'s name, first seen on the file\'s date');
ok(($p7778['class_key'] ?? '') === 'other', 'a new number whose name gives no clue is classed other — a manager corrects it');
ok($p0164['name'] === 'CHARLES SCHWAB & CO., INC. (A SMOKE RENAME)' && $p0164['class_key'] === 'retail' && in_array('CHARLES SCHWAB & CO., INC.', pg_t((string) $p0164['aliases']), true), '0164 renamed: it keeps retail and its old name becomes an alias');
ok(isset($w['report']['ran']['search_catch_up']['indexed']) && (int) one("SELECT count(*) FROM search_index WHERE entity_kind = 'participant' AND entity_id IN (:a, :b, :c)", ['a' => (int) $p7777['id'], 'b' => (int) $p7778['id'], 'c' => (int) $p0164['id']]) === 3,
    'the search index holds the new and the renamed participants (the trigger indexes; the worker\'s catch-up pass ran)');
$d = json_decode(page($jP, '/search?scope=all&q=' . rawurlencode('SMOKE NEW CLEARING'), $json)['body'], true)['data']['results'] ?? [];
ok(in_array('participant', array_column($d, 'entity_kind'), true), 'the search box finds the new participant');

echo "2. A person's class is never overwritten\n";
$r = post_json($jM, '/participants/save.php', ['participant' => (int) $p0164['id'], 'class' => 'clearing_retail']);
ok($r['code'] === 200 && one("SELECT class_source FROM participants WHERE number = '0164'") === 'manual', 'Marco sets 0164 to clearing (retail): the class is his');
q("UPDATE participants SET name = 'CHARLES SCHWAB & CO., INC.' WHERE number = '0164'");
stub_reset();
source_due('dtcc_participants');
worker('fetch', '2026-10-10T11:00:00-04:00');
ok(one("SELECT class_key FROM participants WHERE number = '0164'") === 'clearing_retail' && one("SELECT name FROM participants WHERE number = '0164'") === 'CHARLES SCHWAB & CO., INC. (A SMOKE RENAME)', 'the next pull renames it again and leaves his class alone');

echo "3. The list, as cards by class\n";
$d = json_decode(page($jP, '/participants', $json)['body'], true)['data'] ?? [];
ok(count($d['participants']) === (int) one('SELECT count(*) FROM participants') && count($d['classes']) === 8, 'every participant, and the eight classes');
$d = json_decode(page($jP, '/participants?class=lending', $json)['body'], true)['data'] ?? [];
ok(array_unique(array_column($d['participants'], 'class_key')) === ['lending'] && in_array('7777', array_column($d['participants'], 'number'), true), 'filtered to one class');
foreach (['164' => '0164', 'stock loan' => '7777', 'CHARLES SCHWAB & CO., INC.' => '0164'] as $qq => $num) {
    $d = json_decode(page($jP, '/participants?q=' . rawurlencode($qq), $json)['body'], true)['data'] ?? [];
    ok(in_array($num, array_column($d['participants'], 'number'), true), "found by \"$qq\" (a number, words of the name, an old name)");
}
$h = page($jP, '/participants')['body'];
ok(str_contains($h, 'id="participant-card-' . (int) $p0164['id'] . '"') && str_contains($h, 'si-class-chip') && str_contains($h, 'A class is a setting'), 'cards with the class chip, and the caveat that a class is a setting');

echo "4. One participant, its series in the current security\n";
q("INSERT INTO issuers (name, ticker) VALUES ('SMOKE SERIES CORP', 'SMSR'), ('SMOKE HIDDEN CORP', 'SMHD')");
$ia = (int) one("SELECT id FROM issuers WHERE ticker = 'SMSR'");
$ib = (int) one("SELECT id FROM issuers WHERE ticker = 'SMHD'");
q("INSERT INTO securities (issuer_id, name, cusip, ticker, is_primary) VALUES (:a, 'Common Stock', '99901A101', 'SMSR', true), (:b, 'Common Stock', '99901B101', 'SMHD', true)", ['a' => $ia, 'b' => $ib]);
$sa = (int) one('SELECT id FROM securities WHERE issuer_id = :a', ['a' => $ia]);
$sb = (int) one('SELECT id FROM securities WHERE issuer_id = :b', ['b' => $ib]);
q("INSERT INTO imports (kind, connector, file_name, issuer_id, security_id, record_date, status, tied) VALUES ('spr', 'dtc_spr_csv', 'SMOKE.csv', :i, :s, CURRENT_DATE - 1, 'done', true)", ['i' => $ia, 's' => $sa]);
$imp = (int) one('SELECT max(id) FROM imports');
$pid = (int) $p0164['id'];
foreach ([[10, 5000000], [9, 5100000], [8, 5050000], [3, 6000000], [1, 6200000]] as [$ago, $sh]) {
    q("INSERT INTO participant_positions (security_id, participant_id, position_date, shares, import_id) VALUES (:s, :p, CURRENT_DATE - CAST(:d AS integer), :sh, :imp)", ['s' => $sa, 'p' => $pid, 'd' => $ago, 'sh' => $sh, 'imp' => $imp]);
}
q("INSERT INTO participant_positions (security_id, participant_id, position_date, shares, import_id) VALUES (:s, :p, CURRENT_DATE - 1, 3800000, :imp)", ['s' => $sa, 'p' => (int) one("SELECT id FROM participants WHERE number = '0005'"), 'imp' => $imp]);
q("INSERT INTO issuer_members (issuer_id, member_id) VALUES (:b, 30)", ['b' => $ib]);
$d = json_decode(page($jP, '/participants/' . $pid . '?security=' . $sa, $json)['body'], true)['data'] ?? [];
ok(count($d['series']) === 5 && (float) $d['series'][4]['shares'] === 6200000.0 && (float) $d['series'][1]['change'] === 100000.0, 'the series: five dates, the latest 6,200,000, each with its change');
ok((int) $d['presence']['days'] === 5 && (float) $d['presence']['pct_of_total'] === 62.0, 'present five dates; 62% of the DTC total on the latest');
$h = page($jP, '/participants/' . $pid . '?security=' . $sa)['body'];
ok(str_contains($h, 'id="participant-series-chart"') && str_contains($h, 'id="participant-series-table"') && str_contains($h, '6,200,000') && str_contains($h, 'CHARLES SCHWAB &amp; CO., INC.'), 'its page: the chart, the table twin, the latest, the former name');
$d = json_decode(page($jP, '/participants/' . $pid . '?security=' . $sb, $json)['body'], true)['data'] ?? [];
ok($d['series'] === [] && (int) $d['presence']['days'] === 0, 'a security behind the wall shows no position (Priya, SMOKE HIDDEN)');
$jx = jar(); req('GET', handoff(26), ['jar' => $jx]); page($jx, '/?issuer=' . $ia);
$d = json_decode(page($jx, '/participants?q=0164', $json)['body'], true)['data'] ?? [];
ok((float) ($d['participants'][0]['latest']['shares'] ?? 0) === 6200000.0 && $d['security_id'] === $sa, 'with SMOKE SERIES current, the card shows 0164\'s 6,200,000');
ok(page($jP, '/participants/999999')['code'] === 404, 'a participant that does not exist: 404');

echo "5. The edit\n";
$since = last_activity_id();
$r = post_json($jM, '/participants/save.php', ['participant' => (int) $p7778['id'], 'class' => 'prime', 'notes' => 'SMOKE: a prime broker per its website', 'tags' => 'smoke-prime, watch']);
$pr = q('SELECT * FROM participants WHERE id = :i', ['i' => (int) $p7778['id']])[0];
ok($r['code'] === 200 && $pr['class_key'] === 'prime' && $pr['class_source'] === 'manual' && str_contains((string) $pr['notes'], 'prime broker'), 'participant_update: the class (now manual) and the notes');
ok(pg_t((string) one("SELECT si_tags_of('participant', :i)", ['i' => (int) $p7778['id']])) === ['smoke-prime', 'watch'], 'the tags field sets the whole list');
post_json($jM, '/participants/save.php', ['participant' => (int) $p7778['id'], 'tags' => 'watch']);
ok(pg_t((string) one("SELECT si_tags_of('participant', :i)", ['i' => (int) $p7778['id']])) === ['watch'], '…and takes one off');
$l = json_decode((string) one("SELECT after FROM activity_log WHERE action = 'participant.update' AND id > :s ORDER BY id LIMIT 1", ['s' => $since]), true);
ok(($l['class'] ?? '') === 'prime' && ($l['notes'] ?? '') === '(changed)' && !str_contains(json_encode($l), 'website'), 'participant.update logged: the class, notes "(changed)", never their words');
ok(post_json($jM, '/participants/save.php', ['participant' => (int) $p7778['id'], 'class' => 'whale'])['code'] === 422, 'an unknown class: 422');
$r = post_json($jM, '/participants/save.php', ['participant' => (int) $p7778['id'], 'class' => 'prime']);
ok($r['code'] === 200 && $r['json']['did'] === 'Nothing to change', 'the same class again: nothing to change');
$h = page($jM, '/participants/' . (int) $p7778['id'] . '/edit')['body'];
ok(str_contains($h, 'id="participant-form-class-prime"') && str_contains($h, 'checked') && str_contains($h, 'never changes it again'), 'the edit form: the eight classes as choices, saying the class becomes the person\'s');
ok(page($jP, '/participants/' . (int) $p7778['id'] . '/edit')['code'] === 403 && post_json($jP, '/participants/save.php', ['participant' => 1, 'class' => 'other'])['code'] === 403, 'an Analyst may not edit a participant (issuers.manage): 403');
ok(page($jV, '/participants')['code'] === 200 && page($jV, '/participants/' . $pid)['code'] === 200 && !str_contains(page($jV, '/participants/' . $pid)['body'], 'id="participant-edit-btn"'), 'a Viewer reads the participants, with no edit button');
$r = post_json($jP, '/notes/add.php', ['kind' => 'participant', 'record' => $pid, 'body' => 'SMOKE: Schwab\'s retail box']);
ok($r['code'] === 200 && str_contains(page($jP, '/participants/' . $pid)['body'], 'retail box'), 'a note on a participant');
finish();

function pg_t(string $l): array { $l = trim($l, '{}'); return $l === '' ? [] : array_map(static fn ($v) => trim($v, '"'), str_getcsv($l, ',', '"', '\\')); }
