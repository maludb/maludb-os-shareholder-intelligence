<?php
/**
 * Proof: the index half of edgar_submissions, companyfacts and fetch_now against the stub (edgar-and-finra-fetchers.md §5, edgar-filings.md "Proof"):
 * the index pull makes the filings (the recent ones and the older page it names, once), the watched forms' events (an 8-K with items 1.01/3.02 at
 * watch, a Form 4 an insider transaction, a CORRESP none), and reads the watched forms' text on arrival (HTML with its paragraphs and table cells,
 * the Form 4's XML; a document EDGAR lacks is kept as the filing's error); a second pull changes nothing; companyfacts writes the outstanding
 * counts with their frame and filing (an amendment restating a date wins) and the public float, never touching a person's count; a second pull
 * changes nothing; fetch_now on a backed-off or paused source answers when or why; the filings screen with its form chips.
 */
require __DIR__ . '/lib.php';
[$jP, ] = sign_on(26);
[$jV, ] = sign_on(29);
$sm = smoke_issuer();
foreach ([$jP, $jV] as $j) { page($j, '/?issuer=' . $sm['issuer']); }
edgar_contact(true);
$json = ['headers' => ['Accept: application/json']];

echo "1. The index\n";
stub_reset();
$since = last_activity_id();
$w = pull('edgar_submissions');
$p = last_pull('edgar_submissions');
ok($p['status'] === 'ok' && (int) $p['listings_new'] === 9, 'the pull: ok, 9 filings new (the 7 recent and the 2 of the older page) — ' . $p['status'] . ' ' . $p['listings_new'] . ($p['error'] ? ' — ' . $p['error'] : ''));
ok(in_array('/submissions/CIK0000999999-submissions-001.json', array_column(stub_requests(), 'path'), true), 'the older page the document names was read');
$f8k = filing_id('0000999999-24-000005');
$row = q('SELECT * FROM filings WHERE id = :i', ['i' => $f8k])[0];
ok($row['form'] === '8-K' && $row['items'] === '{1.01,3.02,9.01}' && $row['filed_on'] === '2024-03-15' && $row['primary_document'] === 'smok-8k_20240314.htm' && $row['filer_cik'] === '0000999999', 'the 8-K: its items, its date, its primary document');
$ev = q('SELECT kind, severity FROM events WHERE filing_id = :i', ['i' => $f8k])[0] ?? [];
ok(($ev['kind'] ?? '') === 'filing' && $ev['severity'] === 'watch', 'its event: a filing at watch (items 1.01 and 3.02)');
ok(one('SELECT kind FROM events WHERE filing_id = :i', ['i' => filing_id('0001555555-24-000001')]) === 'insider_transaction' && one('SELECT count(*) FROM events WHERE filing_id = :i', ['i' => filing_id('0000999999-24-000003')]) === 0,
    'the Form 4 an insider transaction; the CORRESP no event (not a watched form)');
ok((int) one("SELECT count(*) FROM events WHERE issuer_id = :i AND source = 'worker'", ['i' => $sm['issuer']]) === 8, 'eight events from the eight watched filings');
ok((int) one("SELECT count(*) FROM activity_log WHERE id > :s AND action = 'filing.index' AND issuer_id = :i", ['s' => $since, 'i' => $sm['issuer']]) === 1, 'filing.index logged once with its counts');

echo "2. The text on arrival\n";
$k10 = filing_id('0000999999-24-000006');
$t = (string) one('SELECT text FROM filing_text WHERE filing_id = :i', ['i' => $k10]);
ok(str_contains($t, 'the registrant had 17,000,000 shares') && str_contains($t, 'Revenue | $1,200') && !str_contains($t, 'var x') && !str_contains($t, 'color:red') && !str_contains($t, 'dei:EntityCommonStockSharesOutstanding'),
    'the 10-K as text: the paragraphs, a table row "Revenue | $1,200" — no script, no style, no hidden inline-XBRL header');
$f = q('SELECT char_count, text_sha256, fetched_text_at FROM filings WHERE id = :i', ['i' => $k10])[0];
ok((int) $f['char_count'] === mb_strlen($t) && $f['text_sha256'] === hash('sha256', $t), 'its characters and its hash kept on the filing');
ok(one('SELECT extractor FROM filing_text WHERE filing_id = :i', ['i' => filing_id('0001555555-24-000001')]) === 'xml' && str_contains((string) one('SELECT text FROM filing_text WHERE filing_id = :i', ['i' => filing_id('0001555555-24-000001')]), 'transactionShares'),
    'the Form 4\'s XML read as its elements\' text');
ok((int) one('SELECT count(*) FROM filing_text t JOIN filings f ON f.id = t.filing_id WHERE f.issuer_id = :i', ['i' => $sm['issuer']]) === 6 && one('SELECT fetched_text_at FROM filings WHERE id = :i', ['i' => filing_id('0000999999-24-000003')]) === null,
    'six watched filings read; the CORRESP not (it is read on request)');
$old = q('SELECT fetched_text_at, error FROM filings WHERE id = :i', ['i' => filing_id('0000999999-23-000012')])[0];
ok($old['fetched_text_at'] === null && str_contains((string) $old['error'], '404'), 'a document EDGAR lacks: the filing keeps the error ("…answered 404…"), nothing kept as text');
ok((int) one("SELECT count(*) FROM activity_log WHERE id > :s AND action = 'filing.fetch_text'", ['s' => $since]) === 6 && !str_contains((string) one("SELECT string_agg(after::text, ' ') FROM activity_log WHERE action = 'filing.fetch_text'"), 'Revenue'),
    'filing.fetch_text logged per filing with its characters — never the text');

echo "3. A second pull changes nothing\n";
stub_reset();
$w = pull('edgar_submissions');
$p = last_pull('edgar_submissions');
$pol = json_decode((string) $p['policy'], true);
ok($p['status'] === 'ok' && (int) $p['listings_new'] === 0 && (int) $p['listings_changed'] === 0 && $pol['cached'] >= 1 && !in_array('/submissions/CIK0000999999-submissions-001.json', array_column(stub_requests(), 'path'), true),
    'nothing new or changed; the document a 304 from the cache; the older page not read again');
ok((int) one('SELECT count(*) FROM filings WHERE issuer_id = :i', ['i' => $sm['issuer']]) === 9 && (int) one('SELECT count(*) FROM events WHERE issuer_id = :i', ['i' => $sm['issuer']]) === 8, 'still nine filings, eight events');

echo "4. Companyfacts\n";
q("INSERT INTO share_counts (security_id, as_of, kind, value, source, recorded_by, note) VALUES (:s, '2024-03-25', 'outstanding', 17050000, 'manual', 27, 'SMOKE a person''s count')", ['s' => $sm['security']]);
$since = last_activity_id();
$w = pull('edgar_companyfacts');
$p = last_pull('edgar_companyfacts');
ok($p['status'] === 'ok' && (int) $p['listings_new'] === 4, 'the facts pulled: four counts new (three outstanding, the public float) — ' . $p['status'] . ' ' . $p['listings_new'] . ($p['error'] ? ' — ' . $p['error'] : ''));
$c = q("SELECT as_of, value, xbrl_frame, filing_id FROM share_counts WHERE security_id = :s AND kind = 'outstanding' AND source = 'filing' ORDER BY as_of", ['s' => $sm['security']]);
ok(count($c) === 3 && (float) $c[1]['value'] === 17053900.0 && $c[1]['xbrl_frame'] === 'CY2024Q1I' && (int) $c[2]['filing_id'] === filing_id('0000999999-24-000009') && (int) $c[0]['filing_id'] === filing_id('0000999999-23-000012'),
    'outstanding by its cover date: 2024-03-25 the amendment\'s 17,053,900 (the latest filed wins) with its frame; the 10-Q\'s linked to its filing');
ok((float) one("SELECT value FROM share_counts WHERE security_id = :s AND kind = 'outstanding' AND source = 'manual'", ['s' => $sm['security']]) === 17050000.0, 'the person\'s count of the same date untouched');
ok((float) one("SELECT value FROM share_counts WHERE security_id = :s AND kind = 'public_float_value'", ['s' => $sm['security']]) === 41000000.0, 'the public float: $41,000,000');
ok((int) one("SELECT count(*) FROM activity_log WHERE id > :s AND action = 'share_count.save'", ['s' => $since]) === 4, 'share_count.save logged per count');
pull('edgar_companyfacts');
$p = last_pull('edgar_companyfacts');
ok((int) $p['listings_new'] === 0 && (int) $p['listings_changed'] === 0, 'a second pull: nothing new, nothing changed');
q("INSERT INTO issuers (name, cik) VALUES ('SMOKE NO FACTS CORP', '0000999998')");
q("INSERT INTO securities (issuer_id, name, is_primary) VALUES ((SELECT id FROM issuers WHERE cik = '0000999998'), 'Common', true)");
pull('edgar_companyfacts');
$p = last_pull('edgar_companyfacts');
ok($p['status'] === 'partial' && str_contains((string) $p['error'], 'No XBRL facts'), 'an issuer without facts at EDGAR: the pull is partial, the issuer named');

echo "5. fetch_now\n";
$src = (int) one("SELECT id FROM sources WHERE connector = 'edgar_submissions'");
q("UPDATE sources SET backoff_until = now() + interval '40 minutes' WHERE id = :s", ['s' => $src]);
$r = post_json($jP, '/admin/sources/pull.php', ['source' => $src]);
ok($r['code'] === 422 && str_contains($r['body'], 'backed off until'), 'a backed-off source: 422 — "backed off until …", nothing pulled');
q("UPDATE sources SET backoff_until = NULL, paused_at = now() WHERE id = :s", ['s' => $src]);
ok(post_json($jP, '/admin/sources/pull.php', ['source' => $src])['code'] === 422, 'a paused one: 422');
q('UPDATE sources SET paused_at = NULL WHERE id = :s', ['s' => $src]);
stub_reset();
$since = last_activity_id();
$r = post_json($jP, '/admin/sources/pull.php', ['source' => $src, 'issuer' => $sm['issuer']]);
ok($r['code'] === 200 && ($r['json']['status'] ?? '') === 'ok' && one('SELECT kind FROM source_pulls WHERE id = :p', ['p' => (int) $r['json']['record_id']]) === 'manual'
    && (int) one("SELECT count(*) FROM activity_log WHERE id > :s AND action = 'source.pull_now'", ['s' => $since]) === 1, 'fetch_now narrowed to one issuer: a manual pull, logged source.pull_now');
ok(post_json($jV, '/admin/sources/pull.php', ['source' => $src])['code'] === 403, 'a Viewer may not pull: 403');

echo "6. The filings screen\n";
$d = json_decode(page($jV, '/filings', $json)['body'], true)['data'] ?? [];
ok(($d['total'] ?? 0) === 9 && $d['filings'][0]['form'] === '10-Q' && $d['filings'][0]['has_text'] === true, 'a Viewer reads the nine filings, newest first, with their marks');
$d = json_decode(page($jV, '/filings?form=8-K', $json)['body'], true)['data'] ?? [];
ok(($d['total'] ?? 0) === 2, 'the 8-K chip: the two 8-Ks');
$h = page($jV, '/filings')['body'];
ok(str_contains($h, 'id="filings-table"') && str_contains($h, 'id="filing-row-' . $f8k . '"') && str_contains($h, 'id="filing-form-8K"') && !str_contains($h, 'id="filings-pull-btn"'), 'the ledger with its chips; a Viewer has no pull button');
ok(str_contains(page($jP, '/filings')['body'], 'id="filings-pull-btn"'), 'an Analyst has "Pull from EDGAR now"');
finish();
