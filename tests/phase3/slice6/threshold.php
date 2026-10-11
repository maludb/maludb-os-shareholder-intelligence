<?php
/**
 * Proof: the Reg SHO threshold lists (short-side.md "Proof") — Nasdaq's file a market day (ten days read, the security on six since Mar 27, the
 * status the Phase 0 proof wrote), every list day recorded with its entries; the DEFECT of db/011 fixed by db/023: a day off the list breaks the
 * run although no row marks the day (the old count would read five days); `threshold.list|delist`; NYSE's three markets (an empty market's
 * list a real day) and FINRA's OTC list; a second pull asking nothing; the runs.
 */
require __DIR__ . '/lib.php';
$sm = seed_short();
$sec = $sm['security'];

echo "1. Nasdaq's list\n";
stub_reset();
pull6('threshold_nasdaq');
$p = last_pull('threshold_nasdaq');
$asked = array_values(array_filter(array_column(stub_requests(), 'path'), static fn ($x) => str_contains($x, 'nasdaqth')));
ok($p['status'] === 'ok' && count($asked) === 10 && str_ends_with($asked[0], '20240321.txt') && str_ends_with($asked[9], '20240404.txt'), "ok: ten market days asked, Mar 21 to Apr 4 (Good Friday skipped) ({$p['status']}, " . count($asked) . ')');
ok((int) one("SELECT count(*) FROM threshold_list_days WHERE list = 'nasdaq'") === 10 && (int) one("SELECT min(entries) FROM threshold_list_days WHERE list = 'nasdaq'") >= 1, 'ten list days recorded with their entries (another security every day)');
ok((int) one('SELECT count(*) FROM threshold_listings WHERE security_id = :s', ['s' => $sec]) === 6, 'six days with SMOK on the list');
$st = q('SELECT * FROM si_threshold_status(:s)', ['s' => $sec])[0];
ok($st['on_list'] === true && $st['list'] === 'nasdaq' && (int) $st['consecutive_days'] === 6 && $st['since'] === '2024-03-27' && $st['latest_list_date'] === '2024-04-04', 'on the Nasdaq list six consecutive list days since Mar 27 (Phase 0\'s figures)');
$li = activity('threshold.list');
ok(count($li) === 1 && json_decode($li[0]['after'], true) == ['security_id' => $sec, 'list' => 'nasdaq', 'list_date' => '2024-03-27'] && activity('threshold.delist') === [], 'threshold.list once (Mar 27), no delisting');

echo "2. A day off the list breaks the run (db/023)\n";
q("DELETE FROM threshold_listings WHERE security_id = :s AND list_date = '2024-04-01'", ['s' => $sec]);
$st = q('SELECT * FROM si_threshold_status(:s)', ['s' => $sec])[0];
ok((int) $st['consecutive_days'] === 3 && $st['since'] === '2024-04-02', 'with Apr 1 off the list the run is three days since Apr 2 (the list day of Apr 1 was read)');
$old = (int) one("WITH cal AS (SELECT DISTINCT list_date FROM threshold_listings), calr AS (SELECT list_date, row_number() OVER (ORDER BY list_date) rn FROM cal),
    mine AS (SELECT t.list_date, c.rn - row_number() OVER (ORDER BY t.list_date) g FROM threshold_listings t JOIN calr c USING (list_date) WHERE t.security_id = :s)
    SELECT count(*) FROM mine WHERE g = (SELECT g FROM mine ORDER BY list_date DESC LIMIT 1)", ['s' => $sec]);
ok($old === 5, 'db/011\'s count, on the dates that have a row, would have read five days');
$runs = q('SELECT * FROM si_threshold_runs(:s)', ['s' => $sec]);
ok(count($runs) === 2 && $runs[0]['from_date'] === '2024-04-02' && (int) $runs[0]['list_days'] === 3 && $runs[0]['current'] === true && $runs[1]['from_date'] === '2024-03-27' && (int) $runs[1]['list_days'] === 2 && $runs[1]['current'] === false,
   'the runs: Apr 2–4 three days (now), Mar 27–28 two days');

echo "3. A re-read day: the listing restored, a removed one deleted\n";
source_settings('threshold_nasdaq', ['done' => ['nasdaq' => '2024-03-29']]);
stub_reset();
pull6('threshold_nasdaq');
ok((int) one('SELECT count(*) FROM threshold_listings WHERE security_id = :s', ['s' => $sec]) === 6 && (int) q('SELECT * FROM si_threshold_status(:s)', ['s' => $sec])[0]['consecutive_days'] === 6, 'Apr 1 read again: SMOK is on it, the run six days again');

echo "4. NYSE's three markets and FINRA's OTC list\n";
stub_reset();
pull6('threshold_nyse');
$p = last_pull('threshold_nyse');
ok($p['status'] === 'ok' && (int) one("SELECT count(*) FROM threshold_list_days WHERE list = 'nyse'") === 10 && (int) one("SELECT count(*) FROM threshold_list_days WHERE list = 'nyse_american' AND entries = 0") === 10
   && (int) one("SELECT count(*) FROM threshold_list_days WHERE list = 'nyse_arca'") === 10, 'NYSE: ten days of each market — NYSE American\'s an empty list, a real day');
ok(count(array_filter(stub_requests(), static fn ($r) => str_contains($r['path'], 'threshold-securities'))) === 30, 'thirty requests: three markets, ten days');
stub_reset();
pull6('threshold_otc');
ok(last_pull('threshold_otc')['status'] === 'ok' && (int) one("SELECT count(*) FROM threshold_list_days WHERE list = 'otc'") === 10 && (int) one("SELECT count(*) FROM threshold_listings WHERE list = 'otc'") === 0, 'FINRA\'s OTC list: ten days read, nothing of ours on it');
ok(count(array_filter(stub_requests(), static fn ($r) => str_contains($r['path'], 'thresholdList'))) === 10, 'one POST a trade date');

echo "5. Nothing again\n";
stub_reset();
pull6('threshold_nasdaq');
ok(array_filter(array_column(stub_requests(), 'path'), static fn ($x) => str_contains($x, 'nasdaqth')) === [] && last_pull('threshold_nasdaq')['status'] === 'ok', 'a second pull the same evening asks for no list');
[$jV, ] = sign_on(29);
page($jV, '/?issuer=' . $sm['issuer']);
$h = page($jV, '/short/threshold?security=' . $sec)['body'];
ok(str_contains($h, 'id="threshold-runs"') && str_contains($h, 'On the Nasdaq list — 6 list days since Apr 4, 2024') === false && preg_match('~On the Nasdaq list — 6 list days since Mar 27, 2024~', $h) === 1, 'a Viewer reads the status: on the Nasdaq list, six list days since Mar 27');
finish();
