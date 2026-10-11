<?php
/**
 * Proof: FINRA (short-side.md "Proof"; edgar-and-finra-fetchers.md §5) — the short interest from the Query API's consolidatedShortInterest (the
 * series the Phase 0 proof wrote, the share of the float and of outstanding, a second pull changing nothing, a revision appending a row the
 * series and the chip show, `short.load|revise` with ids and figures) and the daily short volume (sixty days of the CNMS file, the 5-day mean
 * and FINRA's caveat, two absent days not a failure, the next pull asking only after the last day read, `volume.load`).
 */
require __DIR__ . '/lib.php';
$sm = seed_short();
$sec = $sm['security'];

echo "1. Short interest: the Query API's dataset, the tracked ticker only\n";
stub_reset();
$w = pull6('finra_short_interest');
$p = last_pull('finra_short_interest');
ok($p['status'] === 'ok' && (int) $p['listings_new'] === 5, "the first pull: ok, five settlement dates new ({$p['status']}, {$p['listings_new']})");
$reqs = array_values(array_filter(stub_requests(), static fn ($r) => str_contains($r['path'], 'consolidatedShortInterest')));
ok(count($reqs) === 1 && str_contains($reqs[0]['ua'], 'Kinetic Seas'), 'one POST to consolidatedShortInterest, under the business\'s user-agent');
$s = q("SELECT * FROM si_short_interest_series(:s, '2024-01-01', '2024-04-30') ORDER BY settlement_date", ['s' => $sec]);
ok(count($s) === 5 && (float) $s[4]['short_shares'] === 520000.0 && (float) $s[4]['days_to_cover'] === 11.06 && (float) $s[0]['short_shares'] === 120000.0, 'the series: five dates, 120,000 on Jan 31 to 520,000 on Mar 28 (11.06 days to cover)');
ok((float) $s[4]['pct_of_float'] === 3.47 && (float) $s[4]['pct_of_outstanding'] === 3.05, 'as a share of the 15,000,000 float 3.47%, of the 17,053,900 outstanding 3.05%');
$row = q("SELECT market, revision_flag, previous_short_shares, change_shares FROM short_interest WHERE security_id = :s AND settlement_date = '2024-03-28'", ['s' => $sec])[0];
ok($row['market'] === 'OTC' && $row['revision_flag'] === false && (float) $row['previous_short_shares'] === 410000.0 && (float) $row['change_shares'] === 110000.0, 'FINRA\'s fields kept by name: the market, the previous figure, the change');
$l = activity('short.load');
ok(count($l) === 5 && json_decode($l[0]['after'], true)['security_id'] === $sec && !array_diff(array_keys(json_decode($l[0]['after'], true)), ['security_id', 'settlement_date', 'short_shares']), 'short.load per date, with the security, the date and the shares only');

echo "2. The same figures again change nothing\n";
pull6('finra_short_interest');
$p = last_pull('finra_short_interest');
ok($p['status'] === 'ok' && (int) $p['listings_new'] === 0 && (int) $p['listings_changed'] === 0 && (int) one('SELECT count(*) FROM short_interest') === 5, 'a second pull: nothing new, nothing changed, five rows');

echo "3. A revision is a new row\n";
$since = last_activity_id();
stub_reset(['variant' => ['si_revised']]);
pull6('finra_short_interest');
$p = last_pull('finra_short_interest');
ok((int) $p['listings_changed'] === 1 && (int) one("SELECT count(*) FROM short_interest WHERE settlement_date = '2024-03-28'") === 2 && (int) one('SELECT count(*) FROM short_interest') === 6, 'FINRA revised Mar 28: one changed, a second observation of the date, six rows');
$s = q("SELECT short_shares, days_to_cover, revision_flag FROM si_short_interest_series(:s, '2024-03-28', '2024-03-28')", ['s' => $sec])[0];
ok((float) $s['short_shares'] === 530000.0 && (float) $s['days_to_cover'] === 11.28 && $s['revision_flag'] === true, 'the series shows the latest observation: 530,000, 11.28 days, flagged');
$r = activity('short.revise', $since);
ok(count($r) === 1 && (float) json_decode($r[0]['after'], true)['before_short_shares'] === 520000.0, 'short.revise logged with the earlier figure');
[$jP, ] = sign_on(26);
page($jP, '/?issuer=' . $sm['issuer']);
$h = page($jP, '/short/interest?security=' . $sec . '&from=2024-01-01&to=2024-04-30')['body'];
ok(str_contains($h, 'id="si-table"') && preg_match('~id="revision-chip-2024-03-28"[^>]*title="Revised: FINRA first said 520,000 shares \(11\.06 days to cover\)~', $h) === 1 && str_contains($h, '530,000'), 'the table shows 530,000 with the chip "revised" and the earlier 520,000 on hover');
ok(substr_count($h, 'class="badge text-bg-warning ms-1"') === 1, 'one chip: only the revised date carries it');

echo "4. Daily short volume: the CNMS file a day\n";
stub_reset();
source_settings('finra_daily_short_volume', ['backfill_days' => 62]);
pull6('finra_daily_short_volume');
$p = last_pull('finra_daily_short_volume');
$det = stub_requests();
$cnms = array_values(array_filter($det, static fn ($x) => str_contains($x['path'], 'CNMSshvol')));
$absent = array_values(array_filter($cnms, static fn ($x) => $x['status'] === 404));
ok($p['status'] === 'ok' && (int) one("SELECT count(*) FROM daily_short_volume WHERE security_id = :s AND market = 'CNMS'", ['s' => $sec]) === 60, "sixty days of CNMS rows ({$p['status']})");
ok(count($cnms) === 62 && count($absent) === 2, 'sixty-two market days asked: the two the file does not have (404) are not a failure');
ok((int) one("SELECT count(*) FROM daily_short_volume WHERE market = 'FORF'") === 0 && count(array_filter($det, static fn ($x) => str_contains($x['path'], 'FORFshvol'))) === 62, 'the FORF file read too, nothing of ours in it');
$v = q("SELECT * FROM si_daily_short_volume_series(:s, 'CNMS', '2024-04-04', '2024-04-04') WHERE trade_date = '2024-04-04'", ['s' => $sec])[0] ?? [];
ok(($v['mean_5'] ?? 0) > 0.6 && str_contains((string) ($v['caveat'] ?? ''), 'Not comparable with short interest'), 'the 5-day mean on Apr 4 is over 0.6, and FINRA\'s caveat travels with the row');
$first = q("SELECT short_volume, total_volume, ratio FROM daily_short_volume WHERE security_id = :s AND trade_date = '2024-01-11'", ['s' => $sec])[0];
ok((float) $first['short_volume'] === 14000.0 && (float) $first['total_volume'] === 40000.0 && (float) $first['ratio'] === 0.35, 'Jan 11: 14,000 short of 40,000 — the ratio 0.35, the table\'s own column');
$vl = activity('volume.load');
ok(count($vl) === 1 && (int) json_decode($vl[0]['after'], true)['days'] === 60, 'volume.load once for the security: sixty days');
$set = json_decode((string) one("SELECT settings FROM sources WHERE connector = 'finra_daily_short_volume'"), true);
ok(($set['done']['CNMS'] ?? '') === '2024-04-04' && in_array($sec, $set['backfilled'] ?? [], true), 'the source remembers the last file read (Apr 4) and that the security is back-filled');

echo "5. The next pull asks only after the last file read\n";
stub_reset();
pull6('finra_daily_short_volume', '2024-04-05T19:00:00-04:00');
$asked = array_values(array_filter(array_column(stub_requests(), 'path'), static fn ($x) => str_contains($x, 'shvol')));
ok(count($asked) === 2 && str_ends_with($asked[0], '20240405.txt') && (int) one('SELECT count(*) FROM daily_short_volume') === 60, 'Friday evening: one file a market asked (Apr 5), absent (not published) — nothing changed, no failure');
ok(last_pull('finra_daily_short_volume')['status'] === 'ok', '…and the pull is ok');
finish();
