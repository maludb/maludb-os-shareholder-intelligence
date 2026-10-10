<?php
/**
 * Proof: the dilution instruments (edgar-filings.md): the cards with the floating-price flag and what remains as a bar; one instrument's page;
 * an instrument recorded by hand with its filing chosen through the picker; edited (what remains; a proposed one saved active is confirmed);
 * the fields refused in words; a Viewer reads and may not save; an agent may not save (people only) and its delete pauses (the registry); deleted.
 */
require __DIR__ . '/lib.php';
[$jP, ] = sign_on(26);
[$jV, ] = sign_on(29);
$sm = smoke_issuer();
foreach ([$jP, $jV] as $j) { page($j, '/?issuer=' . $sm['issuer']); }
edgar_contact(true);
$json = ['headers' => ['Accept: application/json']];
pull('edgar_submissions');
pull('edgar_fulltext');
$f8k = filing_id('0000999999-24-000005');

echo "1. By hand\n";
$pk = page($jP, '/pick/?source=filing&q=8-K&issuer=' . $sm['issuer'], ['headers' => ['HX-Request: true']]);
ok($pk['code'] === 200 && str_contains($pk['body'], 'record-picker-row-' . $f8k), 'the filing picker finds the 8-K');
$r = post_json($jP, '/dilution/save.php', ['issuer' => $sm['issuer'], 'kind' => 'warrant', 'counterparty' => 'SMOKE WARRANT HOLDERS', 'filing' => $f8k, 'shares_issuable_max' => '2,000,000',
    'price_terms' => 'Exercisable at $1.50 for five years', 'floating_price' => 'no', 'status' => 'active', 'expires_on' => '2029-03-15']);
$w = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && one('SELECT kind FROM dilution_instruments WHERE id = :i', ['i' => $w]) === 'warrant' && (float) one('SELECT shares_issuable_max FROM dilution_instruments WHERE id = :i', ['i' => $w]) === 2000000.0
    && one('SELECT proposed_by FROM dilution_instruments WHERE id = :i', ['i' => $w]) === 'person', 'recorded by hand: warrants on 2,000,000 shares with the 8-K as their filing');
$r = post_json($jP, '/dilution/save.php', ['issuer' => $sm['issuer'], 'kind' => 'loan', 'facility_amount' => 'lots', 'expires_on' => 'soon']);
ok($r['code'] === 422 && count($r['json']['error']['fields'] ?? $r['json']['fields'] ?? []) >= 1, 'a kind, an amount and a date that are not: 422 with the fields named');
ok(post_json($jP, '/dilution/save.php', ['issuer' => $sm['issuer'], 'kind' => 'atm', 'filing' => 999999])['code'] === 422, 'a filing that is not the issuer\'s: 422');

echo "2. Edit and confirm\n";
$atm = (int) one("SELECT id FROM dilution_instruments WHERE kind = 'atm' AND status = 'proposed' ORDER BY id LIMIT 1");
ok(str_contains(page($jP, '/dilution/' . $atm . '/edit')['body'], 'id="dilution-form"'), 'a proposed ATM\'s edit form');
$r = post_json($jP, '/dilution/save.php', ['instrument' => $atm, 'status' => 'active', 'facility_amount' => '25000000', 'remaining_amount' => '18,500,000', 'counterparty' => 'SMOKE BROKERAGE LLC']);
$row = q('SELECT * FROM dilution_instruments WHERE id = :i', ['i' => $atm])[0];
ok($r['code'] === 200 && $row['status'] === 'active' && (int) $row['confirmed_by'] === 26 && (float) $row['remaining_amount'] === 18500000.0 && $row['kind'] === 'atm', 'saved active: confirmed by the saver, $18,500,000 of $25,000,000 remaining, its kind kept');

echo "3. The screens\n";
$d = json_decode(page($jV, '/dilution', $json)['body'], true)['data']['instruments'] ?? [];
ok(count($d) === 4 && in_array('warrant', array_column($d, 'kind'), true), 'a Viewer reads the four instruments: the ELOC and an ATM proposed, the other ATM active, the warrants');
$h = page($jV, '/dilution')['body'];
ok(str_contains($h, 'id="dilution-card-' . $atm . '"') && str_contains($h, '$18,500,000 of $25,000,000 remaining') && str_contains($h, 'role="progressbar"'), 'the cards: what remains of the facility, as a bar');
q("UPDATE dilution_instruments SET floating_price = true WHERE id = :i", ['i' => $atm]);
ok(str_contains(page($jV, '/dilution')['body'], 'id="dilution-floating-' . $atm . '"'), 'the floating-price flag on its card');
$v = page($jV, '/dilution/' . $atm)['body'];
ok(str_contains($v, 'id="dilution-terms"') && str_contains($v, 'id="dilution-filing-link"') && !str_contains($v, 'id="dilution-edit-btn"'), 'its page: the terms and the filing; a Viewer without Edit');

echo "4. Who may\n";
ok(post_json($jV, '/dilution/save.php', ['issuer' => $sm['issuer'], 'kind' => 'atm'])['code'] === 403 && page($jV, '/dilution/new')['code'] === 403, 'a Viewer: 403 on the form and the save');
kernel_state(function ($s) { $s['facts']['921'] = ['valid' => true, 'member_id' => 42, 'run_id' => 921, 'request_id' => 'req-921', 'is_eval' => false]; return $s; });
$rt = as_agent(run_token(42, 921));
ok(req('POST', '/dilution/save.php', ['headers' => $rt, 'form' => ['issuer' => $sm['issuer'], 'kind' => 'atm']])['code'] === 403, 'an agent may not save (people only): 403');
$ap = array_column(json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json'), true)['approvals'] ?? [], 'category', 'action');
ok(($ap['dilution_delete'] ?? '') === 'deletion', 'an agent\'s dilution_delete pauses for approval (deletion)');
$since = last_activity_id();
ok(post_json($jP, '/dilution/delete.php', ['instrument' => $w])['code'] === 200 && one('SELECT count(*) FROM dilution_instruments WHERE id = :i', ['i' => $w]) === 0
    && (int) one("SELECT count(*) FROM activity_log WHERE id > :s AND action = 'dilution.delete'", ['s' => $since]) === 1, 'deleted, logged');
ok(page($jP, '/dilution/' . $w)['code'] === 404, 'its page is a 404');
finish();
