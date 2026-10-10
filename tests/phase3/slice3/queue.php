<?php
/**
 * Proof: the queue (holder-identity.md §2): the crafted list's namesake waits with its candidate, the score as a bar and the evidence in words;
 * Accept joins the row to the candidate (its other proposals closed) and the queue advances; Reject is REMEMBERED — matching the upload again
 * never proposes the pair again; New holder makes a second holder apart; an agent's proposal (holder_match_propose) carries its sentence and the
 * "Analyst's proposal" chip; the kind proposed by an agent, set by a person; a Viewer gets 403 on the queue and on every decision; an agent may
 * not decide (people only) but may propose; JSON mode; matcher_run on a usable upload.
 */
require __DIR__ . '/lib.php';
[$jP, ] = sign_on(26);
[$jV, ] = sign_on(29);
$fx = fixture_issuer();
$sec = $fx['security'];
foreach ([$jP, $jV] as $j) { page($j, '/?issuer=' . $fx['issuer']); }
$json = ['headers' => ['Accept: application/json']];
$line = static fn (string $sh, array $l, string $zip): string => sprintf('%015.5f', (float) $sh) . ',' . implode(',', array_pad($l, 7, '')) . ',' . $zip . ',07831X206';
$list = static function (string $rd, array $rows) use ($line): string {
    $sum = array_sum(array_map(static fn ($r) => (float) $r[0], $rows));
    $o = "JOB NUMBER,CUSIP,ISSUER NAME,RECORD DATE,SORT:(D)ESCENDING (Z)IP CODE,NUMBER OF NOBOS\nO0" . substr($rd, 0, 4) . ",07831X206,SMOKE ISSUER INC,$rd,D," . sprintf('%07d', count($rows)) . "\n\n"
       . "SHARES,1ST LINE OF NAME & ADDRESS,2ND LINE OF NAME & ADDRESS,3RD LINE OF NAME & ADDRESS,4TH LINE OF NAME & ADDRESS,5TH LINE OF NAME & ADDRESS,6TH LINE OF NAME & ADDRESS,7TH LINE OF NAME & ADDRESS,ZIP CODE FOR SORTING,CUSIP\n\n";
    foreach ($rows as $r) { $o .= $line($r[0], $r[1], $r[2]) . "\n"; }
    return $o . "\n# OF NOBOS,SHARES OF NOBOS\n" . sprintf('%07d', count($rows)) . ',' . sprintf('%018.5f', $sum) . "\n";
};
$up = static function (string $name, string $csv) use ($jP, $sec): int {
    $r = upload($jP, ['name' => $name, 'bytes' => $csv], ['security' => $sec, 'kind' => '']);
    return (int) ($r['json']['record_id'] ?? 0);
};
$u1 = $up('NOBO.07831X.240101.csv', $list('010124', [['5000', ['SMOKE ANNA PERSON', '10 ELM ST', 'RENO NV 89501'], '89501'], ['7000', ['SMOKE BORIS PERSON', '20 OAK AVE', 'AUSTIN TX 78701'], '78701'],
    ['900', ['SMOKE CARLA PERSON', '30 PINE RD', 'BOISE ID 83702'], '83702'], ['100', ['SMOKE DUNE HOLDINGS LLC', '40 ASH CT', 'TAMPA FL 33601'], '33601']]));
$u2 = $up('NOBO.07831X.240201.csv', $list('020124', [['5100', ['SMOKE ANNA PERSON', '99 OTHER ST', 'RENO NV 89502'], '89502'], ['7000', ['SMOKE BORIS PERSONN', '20 OAK AVE', 'AUSTIN TX 78701'], '78701'],
    ['950', ['SMOKE CARLA PERSON', '1 FAR WAY', 'MIAMI FL 33101'], '33101'], ['100', ['SMOKE DUNE HOLDINGS LLC', '40 ASH CT', 'TAMPA FL 33601'], '33601']]));
$m2 = json_decode((string) one("SELECT derived_totals -> 'matcher' FROM imports WHERE id = :i", ['i' => $u2]), true) ?: [];
ok($u1 > 0 && $u2 > 0 && ($m2['auto'] ?? 0) === 2 && ($m2['proposed'] ?? 0) === 2, 'two lists: the second\'s typo at the same address and the entity automatic; Anna and Carla at other addresses proposed');
$annaRow = nobo_row($u2, (int) one("SELECT row_no FROM nobo_rows WHERE import_id = :i AND line1 = 'SMOKE ANNA PERSON'", ['i' => $u2]));
$carlaRow = nobo_row($u2, (int) one("SELECT row_no FROM nobo_rows WHERE import_id = :i AND line1 = 'SMOKE CARLA PERSON'", ['i' => $u2]));
$anna = (int) one("SELECT holder_id FROM nobo_rows WHERE import_id = :i AND line1 = 'SMOKE ANNA PERSON'", ['i' => $u1]);

echo "1. The queue\n";
$d = json_decode(page($jP, '/holders/unmatched', $json)['body'], true)['data'] ?? [];
ok(($d['total'] ?? 0) === 2 && count($d['rows'][0]['candidates']) === 1 && str_contains($d['rows'][0]['candidates'][0]['evidence'], 'the same name'), 'two rows wait, each with its candidate and the evidence in words ("another address; the same name")');
$h = page($jP, '/holders/unmatched')['body'];
ok(str_contains($h, 'id="queue-row-nobo-' . $annaRow . '"') && str_contains($h, 'role="progressbar"') && str_contains($h, 'data-key="a"') && str_contains($h, 'id="queue-new-nobo-'), 'the page: the registration beside the candidate, the score bar, the keys, New holder');
ok(page($jV, '/holders/unmatched')['code'] === 403 && page($jV, '/holders/unmatched', $json)['code'] === 403, 'a Viewer: 403 on the queue');

echo "2. Accept, reject (remembered), new holder\n";
$pa = (int) one("SELECT id FROM holder_match_proposals WHERE row_kind = 'nobo' AND row_id = :r AND status = 'proposed'", ['r' => $annaRow]);
$since = last_activity_id();
$r = post_json($jP, '/holders/matches/accept.php', ['proposal' => $pa, 'return_to' => '/holders/unmatched']);
ok($r['code'] === 200 && (int) one('SELECT holder_id FROM nobo_rows WHERE id = :r', ['r' => $annaRow]) === $anna && $r['json']['location'] === '/holders/unmatched?notice=accepted', 'Accept: Anna\'s new address joins her holder; back to the queue');
ok((int) one('SELECT count(*) FROM holder_accounts WHERE holder_id = :h', ['h' => $anna]) === 2 && (float) one("SELECT sum(shares) FROM holder_positions WHERE holder_id = :h AND record_date = '2024-02-01'", ['h' => $anna]) === 5100.0, '…a second account, her position on Feb 1');
$l = json_decode((string) one("SELECT after FROM activity_log WHERE id > :s AND action = 'match.accept'", ['s' => $since]), true);
ok(isset($l['row_id'], $l['holder_id'], $l['score']) && !str_contains(json_encode($l), 'ANNA'), 'match.accept logged with ids and the score, no name');
$pc = (int) one("SELECT id FROM holder_match_proposals WHERE row_kind = 'nobo' AND row_id = :r AND status = 'proposed'", ['r' => $carlaRow]);
ok(post_json($jP, '/holders/matches/reject.php', ['proposal' => $pc])['code'] === 200 && one('SELECT status FROM holder_match_proposals WHERE id = :p', ['p' => $pc]) === 'rejected', 'Reject: the pair is rejected');
q("UPDATE holder_match_proposals SET status = 'proposed', decided_at = NULL WHERE id = -1");
$carla1 = (int) one("SELECT holder_id FROM nobo_rows WHERE import_id = :i AND line1 = 'SMOKE CARLA PERSON'", ['i' => $u1]);
$r = post_json($jP, '/holders/matches/run.php', ['upload' => $u2]);
$carla2 = (int) one('SELECT holder_id FROM nobo_rows WHERE id = :r', ['r' => $carlaRow]);
ok($r['code'] === 200 && (int) one("SELECT count(*) FROM holder_match_proposals WHERE row_kind = 'nobo' AND row_id = :r AND status = 'proposed'", ['r' => $carlaRow]) === 0 && $carla2 > 0 && $carla2 !== $carla1,
    'matcher_run scores the upload again: the rejected pair is NOT proposed again (remembered) — with no other candidate the row is a holder of its own');
ok((int) (json_decode(page($jP, '/holders/unmatched', $json)['body'], true)['data']['total'] ?? -1) === 0, 'the queue is empty');

echo "3. New holder; an agent's proposal; the kind\n";
$u3 = $up('NOBO.07831X.240301.csv', $list('030124', [['7100', ['SMOKE BORIS PERSON', '5 MOVED LN', 'AUSTIN TX 78702'], '78702'], ['960', ['SMOKE CARLA PERSON', '2 THIRD ST', 'OMAHA NE 68102'], '68102']]));
$boris = (int) one("SELECT holder_id FROM nobo_rows WHERE import_id = :i AND line1 = 'SMOKE BORIS PERSON'", ['i' => $u1]);
$borisRow = (int) one("SELECT id FROM nobo_rows WHERE import_id = :i AND line1 = 'SMOKE BORIS PERSON'", ['i' => $u3]);
$carlaRow3 = (int) one("SELECT id FROM nobo_rows WHERE import_id = :i AND line1 = 'SMOKE CARLA PERSON'", ['i' => $u3]);
ok((int) one("SELECT count(*) FROM holder_match_proposals WHERE row_kind = 'nobo' AND row_id = :r AND status = 'proposed'", ['r' => $carlaRow3]) === 2, 'a third Carla address: proposed against both Carlas');
$r = post_json($jP, '/holders/matches/new-holder.php', ['row_kind' => 'nobo', 'row' => $carlaRow3]);
$carla3 = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && $carla3 > 0 && !in_array($carla3, [$carla1, $carla2], true) && (int) one("SELECT count(*) FROM holder_match_proposals WHERE row_kind = 'nobo' AND row_id = :r AND status = 'rejected'", ['r' => $carlaRow3]) === 2,
    'New holder: a third Carla, apart; both proposals rejected and remembered');
ok(post_json($jP, '/holders/matches/new-holder.php', ['row_kind' => 'nobo', 'row' => $carlaRow3])['code'] === 422, 'a row with its holder: 422');
kernel_state(function ($s) { $s['facts']['801'] = ['valid' => true, 'member_id' => 42, 'run_id' => 801, 'request_id' => 'req-801', 'is_eval' => false]; return $s; });
$rt = as_agent(run_token(42, 801));
$r = req('POST', '/holders/matches/propose.php', ['headers' => $rt, 'form' => ['row_kind' => 'nobo', 'row' => $borisRow, 'holder' => $boris, 'evidence' => 'The same name in Austin, one street over, the same shares a month later.']]);
ok($r['code'] === 200 && (int) one("SELECT proposed_by FROM holder_match_proposals WHERE row_kind = 'nobo' AND row_id = :r AND holder_id = :h", ['r' => $borisRow, 'h' => $boris]) === 42, 'the Analyst agent proposes the match with its sentence (holder_match_propose)' . ($r['code'] === 200 ? '' : ' — ' . substr($r['body'], 0, 200)));
$h = page($jP, '/holders/unmatched')['body'];
ok(str_contains($h, 'Analyst\'s proposal') && str_contains($h, 'one street over'), 'the queue shows the "Analyst\'s proposal" chip and its evidence');
$pb = (int) one("SELECT id FROM holder_match_proposals WHERE row_kind = 'nobo' AND row_id = :r AND holder_id = :h", ['r' => $borisRow, 'h' => $boris]);
ok(req('POST', '/holders/matches/accept.php', ['headers' => $rt, 'form' => ['proposal' => $pb]])['code'] === 403, 'the agent may not accept it (people only): 403');
ok(req('POST', '/holders/matches/propose.php', ['headers' => $rt, 'form' => ['row_kind' => 'nobo', 'row' => $carlaRow3, 'holder' => $carla1, 'evidence' => 'x']])['code'] === 422, 'an agent proposing for a row that has its holder: 422');
$r = req('POST', '/holders/kind-propose.php', ['headers' => $rt, 'form' => ['holder' => (int) one("SELECT id FROM holders WHERE name_key = 'SMOKE DUNE HOLDINGS LLC'"), 'kind' => 'entity', 'entity_type' => 'corporation', 'evidence' => 'An LLC.']]);
ok($r['code'] === 200 && (int) one("SELECT count(*) FROM holder_kind_proposals WHERE status = 'proposed'") === 1, 'the agent proposes a kind (holder_kind_propose)');
$dune = (int) one("SELECT id FROM holders WHERE name_key = 'SMOKE DUNE HOLDINGS LLC'");
ok(str_contains(page($jP, '/holders/' . $dune)['body'], 'id="holder-kind-proposal-'), 'the holder\'s page shows the proposal');
ok(post_json($jP, '/holders/kind.php', ['holder' => $dune, 'kind' => 'entity', 'entity_type' => 'corporation'])['code'] === 200 && one('SELECT entity_type FROM holders WHERE id = :h', ['h' => $dune]) === 'corporation'
    && (int) one("SELECT count(*) FROM holder_kind_proposals WHERE status = 'proposed'") === 0, 'a person sets the kind; the proposal closes');
$r = post_json($jP, '/holders/matches/accept.php', ['proposal' => $pb]);
ok($r['code'] === 200 && (int) one('SELECT holder_id FROM nobo_rows WHERE id = :r', ['r' => $borisRow]) === $boris, 'a person accepts the agent\'s proposal');

echo "4. Who may decide\n";
$u4 = $up('NOBO.07831X.240401.csv', $list('040124', [['7200', ['SMOKE BORIS PERSON', '8 LAST RD', 'DALLAS TX 75201'], '75201']]));
$row4 = (int) one('SELECT id FROM nobo_rows WHERE import_id = :i', ['i' => $u4]);
$p3 = (int) one("SELECT id FROM holder_match_proposals WHERE row_kind = 'nobo' AND row_id = :r AND status = 'proposed' LIMIT 1", ['r' => $row4]);
$row3 = $row4;
ok(post_json($jV, '/holders/matches/accept.php', ['proposal' => $p3])['code'] === 403 && post_json($jV, '/holders/matches/reject.php', ['proposal' => $p3])['code'] === 403
    && post_json($jV, '/holders/matches/new-holder.php', ['row_kind' => 'nobo', 'row' => $row3])['code'] === 403 && post_json($jV, '/holders/kind.php', ['holder' => $dune, 'kind' => 'unknown'])['code'] === 403,
    'a Viewer: 403 on accept, reject, new holder and kind');
ok(post_json($jP, '/holders/matches/accept.php', ['proposal' => 999999])['code'] === 422, 'a proposal that is not open: 422');
finish();
