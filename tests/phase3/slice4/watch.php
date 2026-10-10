<?php
/**
 * Proof: the full-text watch (edgar-filings.md "Proof"): the pull finds the dilution terms in the company's filings — the snippet in the hit
 * document's own words (the 8-K's exhibit, not the 8-K) — and the company's name in another filer's filing (inserted about_us, its event made);
 * the ELOC and two ATMs proposed (one ELOC for the two terms of one filing); a second pull finds nothing new; the watch screen's cards; a person
 * confirms the ELOC through the prefilled form (floating price, the counterparty, the facility) — the instrument active, the hit confirmed; an
 * agent's confirm is refused (people only); a dismissal dismisses its proposal; a Viewer reads the watch without the buttons.
 */
require __DIR__ . '/lib.php';
[$jP, ] = sign_on(26);
[$jV, ] = sign_on(29);
$sm = smoke_issuer();
foreach ([$jP, $jV] as $j) { page($j, '/?issuer=' . $sm['issuer']); }
edgar_contact(true);
$json = ['headers' => ['Accept: application/json']];
pull('edgar_submissions');
$f8k = filing_id('0000999999-24-000005');

echo "1. The pull\n";
stub_reset();
$since = last_activity_id();
pull('edgar_fulltext');
$p = last_pull('edgar_fulltext');
ok($p['status'] === 'ok' && (int) $p['listings_new'] === 5, 'the watch pulled: five hits new (two terms in the 8-K, the S-3 and the 424B5 at-the-market, the complaint about us) — ' . $p['status'] . ' ' . $p['listings_new'] . ($p['error'] ? ' — ' . $p['error'] : ''));
$q = array_values(array_filter(array_column(stub_requests(), 'path'), static fn ($x) => $x === '/LATEST/search-index'));
ok(count($q) === 8, 'one query per term (seven) and one for the company\'s name in others\' filings (' . count($q) . ')');
$h = q("SELECT term, snippet FROM watch_hits WHERE filing_id = :f AND term = 'equity purchase agreement'", ['f' => $f8k])[0] ?? [];
ok(str_contains((string) ($h['snippet'] ?? ''), 'This equity purchase agreement is made between SMOKE ISSUER INC and SMOKE EPSILON CAPITAL LLC'), 'the snippet is the exhibit\'s own words — the document the search named');
$about = q("SELECT f.* FROM filings f WHERE f.issuer_id = :i AND f.about_us", ['i' => $sm['issuer']]);
ok(count($about) === 1 && $about[0]['filer_cik'] === '0001777777' && $about[0]['primary_document'] === 'complaint.htm' && (int) one("SELECT count(*) FROM watch_hits WHERE filing_id = :f AND term = 'about us'", ['f' => (int) $about[0]['id']]) === 1,
    'another filer\'s 8-K naming the company: inserted about_us (its filer, its document), a hit "about us"');
ok((int) one("SELECT count(*) FROM filings WHERE accession_number = '0001444444-24-000003'") === 0, 'the 13D that lists the company\'s own CIK is not taken as "about us" (it is in the index\'s domain)');
ok(one("SELECT title FROM events WHERE filing_id = :f", ['f' => (int) $about[0]['id']]) === '8-K filed about the company: 8-K', 'the about-us filing made its event: "8-K filed about the company"');
$d = q("SELECT kind, filing_id, status, proposed_by, watch_term FROM dilution_instruments WHERE issuer_id = :i ORDER BY id", ['i' => $sm['issuer']]);
ok(count($d) === 3 && count(array_filter($d, static fn ($x) => $x['kind'] === 'eloc')) === 1 && count(array_filter($d, static fn ($x) => $x['kind'] === 'atm')) === 2 && array_unique(array_column($d, 'status')) === ['proposed'] && array_unique(array_column($d, 'proposed_by')) === ['watch'],
    'proposed: one ELOC for the 8-K (two terms, one instrument) and two ATMs (the S-3 and the 424B5), by the watch');
ok((int) one("SELECT count(*) FROM activity_log WHERE id > :s AND action = 'watch.hit'", ['s' => $since]) === 5 && (int) one("SELECT count(*) FROM activity_log WHERE id > :s AND action = 'dilution.propose'", ['s' => $since]) === 3, 'watch.hit and dilution.propose logged');
pull('edgar_fulltext');
ok((int) last_pull('edgar_fulltext')['listings_new'] === 0 && (int) one('SELECT count(*) FROM watch_hits') === 5 && (int) one('SELECT count(*) FROM dilution_instruments') === 3, 'a second pull: nothing new');

echo "2. The watch screen\n";
$d = json_decode(page($jV, '/filings/watch', $json)['body'], true)['data'] ?? [];
ok(($d['total'] ?? 0) === 5 && count($d['terms'] ?? []) === 7, 'the new hits and the terms asked');
$hv = page($jV, '/filings/watch')['body'];
$hp = page($jP, '/filings/watch')['body'];
$hit = (int) one("SELECT id FROM watch_hits WHERE filing_id = :f AND term = 'equity purchase agreement'", ['f' => $f8k]);
ok(str_contains($hv, 'id="watch-hit-' . $hit . '"') && !str_contains($hv, 'id="watch-confirm-') && str_contains($hp, 'id="watch-confirm-' . $hit . '"'), 'a Viewer reads the cards without the buttons; an Analyst has Confirm and Dismiss');

echo "3. Confirm and dismiss\n";
$form = page($jP, '/dilution/new?hit=' . $hit)['body'];
ok(str_contains($form, 'id="dilution-form-hit"') && str_contains($form, '<option value="eloc" selected') && str_contains($form, 'action="/filings/watch/confirm.php"'), 'the confirm form: the hit\'s words, the kind prefilled (ELOC), posting to watch_hit_confirm');
kernel_state(function ($s) { $s['facts']['911'] = ['valid' => true, 'member_id' => 42, 'run_id' => 911, 'request_id' => 'req-911', 'is_eval' => false]; return $s; });
ok(req('POST', '/filings/watch/confirm.php', ['headers' => as_agent(run_token(42, 911)), 'form' => ['watch_hit' => $hit, 'kind' => 'eloc']])['code'] === 403, 'an agent may not confirm (people only): 403');
$since = last_activity_id();
$r = post_json($jP, '/filings/watch/confirm.php', ['watch_hit' => $hit, 'kind' => 'eloc', 'counterparty' => 'SMOKE EPSILON CAPITAL LLC', 'facility_amount' => '10,000,000', 'floating_price' => 'yes',
    'price_terms' => '95% of the lowest daily VWAP of the five trading days before each put']);
$iid = (int) ($r['json']['record_id'] ?? 0);
$i = q('SELECT * FROM dilution_instruments WHERE id = :i', ['i' => $iid])[0] ?? [];
ok($r['code'] === 200 && ($i['status'] ?? '') === 'active' && $i['floating_price'] === true && (float) $i['facility_amount'] === 10000000.0 && $i['counterparty'] === 'SMOKE EPSILON CAPITAL LLC' && (int) $i['confirmed_by'] === 26
    && (int) one("SELECT count(*) FROM dilution_instruments WHERE kind = 'eloc'") === 1, 'confirmed: the proposed ELOC made active — floating, $10,000,000, the counterparty — confirmed by the Analyst (no second instrument)');
ok(one('SELECT status FROM watch_hits WHERE id = :h', ['h' => $hit]) === 'confirmed' && (int) one("SELECT count(*) FROM activity_log WHERE id > :s AND action = 'watch.confirm'", ['s' => $since]) === 1, 'the hit confirmed; watch.confirm logged');
ok(post_json($jP, '/filings/watch/confirm.php', ['watch_hit' => $hit, 'kind' => 'eloc'])['code'] === 422, 'a second confirm: 422 (already confirmed)');
$s3 = (int) one("SELECT id FROM watch_hits WHERE term = 'at-the-market' AND filing_id = :f", ['f' => filing_id('0000999999-24-000004')]);
$r = post_json($jP, '/filings/watch/dismiss.php', ['watch_hit' => $s3]);
ok($r['code'] === 200 && one('SELECT status FROM watch_hits WHERE id = :h', ['h' => $s3]) === 'dismissed' && one("SELECT status FROM dilution_instruments WHERE filing_id = :f", ['f' => filing_id('0000999999-24-000004')]) === 'dismissed',
    'Dismiss: the hit and the ATM it proposed dismissed');
ok(post_json($jV, '/filings/watch/dismiss.php', ['watch_hit' => (int) one("SELECT id FROM watch_hits WHERE status = 'new' LIMIT 1")])['code'] === 403, 'a Viewer may not dismiss: 403');
ok(post_json($jP, '/filings/watch/confirm.php', ['watch_hit' => (int) one("SELECT id FROM watch_hits WHERE status = 'new' LIMIT 1"), 'kind' => 'loan'])['code'] === 422, 'a kind that is not one: 422');
finish();
