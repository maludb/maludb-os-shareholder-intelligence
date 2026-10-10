<?php
/**
 * Proof: the merge (holder-identity.md §3): the compare page with the picker (holders of the issuer, the PII roles), the two columns and the
 * survivor's radio; the merge re-points accounts, positions and rows and tombstones the loser with merged_into_id; "Merged from …" in the
 * survivor's notes; the loser's URL answers 404 with the "merged into" link; a person is never merged into an entity (the database's sentence);
 * an Analyst may not merge (403); an agent's merge pauses (the registry: approval deletion); delete and restore; JSON.
 */
require __DIR__ . '/lib.php';
[$jP, ] = sign_on(26);
[$jM, ] = sign_on(27);
[$jV, ] = sign_on(29);
$fx = fixture_issuer();
$sec = $fx['security'];
foreach ([$jP, $jM, $jV] as $j) { page($j, '/?issuer=' . $fx['issuer']); }
$json = ['headers' => ['Accept: application/json']];
$csv = "JOB NUMBER,CUSIP,ISSUER NAME,RECORD DATE,SORT:(D)ESCENDING (Z)IP CODE,NUMBER OF NOBOS\nO05555,07831X206,SMOKE ISSUER INC,050124,D,0000003\n\n"
     . "SHARES,1ST LINE OF NAME & ADDRESS,2ND LINE OF NAME & ADDRESS,3RD LINE OF NAME & ADDRESS,4TH LINE OF NAME & ADDRESS,5TH LINE OF NAME & ADDRESS,6TH LINE OF NAME & ADDRESS,7TH LINE OF NAME & ADDRESS,ZIP CODE FOR SORTING,CUSIP\n\n"
     . "000001000.00000,SMOKE EVE PERSON,1 ELM ST,RENO NV 89501,,,,,89501,07831X206\n000002000.00000,SMOKE EVELYN PERSON,CHARLES SCHWAB & CO INC CUST,IRA CONTRIBUTORY,9 FAR RD,BOISE ID 83702,,,83702,07831X206\n000003000.00000,SMOKE FERN HOLDINGS LLC,2 OAK AVE,AUSTIN TX 78701,,,,,78701,07831X206\n\n"
     . "# OF NOBOS,SHARES OF NOBOS\n0000003,000000006000.00000\n";
$r = upload($jP, ['name' => 'NOBO.07831X.240501.csv', 'bytes' => $csv], ['security' => $sec, 'kind' => '']);
$u = (int) ($r['json']['record_id'] ?? 0);
$eve = (int) one("SELECT holder_id FROM nobo_rows WHERE import_id = :i AND line1 = 'SMOKE EVE PERSON'", ['i' => $u]);
$evelyn = (int) one("SELECT holder_id FROM nobo_rows WHERE import_id = :i AND line1 = 'SMOKE EVELYN PERSON'", ['i' => $u]);
$fern = (int) one("SELECT holder_id FROM nobo_rows WHERE import_id = :i AND line1 = 'SMOKE FERN HOLDINGS LLC'", ['i' => $u]);
ok($eve > 0 && $evelyn > 0 && $fern > 0 && $eve !== $evelyn && one('SELECT kind FROM holders WHERE id = :h', ['h' => $fern]) === 'entity', 'three holders: Eve, Evelyn (a Schwab IRA), and an entity');

echo "1. The compare page\n";
$h = page($jM, '/holders/' . $eve . '/merge')['body'];
ok(str_contains($h, 'id="merge-into-picker"') && str_contains($h, 'data-picker-source="holder"') && !str_contains($h, 'id="merge-compare"'), 'the page opens with the picker of holders, nothing to compare yet');
$pick = page($jM, '/pick/?source=holder&q=SMOKE&issuer=' . $fx['issuer'], ['headers' => ['HX-Request: true']]);
ok($pick['code'] === 200 && str_contains($pick['body'], 'Smoke Evelyn Person'), 'the picker finds the holders of the issuer');
$pv = page($jV, '/pick/?source=holder&q=SMOKE', ['headers' => ['HX-Request: true']]);
ok($pv['code'] === 403 && str_contains($pv['body'], 'Holders are an Analyst'), 'a Viewer\'s picker: "Holders are an Analyst\'s." (403)');
$h = page($jM, '/holders/' . $eve . '/merge?into=' . $evelyn)['body'];
ok(str_contains($h, 'id="merge-side-a"') && str_contains($h, 'id="merge-side-b"') && str_contains($h, 'id="merge-survivor-a"') && str_contains($h, 'CHARLES SCHWAB'), 'both columns, their accounts (the IRA\'s registration), the survivor\'s radio');
ok(page($jP, '/holders/' . $eve . '/merge?into=' . $evelyn)['code'] === 403, 'an Analyst may not open it (holders.merge): 403');

echo "2. The merge\n";
ok(post_json($jM, '/holders/merge.php', ['survivor' => $eve, 'loser' => $fern])['code'] === 422 && str_contains(post_json($jM, '/holders/merge.php', ['survivor' => $eve, 'loser' => $fern])['body'], 'A person is not merged with an entity'), 'a person into an entity: 422, the database\'s sentence');
ok(post_json($jP, '/holders/merge.php', ['survivor' => $eve, 'loser' => $evelyn])['code'] === 403, 'an Analyst may not merge: 403');
$accounts = (int) one('SELECT count(*) FROM holder_accounts WHERE holder_id IN (:a, :b)', ['a' => $eve, 'b' => $evelyn]);
$since = last_activity_id();
$r = post_json($jM, '/holders/merge.php', ['survivor' => $eve, 'loser' => $evelyn]);
ok($r['code'] === 200 && $r['json']['location'] === '/holders/' . $eve . '?notice=merged' && (int) one('SELECT merged_into_id FROM holders WHERE id = :h', ['h' => $evelyn]) === $eve, 'merged: the loser tombstoned with merged_into_id; the manager lands on the survivor');
ok((int) one('SELECT count(*) FROM holder_accounts WHERE holder_id = :a', ['a' => $eve]) === $accounts && (int) one('SELECT count(*) FROM nobo_rows WHERE holder_id = :b', ['b' => $evelyn]) === 0
    && (float) one("SELECT sum(shares) FROM holder_positions WHERE holder_id = :a AND record_date = '2024-05-01'", ['a' => $eve]) === 3000.0, 'every account, row and position re-pointed (3,000 shares now Eve\'s)');
ok(str_contains((string) one('SELECT notes FROM holders WHERE id = :a', ['a' => $eve]), 'Merged from holder ' . $evelyn), '"Merged from holder …" in the survivor\'s notes');
$l = json_decode((string) one("SELECT after FROM activity_log WHERE id > :s AND action = 'holder.merge'", ['s' => $since]), true);
ok(($l['survivor'] ?? 0) === $eve && ($l['loser'] ?? 0) === $evelyn && count($l) === 2, 'holder.merge logged with the two ids, nothing else');
$g = page($jP, '/holders/' . $evelyn);
ok($g['code'] === 404 && str_contains($g['body'], 'id="holder-merged-link"') && str_contains($g['body'], '/holders/' . $eve), 'the loser\'s page: 404 with the "merged into" link');
$gj = page($jP, '/holders/' . $evelyn, $json);
ok($gj['code'] === 404 && (int) (json_decode($gj['body'], true)['error']['merged_into'] ?? 0) === $eve, '…and in JSON, merged_into');
$ap = array_column(json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json'), true)['approvals'] ?? [], 'category', 'action');
ok(($ap['holders_merge'] ?? '') === 'deletion' && ($ap['holder_delete'] ?? '') === 'deletion', 'an agent\'s holders_merge and holder_delete pause: approvals[] carries both (deletion)');

echo "3. Delete and restore\n";
ok(post_json($jP, '/holders/delete.php', ['holder' => $fern])['code'] === 403, 'an Analyst may not delete a holder: 403');
ok(post_json($jM, '/holders/delete.php', ['holder' => $fern])['code'] === 200 && page($jP, '/holders/' . $fern)['code'] === 404, 'deleted: its page is a 404');
ok(post_json($jM, '/holders/restore.php', ['holder' => $fern])['code'] === 200 && page($jP, '/holders/' . $fern)['code'] === 200, 'restored');
ok(post_json($jM, '/holders/restore.php', ['holder' => $evelyn])['code'] === 422, 'a merged holder is not restored: 422');

echo "4. The edit\n";
$since = last_activity_id();
$r = post_json($jP, '/holders/save.php', ['holder' => $eve, 'city' => 'SPARKS', 'notes' => 'SMOKE: moved']);
$l = json_decode((string) one("SELECT after FROM activity_log WHERE id > :s AND action = 'holder.update'", ['s' => $since]), true);
ok($r['code'] === 200 && one('SELECT city FROM holders WHERE id = :h', ['h' => $eve]) === 'SPARKS' && ($l['fields_changed'] ?? []) === ['city', 'notes'] && !str_contains(json_encode($l), 'SPARKS'), 'holder_update: the city and notes changed; the log names the fields, never the values');
ok(post_json($jP, '/holders/save.php', ['holder' => $eve, 'kind' => 'robot'])['code'] === 422, 'a kind that is not one: 422');
ok(page($jV, '/holders/' . $eve . '/edit')['code'] === 403 && post_json($jV, '/holders/save.php', ['holder' => $eve, 'city' => 'X'])['code'] === 403, 'a Viewer may not edit: 403');
finish();
