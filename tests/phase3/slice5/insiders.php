<?php
/**
 * Proof: Forms 3/4/5 and 144 (ownership-forms.md "Proof"): the Form 4 read into its rows in order — two sales under a 10b5-1 plan, an exercise,
 * the derivative row; the register's "ROBERT A SMITH" proposed for EDGAR's "SMITH ROBERT A" (db/022) and marked an insider; read once; the ledger's
 * filters and summary; the register link shown to the PII roles only; insider_holder_link moving every row (an entity refused, a Viewer and an
 * agent refused); the Form 144 notice.
 */
require __DIR__ . '/lib.php';
[$jP, ] = sign_on(26);
[$jV, ] = sign_on(29);
$sm = seed_ownership();
foreach ([$jP, $jV] as $j) { page($j, '/?issuer=' . $sm['issuer']); }
$json = ['headers' => ['Accept: application/json']];
$smith = (int) q("INSERT INTO holders (issuer_id, display_name, name_key, kind, last_seen_on) VALUES (:i, 'ROBERT A SMITH', si_name_key('ROBERT A SMITH'), 'person', '2024-03-29') RETURNING id", ['i' => $sm['issuer']])[0]['id'];
$bob = (int) q("INSERT INTO holders (issuer_id, display_name, name_key, kind) VALUES (:i, 'BOB SMITH', si_name_key('BOB SMITH'), 'person') RETURNING id", ['i' => $sm['issuer']])[0]['id'];
$ent = (int) q("INSERT INTO holders (issuer_id, display_name, name_key, kind) VALUES (:i, 'SMITH FAMILY HOLDINGS LLC', si_name_key('SMITH FAMILY HOLDINGS LLC'), 'entity') RETURNING id", ['i' => $sm['issuer']])[0]['id'];

echo "1. The Form 4\n";
$since = last_activity_id();
pull('edgar_ownership');
$p = last_pull('edgar_ownership');
ok($p['status'] === 'ok' && (int) $p['listings_seen'] === 2 && (int) $p['listings_new'] === 4, 'two Forms 4 read, four rows — ' . $p['status'] . ' ' . $p['listings_seen'] . '/' . $p['listings_new'] . ($p['error'] ? ' — ' . $p['error'] : ''));
$t = q('SELECT * FROM insider_transactions ORDER BY row_no');
ok(array_column($t, 'transaction_code') === ['S', 'S', 'M', 'M'] && array_map('floatval', array_column($t, 'shares')) === [20000.0, 15000.0, 10000.0, 10000.0] && $t[0]['reporting_owner_name'] === 'SMITH ROBERT A' && $t[0]['relationship'] === '{director,officer}',
    'the rows in order: two sales, the exercise, the derivative row; the owner as EDGAR writes him, a director and officer');
ok((float) $t[0]['price'] === 4.15 && $t[0]['acquired_disposed'] === 'D' && (float) $t[1]['shares_owned_after'] === 465000.0 && $t[3]['is_derivative'] === true && $t[0]['is_10b5_1'] === true && str_contains($t[0]['footnotes'], '10b5-1'),
    'the price, disposed, owned after; the derivative marked; the 10b5-1 plan and its footnote');
ok(count(array_unique(array_column($t, 'holder_id'))) === 1 && (int) $t[0]['holder_id'] === $smith && one('SELECT is_insider FROM holders WHERE id = :h', ['h' => $smith]) === true && one('SELECT insider_cik FROM holders WHERE id = :h', ['h' => $smith]) === $t[0]['reporting_owner_cik'],
    'the register\'s ROBERT A SMITH proposed for EDGAR\'s SMITH ROBERT A, marked an insider with his CIK');
$o = q('SELECT structured_at, error FROM filings WHERE accession_number = :a', ['a' => '0000999999-24-000020'])[0];
ok($o['structured_at'] !== null && str_starts_with((string) $o['error'], 'About another company') && (int) one('SELECT count(*) FROM insider_transactions WHERE filing_id = :f', ['f' => filing_id('0000999999-24-000020')]) === 0,
    'the issuer\'s own Form 4 about another company set aside in words; nothing kept of it (its subject\'s CIK is not the issuer\'s)');
ok((int) one("SELECT count(*) FROM activity_log WHERE id > :s AND action = 'insider.load'", ['s' => $since]) === 1, 'insider.load logged');
pull('edgar_ownership');
ok((int) last_pull('edgar_ownership')['listings_seen'] === 0 && (int) one('SELECT count(*) FROM insider_transactions') === 4, 'a second pull reads it no more');

echo "2. The ledger\n";
$d = json_decode(page($jV, '/ownership/insiders', $json)['body'], true)['data'] ?? [];
ok(($d['total'] ?? 0) === 4 && ($d['summary'][0]['sales'] ?? 0) === 2 && (float) $d['summary'][0]['sale_shares'] === 35000.0, 'a Viewer reads the ledger and the summary: two sales, 35,000 shares');
ok(array_filter(array_column($d['rows'], 'holder_name')) === [] && !str_contains(page($jV, '/ownership/insiders')['body'], 'ROBERT A SMITH'), 'the register\'s name withheld from a Viewer (the PII wall)');
$a = json_decode(page($jP, '/ownership/insiders', $json)['body'], true)['data']['rows'] ?? [];
ok(($a[0]['holder_name'] ?? '') === 'ROBERT A SMITH', 'an Analyst sees the register link');
$s = json_decode(page($jV, '/ownership/insiders?code=S', $json)['body'], true)['data'] ?? [];
ok($s['total'] === 2 && json_decode(page($jV, '/ownership/insiders?from=2030-01-01', $json)['body'], true)['data']['total'] === 0, 'filtered by code (two sales) and by date (none after 2030)');
$h = page($jV, '/ownership/insiders')['body'];
ok(str_contains($h, 'id="insiders-table"') && str_contains($h, '10b5-1') && str_contains($h, 'title="Open-market sale"'), 'the page: the table, the code\'s meaning, the plan');

echo "3. insider_holder_link\n";
$cik = $t[0]['reporting_owner_cik'];
$since = last_activity_id();
ok(post_json($jV, '/ownership/insiders/link.php', ['reporting_owner_cik' => $cik, 'holder' => $bob])['code'] === 403, 'a Viewer may not link');
$r = post_json($jP, '/ownership/insiders/link.php', ['reporting_owner_cik' => $cik, 'holder' => $ent]);
ok($r['code'] === 422 && str_contains(json_encode($r['json']), 'entity'), 'an entity holder refused in words');
$r = post_json($jP, '/ownership/insiders/link.php', ['reporting_owner_cik' => $cik, 'holder' => $bob]);
ok($r['code'] === 200 && ($r['json']['record_id'] ?? null) === $bob && (int) one('SELECT count(*) FROM insider_transactions WHERE holder_id = :h', ['h' => $bob]) === 4 && one('SELECT is_insider FROM holders WHERE id = :h', ['h' => $bob]) === true,
    'a person corrects the link: every row of the insider moves to BOB SMITH');
$l = activity('insider.holder_link', $since)[0] ?? [];
ok($l !== [] && !str_contains((string) $l['after'], 'SMITH'), 'insider.holder_link logged with ids only');
ok(req('POST', '/ownership/insiders/link.php', ['headers' => as_agent(run_token(42, 77)), 'form' => ['reporting_owner_cik' => $cik, 'holder' => $smith]])['code'] === 403, 'an agent may not link (people only)');

echo "4. The Form 144\n";
pull('edgar_144');
$n = q('SELECT * FROM form144_notices')[0] ?? [];
ok(($n['seller_name'] ?? '') !== '' && (float) $n['shares_to_sell'] === 100000.0 && (float) $n['aggregate_market_value'] === 410000.0 && $n['approx_sale_date'] === '2024-04-05' && $n['broker'] === 'SMOKE BROKER LLC', 'the notice: 100,000 shares worth $410,000 about 2024-04-05 through SMOKE BROKER LLC');
$d = json_decode(page($jV, '/ownership/144', $json)['body'], true)['data']['notices'] ?? [];
ok(count($d) === 1 && $d[0]['filing_id'] === filing_id('0000099990-24-000013') && str_contains(page($jV, '/ownership/144')['body'], 'id="form144-table"'), 'the 144 screen lists it with its filing');
finish();
