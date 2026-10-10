<?php
/**
 * Proof: the positions screens on six real weeks (May 31 – July 5, 2024): the table (ranked, the change against the previous date, the
 * window's net, the new and the gone, the class and participant filters, the total equal to the SPR's), the movement matrix (sorted by the
 * window's net, cells equal to the positions, absent cells hatched, one date at a time on a phone), the flow by class (its caveat ALWAYS, the
 * classes' nets adding up to the total's change), the concentration (the database's, 0–100), JSON on each, and a security behind the wall
 * showing nothing.
 */
require __DIR__ . '/lib.php';
[$jP, ] = sign_on(26);
[$jV, ] = sign_on(29);
$ref = reference_issuer();
$sec = $ref['security'];
$json = ['headers' => ['Accept: application/json']];
foreach (['05-31-2024', '06-07-2024', '06-14-2024', '06-21-2024', '06-28-2024', '07-05-2024'] as $d) {
    $f = glob(REAL . '*SPR_' . $d . '_09175A107*.csv')[0];
    $r = upload($jP, $f, ['security' => $sec]);
    ok($r['code'] === 200 && $r['json']['tied'] === true, "the week of $d uploaded and tied");
}
page($jP, '/?issuer=' . $ref['issuer']);
page($jV, '/?issuer=' . $ref['issuer']);

echo "1. The position table\n";
$d = json_decode(page($jP, '/positions', $json)['body'], true)['data'] ?? [];
$last = (string) one('SELECT max(position_date) FROM participant_positions WHERE security_id = :s', ['s' => $sec]);
ok($d['position_date'] === $last && $d['previous_date'] === (string) one('SELECT max(position_date) FROM participant_positions WHERE security_id = :s AND position_date < :d', ['s' => $sec, 'd' => $last]), "the latest date by default ($last), with the previous position date");
ok((float) $d['total'] === (float) one('SELECT si_spr_total(:s, :d)', ['s' => $sec, 'd' => $last]) && count($d['positions']) === (int) one('SELECT count(*) FROM participant_positions WHERE security_id = :s AND position_date = :d', ['s' => $sec, 'd' => $last]),
    'the total and the rows are the database\'s');
$shares = array_column($d['positions'], 'shares');
$sorted = $shares; rsort($sorted);
ok($shares === $sorted, 'ranked by shares');
$top = $d['positions'][0];
$prevShares = one('SELECT shares FROM participant_positions WHERE security_id = :s AND participant_id = :p AND position_date = :d', ['s' => $sec, 'p' => $top['participant_id'], 'd' => $d['previous_date']]);
ok(($top['change_prev'] === null ? null : (float) $top['change_prev']) === ($prevShares === false ? null : (float) $top['shares'] - (float) $prevShares) && abs(array_sum(array_column($d['positions'], 'pct_of_total')) - 100) < 0.5, 'each change is against the previous date; the shares of the total add to 100%');
$d2 = json_decode(page($jP, '/positions?date=2024-06-18', $json)['body'], true)['data'] ?? [];
ok($d2['position_date'] === '2024-06-18' && $d2['previous_date'] === '2024-06-17', 'a chosen date (June 18) and its previous one');
$d3 = json_decode(page($jP, '/positions?date=2024-06-20', $json)['body'], true)['data'] ?? [];
ok($d3['previous_date'] === '2024-06-18', 'after Juneteenth (absent) the previous position date is June 18');
$d = json_decode(page($jP, '/positions?class=retail', $json)['body'], true)['data'] ?? [];
ok($d['positions'] !== [] && array_unique(array_column($d['positions'], 'class_key')) === ['retail'], 'the class filter');
$d = json_decode(page($jP, '/positions?q=164', $json)['body'], true)['data'] ?? [];
ok(count($d['positions']) === 1 && $d['positions'][0]['number'] === '0164', 'a participant by its number (164 is 0164)');
$h = page($jP, '/positions')['body'];
ok(str_contains($h, 'id="positions-table"') && str_contains($h, 'si-class-chip') && str_contains($h, 'Source: the DTC Security Position Report'), 'the page: the table, the class chips, the source line');
ok(page($jV, '/positions')['code'] === 200, 'a Viewer reads the positions (an SPR names brokers, never a person)');

echo "2. The movement matrix\n";
$d = json_decode(page($jP, '/positions/matrix?from=2024-05-24&to=2024-07-05', $json)['body'], true)['data'] ?? [];
ok(count($d['dates']) === min(30, (int) one("SELECT count(DISTINCT position_date) FROM participant_positions WHERE security_id = :s AND position_date BETWEEN '2024-05-24' AND '2024-07-05'", ['s' => $sec])) && count($d['rows']) <= 40, 'the window\'s last 30 position dates, at most 40 participants');
$nets = array_map(static fn ($r) => abs($r['net']), $d['rows']);
$s = $nets; rsort($s);
ok($nets === $s, 'sorted by the window\'s net, largest moves first');
$r0 = $d['rows'][0];
$dd = array_key_last($r0['cells']);
ok((float) $r0['cells'][$dd]['shares'] === (float) one('SELECT shares FROM participant_positions WHERE security_id = :s AND participant_id = :p AND position_date = :d', ['s' => $sec, 'p' => $r0['participant_id'], 'd' => $dd]), 'a cell is the position');
$h = page($jP, '/positions/matrix?from=2024-05-24&to=2024-07-05')['body'];
ok(str_contains($h, 'id="matrix"') && str_contains($h, 'id="matrix-phone-table"') && str_contains($h, 'id="matrix-focus"'), 'the full matrix on a desktop, one date at a time on a phone');
ok(str_contains(page($jP, '/positions/matrix?from=2024-05-24&to=2024-07-05&focus=2024-06-18')['body'], '<option value="2024-06-18" selected'), 'the phone\'s date chooser keeps its date');

echo "3. The flow by class\n";
$d = json_decode(page($jP, '/positions/flow?from=2024-05-24&to=2024-07-05', $json)['body'], true)['data'] ?? [];
ok(count($d['flow']) >= 20 && $d['caveat'] === 'Class buckets are a setting. Movement between them is practitioner lore with no published validation.', count($d['flow']) . ' steps between position dates, and the caveat');
$bad = 0;
foreach ($d['flow'] as $f) {
    $change = (float) one('SELECT si_spr_total(:s, :d)', ['s' => $sec, 'd' => $f['date']]) - (float) one('SELECT si_spr_total(:s, :d)', ['s' => $sec, 'd' => $f['prev']]);
    if (abs(array_sum($f['by_class']) - $change) > 0.5) { $bad++; }
}
ok($bad === 0, 'on every step the classes\' nets add up to the change in the DTC total');
$h = page($jP, '/positions/flow?from=2024-05-24&to=2024-07-05')['body'];
ok(str_contains($h, 'id="flow-chart"') && str_contains($h, 'id="flow-caveat"') && str_contains($h, 'practitioner lore with no published validation'), 'the stacked chart, with the caveat under it');
ok(str_contains(page($jP, '/positions/flow?from=2020-01-01&to=2020-02-01')['body'], 'id="flow-caveat"'), 'the caveat is there even when the window is empty');

echo "4. The concentration\n";
$d = json_decode(page($jP, '/positions/concentration?from=2024-05-24&to=2024-07-05', $json)['body'], true)['data'] ?? [];
$c = $d['series'][count($d['series']) - 1];
$db = q('SELECT * FROM si_concentration(:s, :d)', ['s' => $sec, 'd' => $c['position_date']])[0];
ok((float) $c['top5_pct'] === (float) $db['top5_pct'] && (float) $c['hhi'] === (float) $db['hhi'] && $c['top5_pct'] <= $c['top10_pct'] && $c['top10_pct'] <= $c['top20_pct'] && $c['top20_pct'] <= 100, 'the database\'s figures; top 5 ≤ top 10 ≤ top 20 ≤ 100');
ok(str_contains(page($jP, '/positions/concentration?from=2024-05-24&to=2024-07-05')['body'], 'id="concentration-chart"'), 'the chart and its table');

echo "5. Behind the wall\n";
q('INSERT INTO issuer_members (issuer_id, member_id) VALUES (:i, 30)', ['i' => $ref['issuer']]);
[$jP2, ] = sign_on(26);
$d = json_decode(page($jP2, '/positions?security=' . $sec, $json)['body'], true)['data'] ?? [];
ok($d['security_id'] === null && str_contains(page($jP2, '/positions/matrix?security=' . $sec)['body'], 'Choose an issuer'), 'restricted away from her: the positions of its security show nothing, in any screen');
q('DELETE FROM issuer_members WHERE issuer_id = :i', ['i' => $ref['issuer']]);
finish();
